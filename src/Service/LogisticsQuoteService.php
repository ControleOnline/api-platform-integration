<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceProviderRegistry;
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
        private readonly ?MarketplaceProviderRegistry $marketplaceProviderRegistry = null,
    ) {
        self::$logger = $this->loggerService->getLogger('LogisticsQuote');
    }

    public function integrate(Integration $integration): ?Order
    {
        $payload = $this->decodePayload($integration->getBody());
        if ($payload === []) {
            $message = 'Logistics quote ignored because payload is invalid';
            self::$logger?->warning($message, [
                'integration_id' => $integration->getId(),
            ]);

            throw new \RuntimeException($message);
        }

        $quoteOrder = $this->resolveQuoteOrder($payload);
        if (!$quoteOrder instanceof Order) {
            $message = 'Logistics quote ignored because quote order could not be resolved';
            self::$logger?->warning($message, [
                'integration_id' => $integration->getId(),
                'payload' => $payload,
            ]);

            throw new \RuntimeException($message);
        }

        $providerKey = $this->normalizeProviderKey($payload['provider_key'] ?? $quoteOrder->getApp());

        try {
            $provider = $this->resolveMarketplaceQuoteProvider($providerKey);
            $result = $provider instanceof MarketplaceLogisticsQuoteProviderInterface
                ? $provider->quoteDelivery($quoteOrder)
                : [
                    'errno' => 400,
                    'errmsg' => 'Provider de cotacao invalido.',
                ];
        } catch (\Throwable $exception) {
            self::$logger?->error('Logistics quote worker failed', [
                'integration_id' => $integration->getId(),
                'quote_order_id' => $quoteOrder->getId(),
                'provider_key' => $providerKey,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
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

        if (isset($result['errno']) && (int) $result['errno'] !== 0) {
            throw new \RuntimeException(
                (string) ($result['errmsg'] ?? 'Logistics quote provider returned an error.'),
                (int) $result['errno']
            );
        }

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

    private function resolveMarketplaceQuoteProvider(string $providerKey): ?MarketplaceLogisticsQuoteProviderInterface
    {
        $normalizedProviderKey = $this->normalizeProviderKey($providerKey);

        if ($this->marketplaceProviderRegistry instanceof MarketplaceProviderRegistry) {
            $provider = $this->marketplaceProviderRegistry->resolveLogisticsQuoteProvider($normalizedProviderKey);
            if ($provider instanceof MarketplaceLogisticsQuoteProviderInterface) {
                return $provider;
            }
        }

        return match ($normalizedProviderKey) {
            'ifood' => $this->iFoodService,
            'uber' => $this->uberService,
            'food99' => $this->food99Service,
            default => null,
        };
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
        $otherInformations = $this->normalizeOtherInformations($order->getOtherInformations(true));

        return $this->normalizeLogisticsState($otherInformations['logistics'] ?? []);
    }

    private function normalizeOtherInformations(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value) || is_object($value)) {
            $normalized = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);

            return is_array($normalized) ? $normalized : [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeLogisticsState(mixed $value): array
    {
        if (is_array($value) || is_object($value)) {
            $normalized = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);

            return is_array($normalized) ? $normalized : [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
