<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use Symfony\Component\HttpFoundation\Request;

class WebhookCaptureService
{
    private const QUEUE_NAME = 'WebhookCapture';

    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly LoggerService $loggerService,
        private readonly ?MercadoLivreService $mercadoLivreService = null,
    ) {
    }

    public function capture(Request $request, string $provider): Integration
    {
        $rawBody = $request->getContent();
        $payload = $this->decodePayload($rawBody);
        $meta = $this->buildMeta($request, $provider, $rawBody, $payload);

        $existingIntegrationId = $this->integrationService->findRecentIntegrationIdByWebhookEvent(
            self::QUEUE_NAME,
            $meta['event_id']
        );

        if ($existingIntegrationId !== null) {
            $this->loggerService->getLogger($provider)->info('Webhook duplicate ignored', [
                'event_id' => $meta['event_id'],
                'existing_integration_id' => $existingIntegrationId,
            ]);

            $existing = new Integration();
            $this->setIntegrationId($existing, $existingIntegrationId);

            return $existing;
        }

        $body = [
            'provider' => $provider,
            'payload' => $payload,
            '__webhook' => $meta,
        ];

        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encodedBody === false) {
            $encodedBody = json_encode([
                'provider' => $provider,
                'payload' => ['raw' => $rawBody],
                '__webhook' => $meta,
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $integration = $this->integrationService->addIntegrationWithHeaders(
            (string) $encodedBody,
            self::QUEUE_NAME,
            ['webhook' => $meta]
        );

        $this->loggerService->getLogger($provider)->info('Webhook captured', [
            'event_id' => $meta['event_id'],
            'event_type' => $meta['event_type'],
            'resource' => $meta['resource'],
            'integration_id' => $integration->getId(),
        ]);

        return $integration;
    }

    public function integrate(Integration $integration): ?\ControleOnline\Entity\Order
    {
        $headers = json_decode((string) $integration->getHeaders(), true);
        $webhook = is_array($headers['webhook'] ?? null) ? $headers['webhook'] : [];
        $provider = trim((string) ($webhook['provider'] ?? 'WebhookCapture'));

        if (strcasecmp($provider, 'MercadoLivre') === 0 && $this->mercadoLivreService instanceof MercadoLivreService) {
            return $this->mercadoLivreService->handleWebhookCapture($integration);
        }

        /* @agents Generic captures are archival only until a provider-specific
         * integration exists; the worker must close the queue without mutating
         * business entities or marking the callback as failed.
         */
        $this->loggerService->getLogger($provider)->info('Webhook capture archived', [
            'integration_id' => $integration->getId(),
            'event_id' => $webhook['event_id'] ?? null,
            'event_type' => $webhook['event_type'] ?? null,
        ]);

        return null;
    }

    private function decodePayload(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['raw' => $rawBody];
        }

        return is_array($payload) ? $payload : ['value' => $payload];
    }

    private function buildMeta(Request $request, string $provider, string $rawBody, array $payload): array
    {
        $externalEventId = trim((string) (
            $request->headers->get('X-GitHub-Delivery')
            ?? $payload['id']
            ?? $payload['event_id']
            ?? $payload['resource']
            ?? ''
        ));
        $eventType = trim((string) (
            $request->headers->get('X-GitHub-Event')
            ?? $payload['topic']
            ?? $payload['event_type']
            ?? $payload['action']
            ?? 'notification'
        ));
        $resource = trim((string) (
            $payload['resource']
            ?? $payload['url']
            ?? $payload['repository']['full_name']
            ?? ''
        ));

        if ($externalEventId === '') {
            $externalEventId = hash('sha256', $provider . '|' . $eventType . '|' . $resource . '|' . $rawBody);
        }

        return [
            'provider' => $provider,
            'event_id' => strtolower($provider) . ':' . $externalEventId,
            'external_event_id' => $externalEventId,
            'event_type' => $eventType,
            'resource' => $resource !== '' ? $resource : null,
            'received_at' => date('Y-m-d H:i:s'),
            'signature_present' => $this->hasSignatureHeader($request),
        ];
    }

    private function hasSignatureHeader(Request $request): bool
    {
        foreach (['X-Hub-Signature-256', 'X-Hub-Signature', 'X-Meli-Signature'] as $header) {
            if (trim((string) $request->headers->get($header, '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function setIntegrationId(Integration $integration, int $id): void
    {
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($integration, $id);
    }
}
