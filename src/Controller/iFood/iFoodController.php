<?php

namespace ControleOnline\Controller\iFood;

use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class iFoodController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('iFood');
    }

    #[Route('/webhook/ifood', name: 'ifood_webhook', methods: ['POST'])]
    public function handleIFoodWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $signature = trim((string) $request->headers->get('X-IFood-Signature', ''));
        $secretKey = (string) ($_ENV['OAUTH_IFOOD_CLIENT_SECRET'] ?? '');

        if ($secretKey === '' || $signature === '') {
            self::$logger->warning('iFood webhook missing signature or secret configuration');
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = hash_hmac('sha256', $rawInput, $secretKey);
        if (!hash_equals($expectedSignature, $signature)) {
            self::$logger->error('iFood webhook signature mismatch', [
                'received_signature' => $signature,
            ]);

            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            self::$logger->error('iFood webhook JSON decode failed', [
                'error' => json_last_error_msg(),
            ]);

            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        $events = array_is_list($payload) ? $payload : [$payload];
        $queued = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventType = strtoupper(trim((string) ($event['fullCode'] ?? $event['code'] ?? '')));
            if ($eventType === 'KEEPALIVE') {
                continue;
            }

            $meta = $this->buildWebhookMeta($event, $rawInput);
            $existingIntegrationId = $integrationService->findRecentIntegrationIdByWebhookEvent('iFood', $meta['event_id']);
            if ($existingIntegrationId !== null) {
                self::$logger->info('iFood duplicate webhook ignored', [
                    'event_id' => $meta['event_id'],
                    'existing_integration_id' => $existingIntegrationId,
                ]);
                continue;
            }

            $eventPayload = $event;
            $eventPayload['__webhook'] = $meta;

            $encodedPayload = json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encodedPayload === false) {
                self::$logger->warning('iFood webhook payload re-encode failed, using raw body fallback', [
                    'event_id' => $meta['event_id'],
                    'json_error' => json_last_error_msg(),
                ]);
                $encodedPayload = $rawInput;
            }

            $integrationService->addIntegrationWithHeaders(
                $encodedPayload,
                'iFood',
                ['webhook' => $meta]
            );

            $queued++;
        }

        return new JsonResponse([
            'accepted' => true,
            'queued' => $queued,
        ], Response::HTTP_ACCEPTED);
    }

    private function buildWebhookMeta(array $event, string $rawInput): array
    {
        $eventType = trim((string) ($event['fullCode'] ?? $event['code'] ?? 'UNKNOWN'));
        $orderId = trim((string) ($event['orderId'] ?? ''));
        $merchantId = trim((string) ($event['merchantId'] ?? ''));
        $eventAt = trim((string) ($event['createdAt'] ?? ''));

        $providedEventId = trim((string) (
            $event['id']
            ?? $event['eventId']
            ?? $event['event_id']
            ?? ''
        ));

        $eventId = $providedEventId;
        if ($eventId === '') {
            $eventId = implode('|', [
                $eventType !== '' ? $eventType : 'unknown',
                $merchantId !== '' ? $merchantId : 'unknown-merchant',
                $orderId !== '' ? $orderId : 'unknown-order',
                $eventAt !== '' ? $eventAt : 'unknown-time',
                substr(hash('sha256', $rawInput), 0, 16),
            ]);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_at' => $eventAt,
            'received_at' => date('Y-m-d H:i:s'),
            'shop_id' => $merchantId,
            'order_id' => $orderId,
            'body_sha256' => hash('sha256', $rawInput),
        ];
    }
}
