<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use Throwable;

/**
 * Logging and JSON helpers for IntegrationService (keeps main service under size limit).
 */
class IntegrationLogSupport
{
    public function __construct(
        private ?LoggerService $loggerService = null,
    ) {
    }

    public function logIntegrationFailure(Integration $integration, ?Throwable $exception): void
    {
        if (!$exception instanceof Throwable || !$this->loggerService instanceof LoggerService) {
            return;
        }

        $previous = $exception->getPrevious();
        $context = $this->buildIntegrationLogContext($integration, [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'previousClass' => $previous ? $previous::class : null,
            'previousMessage' => $previous?->getMessage(),
        ]);

        $this->loggerService
            ->getLogger('integration')
            ->error('Integration queue execution failed', $context);
    }

    public function logIntegrationProcessingStart(Integration $integration): void
    {
        if (!$this->loggerService instanceof LoggerService) {
            return;
        }

        $this->loggerService
            ->getLogger('integration')
            ->info('Integration queue execution started', $this->buildIntegrationLogContext($integration));
    }

    public function buildIntegrationLogContext(Integration $integration, array $extra = []): array
    {
        $body = $this->decodeIntegrationJson($integration->getBody());
        $headers = $this->decodeIntegrationJson($integration->getHeaders() ?? '');
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($orderInfo['shop'] ?? null)
            ? $orderInfo['shop']
            : (is_array($data['shop'] ?? null) ? $data['shop'] : []);
        $webhook = is_array($headers['webhook'] ?? null) ? $headers['webhook'] : [];
        $eventType = trim((string) ($body['type'] ?? $body['eventType'] ?? $body['fullCode'] ?? $body['code'] ?? ''));

        return array_filter(array_merge([
            'logEntity' => $integration,
            'integrationId' => $integration->getId(),
            'queueName' => $integration->getQueueName(),
            'queueStatusId' => $integration->getStatus()?->getId(),
            'queueStatus' => $integration->getStatus()?->getStatus(),
            'queueRealStatus' => $integration->getStatus()?->getRealStatus(),
            'retry' => $integration->getRetry(),
            'deviceId' => $integration->getDevice()?->getId(),
            'peopleId' => $integration->getPeople()?->getId(),
            'userId' => $integration->getUser()?->getId(),
            'eventType' => $eventType !== '' ? $eventType : null,
            'bodyEventId' => isset($body['event_id']) ? (string) $body['event_id'] : null,
            'headerEventId' => isset($webhook['event_id']) ? (string) $webhook['event_id'] : null,
            'appShopId' => isset($body['app_shop_id']) ? (string) $body['app_shop_id'] : null,
            'orderId' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'orderIndex' => isset($orderInfo['order_index']) ? (string) $orderInfo['order_index'] : null,
            'shopId' => isset($shop['shop_id']) ? (string) $shop['shop_id'] : null,
            'shopName' => $shop['shop_name'] ?? null,
            'bodyPreview' => substr((string) $integration->getBody(), 0, 2000),
            'headersPreview' => substr((string) ($integration->getHeaders() ?? ''), 0, 2000),
        ], $extra), static fn($value) => $value !== null && $value !== '');
    }

    public function decodeIntegrationJson(?string $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

}
