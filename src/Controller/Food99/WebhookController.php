<?php

namespace ControleOnline\Controller\Food99;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;

class WebhookController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('Food99');
    }

    #[Route('/webhook/99food', name: 'Food99_webhook', methods: ['POST'])]
    public function handleFood99Webhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $headerSign = $request->headers->get('didi-header-sign');
        $appSecret = $_ENV['OAUTH_99FOOD_CLIENT_SECRET'];
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            self::$logger->warning('Food99 webhook payload is not valid JSON', [
                'json_error' => json_last_error_msg(),
                'content_length' => strlen($rawInput),
            ]);

            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null) ? $info['shop'] : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        if (!$headerSign) {
            self::$logger->warning('Food99 webhook missing signature header', [
                'content_length' => strlen($rawInput),
                'event_type' => $payload['type'] ?? null,
            ]);
            return new Response('Missing signature', Response::HTTP_UNAUTHORIZED);
        }
        $signStr = $rawInput . $appSecret;
        $checkSign = md5($signStr);
        
        if ($checkSign !== $headerSign) {
            self::$logger->warning('Invalid signature', [
                'event_type' => $payload['type'] ?? null,
                'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
                'order_index' => isset($info['order_index']) ? (string) $info['order_index'] : null,
                'shop_id' => isset($shop['shop_id']) ? (string) $shop['shop_id'] : null,
                'expected' => $checkSign,
                'received' => $headerSign
            ]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $meta = $this->buildWebhookMeta($payload, $rawInput);
        $existingIntegrationId = $integrationService->findRecentIntegrationIdByWebhookEvent('Food99', $meta['event_id']);
        if ($existingIntegrationId !== null) {
            self::$logger->info('Food99 webhook duplicate ignored at enqueue step', [
                'event_id' => $meta['event_id'],
                'event_type' => $meta['event_type'],
                'order_id' => $meta['order_id'],
                'shop_id' => $meta['shop_id'],
                'existing_integration_id' => $existingIntegrationId,
            ]);

            return new JsonResponse([
                'errno' => 0,
                'errmsg' => 'ok',
                'duplicate' => true,
            ], Response::HTTP_OK);
        }

        $payload['__webhook'] = $meta;
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            self::$logger->warning('Food99 webhook payload re-encoding failed, keeping original payload', [
                'event_id' => $meta['event_id'],
                'json_error' => json_last_error_msg(),
            ]);
            $encodedPayload = $rawInput;
        }

        $integrationService->addIntegrationWithHeaders(
            $encodedPayload,
            'Food99',
            ['webhook' => $meta]
        );
        self::$logger->info('Webhook autenticado', [
            'event_id' => $meta['event_id'],
            'event_type' => $meta['event_type'],
            'order_id' => $meta['order_id'],
            'order_index' => isset($info['order_index']) ? (string) $info['order_index'] : null,
            'shop_id' => $meta['shop_id'],
            'shop_name' => $shop['shop_name'] ?? null,
        ]);


        return new JsonResponse([
            'errno' => 0,
            'errmsg' => 'ok',
        ], Response::HTTP_OK);
    }

    private function buildWebhookMeta(array $payload, string $rawInput): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($orderInfo['shop'] ?? null)
            ? $orderInfo['shop']
            : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        $eventType = trim((string) ($payload['type'] ?? 'unknown'));
        $orderId = trim((string) ($data['order_id'] ?? $orderInfo['order_id'] ?? ''));
        $shopId = trim((string) ($shop['shop_id'] ?? $payload['app_shop_id'] ?? ''));
        $eventTimestamp = trim((string) ($payload['timestamp'] ?? $data['timestamp'] ?? $orderInfo['create_time'] ?? ''));

        $providedEventId = trim((string) (
            $payload['event_id']
            ?? $payload['eventId']
            ?? $payload['id']
            ?? $payload['requestId']
            ?? ''
        ));

        $eventId = $providedEventId;
        if ($eventId === '') {
            $eventId = implode('|', [
                $eventType !== '' ? $eventType : 'unknown',
                $shopId !== '' ? $shopId : 'unknown-shop',
                $orderId !== '' ? $orderId : 'unknown-order',
                $eventTimestamp !== '' ? $eventTimestamp : 'unknown-time',
                substr(hash('sha256', $rawInput), 0, 16),
            ]);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_at' => $eventTimestamp,
            'received_at' => date('Y-m-d H:i:s'),
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'body_sha256' => hash('sha256', $rawInput),
        ];
    }
}
