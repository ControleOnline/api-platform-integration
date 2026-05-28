<?php

namespace ControleOnline\Controller\Uber;

use ControleOnline\Entity\User;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\SkyNetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private readonly LoggerService $loggerService,
        private readonly RequestPayloadService $requestPayloadService,
        private readonly SkyNetService $skyNetService,
    ) {
        self::$logger = $loggerService->getLogger('Uber');
    }

    #[Route('/webhook/uber', name: 'uber_webhook', methods: ['POST'])]
    public function __invoke(Request $request, IntegrationService $integrationService): Response
    {
        $rawBody = $request->getContent();
        $signature = trim((string) (
            $request->headers->get('x-uber-signature')
            ?? $request->headers->get('x-postmates-signature')
            ?? ''
        ));
        $this->skyNetService->discoveryBotUser('uber');
        $uberUser = $this->skyNetService->getBotUser();

        if (!$uberUser instanceof User) {
            self::$logger->error('Uber webhook ignored because Uber user is not configured');
            return new Response('Uber user not configured', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($rawBody === '' || $signature === '') {
            self::$logger->warning('Uber webhook missing body or signature');
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $uberUser->getApiKey());
        if (!hash_equals($expectedSignature, $signature)) {
            self::$logger->warning('Uber webhook signature mismatch', [
                'received_signature' => $signature,
            ]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $this->requestPayloadService->decodeJsonContent($rawBody);
        } catch (\InvalidArgumentException $exception) {
            self::$logger->warning('Uber webhook invalid JSON', [
                'error' => $exception->getMessage(),
            ]);

            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        $meta = $this->buildWebhookMeta($payload, $rawBody);
        $existingIntegrationId = $integrationService->findRecentIntegrationIdByWebhookEvent('Uber', $meta['event_id']);
        if ($existingIntegrationId !== null) {
            self::$logger->info('Uber duplicate webhook ignored', [
                'event_id' => $meta['event_id'],
                'existing_integration_id' => $existingIntegrationId,
            ]);

            return new Response('', Response::HTTP_OK);
        }

        $payload['__webhook'] = $meta;
        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encodedPayload === false) {
            self::$logger->error('Uber webhook payload re-encoding failed', [
                'event_id' => $meta['event_id'],
                'json_error' => json_last_error_msg(),
            ]);

            return new Response('Invalid payload encoding', Response::HTTP_BAD_REQUEST);
        }

        $integrationService->addIntegrationWithHeaders(
            $encodedPayload,
            'Uber',
            ['webhook' => $meta],
            null,
            $uberUser,
            $uberUser->getPeople()
        );

        self::$logger->info('Uber webhook queued', [
            'event_id' => $meta['event_id'],
            'event_type' => $meta['event_type'],
            'external_order_id' => $meta['external_order_id'],
        ]);

        return new Response('', Response::HTTP_OK);
    }

    private function buildWebhookMeta(array $payload, string $rawBody): array
    {
        $eventType = trim((string) (
            $payload['event_type']
            ?? $payload['kind']
            ?? $payload['type']
            ?? $payload['meta']['event_type']
            ?? 'unknown'
        ));
        $eventId = trim((string) (
            $payload['event_id']
            ?? $payload['id']
            ?? $payload['meta']['event_id']
            ?? ''
        ));
        $orderId = trim((string) (
            $payload['order_id']
            ?? $payload['orderId']
            ?? $payload['meta']['resource_id']
            ?? ''
        ));
        $externalOrderId = trim((string) (
            $payload['external_order_id']
            ?? $payload['externalOrderId']
            ?? $payload['meta']['external_order_id']
            ?? ''
        ));
        $eventAt = trim((string) (
            $payload['event_time']
            ?? $payload['event_at']
            ?? $payload['meta']['event_time']
            ?? ''
        ));

        if ($eventId === '') {
            $eventId = implode('|', [
                $eventType !== '' ? $eventType : 'unknown',
                $orderId !== '' ? $orderId : 'unknown-order',
                $externalOrderId !== '' ? $externalOrderId : 'unknown-external-order',
                $eventAt !== '' ? $eventAt : 'unknown-time',
                substr(hash('sha256', $rawBody), 0, 16),
            ]);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_at' => $eventAt,
            'received_at' => date('Y-m-d H:i:s'),
            'order_id' => $orderId,
            'external_order_id' => $externalOrderId,
            'body_sha256' => hash('sha256', $rawBody),
        ];
    }
}
