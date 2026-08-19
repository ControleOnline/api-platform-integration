<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;

/**
 * Marketplace financial-generation guards extracted from IntegrationService.
 */
class IntegrationMarketplaceFinancialGuard
{
    public function shouldGenerateMarketplaceFinancial(Integration $integration, mixed $result): bool
    {
        if (!$result instanceof Order) {
            return false;
        }

        $app = strtolower(trim((string) $result->getApp()));
        $queueName = strtolower(trim((string) $integration->getQueueName()));

        if ($app === strtolower(Order::APP_FOOD99) && $queueName === strtolower(Order::APP_FOOD99)) {
            $body = json_decode((string) $integration->getBody(), true);
            if (!is_array($body)) {
                return false;
            }

            return strtolower(trim((string) ($body['type'] ?? ''))) === 'ordernew';
        }

        if ($app === strtolower(Order::APP_IFOOD) && $queueName === strtolower(Order::APP_IFOOD)) {
            return $this->isIfoodFinancialGenerationEvent($integration);
        }

        return false;
    }

    public function isIfoodFinancialGenerationEvent(Integration $integration): bool
    {
        $body = json_decode((string) $integration->getBody(), true);
        if (!is_array($body)) {
            return false;
        }

        $eventCode = strtoupper(trim((string) (
            $body['fullCode']
                ?? $body['code']
                ?? $body['type']
                ?? $body['eventType']
                ?? ''
        )));

        return in_array($eventCode, [
            'CONCLUDED',
            'ORDER_CONCLUDED',
            'ORDER_FINISHED',
            'DELIVERY_CONCLUDED',
        ], true);
    }

}
