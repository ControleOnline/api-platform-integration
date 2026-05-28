<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Service\Food99Service;
use ControleOnline\Service\UberService;
use ControleOnline\Service\iFoodService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class MarketplaceProviderRegistry
{
    private const DEFAULT_PROVIDER_SERVICE_CLASSES = [
        iFoodService::class,
        Food99Service::class,
        UberService::class,
    ];

    /** @var array<class-string, object> */
    private array $resolvedServices = [];
    /** @var array<int, class-string> */
    private array $providerServiceClasses;

    public function __construct(
        private readonly ContainerInterface $container,
        ?array $providerServiceClasses = null,
    ) {
        $this->providerServiceClasses = $providerServiceClasses ?? self::DEFAULT_PROVIDER_SERVICE_CLASSES;
    }

    public function resolveIntegrationHandler(string $providerKey): ?MarketplaceIntegrationHandlerInterface
    {
        $provider = $this->resolveProvider($providerKey);

        return $provider instanceof MarketplaceIntegrationHandlerInterface ? $provider : null;
    }

    public function resolveLogisticsQuoteProvider(string $providerKey): ?MarketplaceLogisticsQuoteProviderInterface
    {
        $provider = $this->resolveProvider($providerKey);

        return $provider instanceof MarketplaceLogisticsQuoteProviderInterface ? $provider : null;
    }

    public function resolveIntegrationStateProvider(string $providerKey): ?MarketplaceIntegrationStateProviderInterface
    {
        $provider = $this->resolveProvider($providerKey);

        return $provider instanceof MarketplaceIntegrationStateProviderInterface ? $provider : null;
    }

    public function resolveOrderSnapshotProvider(string $providerKey): ?MarketplaceOrderSnapshotProviderInterface
    {
        $provider = $this->resolveProvider($providerKey);

        return $provider instanceof MarketplaceOrderSnapshotProviderInterface ? $provider : null;
    }

    private function resolveProvider(string $providerKey): ?MarketplaceProviderInterface
    {
        $normalizedKey = $this->normalizeProviderKey($providerKey);

        foreach ($this->providerServiceClasses as $serviceClass) {
            $service = $this->resolveService($serviceClass);
            if (
                $service instanceof MarketplaceProviderInterface &&
                $this->normalizeProviderKey($service->getMarketplaceKey()) === $normalizedKey
            ) {
                return $service;
            }
        }

        return null;
    }

    private function resolveService(string $serviceClass): ?object
    {
        if (isset($this->resolvedServices[$serviceClass])) {
            return $this->resolvedServices[$serviceClass];
        }

        if (!$this->container->has($serviceClass)) {
            return null;
        }

        $service = $this->container->get($serviceClass);
        if (!is_object($service)) {
            return null;
        }

        $this->resolvedServices[$serviceClass] = $service;

        return $service;
    }

    private function normalizeProviderKey(string $value): string
    {
        $normalized = strtolower(trim($value));

        return $normalized === '99food' ? 'food99' : $normalized;
    }
}
