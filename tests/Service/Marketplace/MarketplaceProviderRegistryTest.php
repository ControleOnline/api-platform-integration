<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceProviderRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class KeetaMarketplaceService implements
    MarketplaceIntegrationHandlerInterface,
    MarketplaceIntegrationStateProviderInterface,
    MarketplaceLogisticsQuoteProviderInterface,
    MarketplaceOrderSnapshotProviderInterface
{
    public function getMarketplaceKey(): string
    {
        return 'Keeta';
    }

    public function integrate(Integration $integration): ?Order
    {
        return null;
    }

    public function getStoredIntegrationState(People $provider): array
    {
        return [
            'connected' => true,
            'online' => true,
            'provider_id' => $provider->getId(),
        ];
    }

    public function quoteDelivery(Order $order): array
    {
        return ['errno' => 0, 'errmsg' => 'ok'];
    }

    public function requestDeliveryFromQuote(Order $order): array
    {
        return ['errno' => 0, 'errmsg' => 'ok'];
    }

    public function getStoredOrderIntegrationState(Order $order): array
    {
        return [
            'connected' => true,
            'remote_order_state' => 'ready',
        ];
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        return [
            'financial' => [
                'delivery_fee' => 7.5,
            ],
        ];
    }
}

final class MarketplaceProviderRegistryTest extends TestCase
{
    public function testResolvesMarketplaceCapabilitiesByContractForCustomProvider(): void
    {
        $service = new KeetaMarketplaceService();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('has')
            ->with(KeetaMarketplaceService::class)
            ->willReturn(true);
        $container->expects(self::once())
            ->method('get')
            ->with(KeetaMarketplaceService::class)
            ->willReturn($service);

        $registry = new MarketplaceProviderRegistry($container, [KeetaMarketplaceService::class]);

        self::assertSame($service, $registry->resolveIntegrationHandler('keeta'));
        self::assertSame($service, $registry->resolveLogisticsQuoteProvider('keeta'));
        self::assertSame($service, $registry->resolveIntegrationStateProvider('keeta'));
        self::assertSame($service, $registry->resolveOrderSnapshotProvider('keeta'));
    }
}
