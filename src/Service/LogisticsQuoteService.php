<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;

class LogisticsQuoteService
{
    private static $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerService $loggerService,
        private readonly QuoteLogisticsService $quoteLogisticsService,
        private readonly iFoodService $iFoodService,
        private readonly UberService $uberService,
        private readonly Food99Service $food99Service,
    ) {
        self::$logger = $this->loggerService->getLogger('LogisticsQuote');
    }

    public function integrate(Integration $integration): ?Order
    {
        $payload = $this->decodePayload($integration->getBody());
        if ($payload === []) {
            self::$logger?->warning('Logistics quote ignored because payload is invalid', [
                'integration_id' => $integration->getId(),
            ]);

            return null;
        }

        $quoteOrder = $this->resolveQuoteOrder($payload);
        if (!$quoteOrder instanceof Order) {
            self::$logger?->warning('Logistics quote ignored because quote order could not be resolved', [
                'integration_id' => $integration->getId(),
                'payload' => $payload,
            ]);

            return null;
        }

        $providerKey = $this->normalizeProviderKey($payload['provider_key'] ?? $quoteOrder->getApp());

        try {
            $result = match ($providerKey) {
                'ifood' => $this->iFoodService->quoteDelivery($quoteOrder),
                'uber' => $this->uberService->quoteDelivery($quoteOrder),
                'food99' => $this->food99Service->quoteDelivery($quoteOrder),
                default => [
                    'errno' => 400,
                    'errmsg' => 'Provider de cotacao invalido.',
                ],
            };
        } catch (\Throwable $exception) {
            $result = [
                'errno' => 500,
                'errmsg' => $exception->getMessage(),
            ];
        }

        $quoteOrder = $this->entityManager->getRepository(Order::class)->find($quoteOrder->getId()) ?? $quoteOrder;
        $quoteState = $this->extractQuoteState($quoteOrder);

        $this->quoteLogisticsService->broadcastOrderChange($quoteOrder, 'order.updated', [
            'changed' => true,
            'quoteChanged' => true,
            'quoteState' => $quoteState['quote_state'] ?? null,
            'quotePrice' => $quoteState['price'] ?? null,
            'quoteUpdatedAt' => $quoteState['quote_updated_at'] ?? null,
            'quoteError' => $result['errmsg'] ?? null,
            'quoteErrorCode' => $result['errno'] ?? null,
        ]);

        return $quoteOrder;
    }

    private function decodePayload(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolveQuoteOrder(array $payload): ?Order
    {
        $quoteOrderId = $this->normalizeEntityId(
            $payload['quote_order_id'] ?? $payload['quoteOrderId'] ?? $payload['order_id'] ?? $payload['orderId'] ?? null
        );

        if ($quoteOrderId === null) {
            return null;
        }

        $quoteOrder = $this->entityManager->getRepository(Order::class)->find($quoteOrderId);
        if (!$quoteOrder instanceof Order) {
            return null;
        }

        return $quoteOrder;
    }

    private function normalizeProviderKey(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return $normalized === '99food' ? 'food99' : $normalized;
    }

    private function normalizeEntityId(mixed $value): ?int
    {
        if (is_object($value) && method_exists($value, 'getId')) {
            $value = $value->getId();
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);
        if ($normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function extractQuoteState(Order $order): array
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            return [];
        }

        $logistics = $otherInformations['logistics'] ?? [];

        return is_array($logistics) ? $logistics : [];
    }
}
