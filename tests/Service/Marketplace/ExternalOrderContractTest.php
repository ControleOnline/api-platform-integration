<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Service\Marketplace\ExternalOrderContract;
use PHPUnit\Framework\TestCase;

final class ExternalOrderContractTest extends TestCase
{
    public function testChannelConstantIsExternal(): void
    {
        $this->assertSame('external', ExternalOrderContract::CHANNEL_EXTERNAL);
    }

    public function testResolveFulfillmentMapsTakeoutToPickup(): void
    {
        $this->assertSame(
            ExternalOrderContract::FULFILLMENT_PICKUP,
            ExternalOrderContract::resolveFulfillmentType(['order_type' => 'TAKEOUT'])
        );
    }

    public function testResolveFulfillmentMapsDelivery(): void
    {
        $this->assertSame(
            ExternalOrderContract::FULFILLMENT_DELIVERY,
            ExternalOrderContract::resolveFulfillmentType(['order_type' => 'DELIVERY'])
        );
    }

    public function testResolveFulfillmentMapsDineIn(): void
    {
        $this->assertSame(
            ExternalOrderContract::FULFILLMENT_DINE_IN,
            ExternalOrderContract::resolveFulfillmentType(['order_type' => 'DINE_IN'])
        );
    }

    public function testResolveFulfillmentDefaultsToPickupWithoutAddress(): void
    {
        $this->assertSame(
            ExternalOrderContract::FULFILLMENT_PICKUP,
            ExternalOrderContract::resolveFulfillmentType([])
        );
    }

    public function testResolveFulfillmentDefaultsToDeliveryWithAddressHint(): void
    {
        $this->assertSame(
            ExternalOrderContract::FULFILLMENT_DELIVERY,
            ExternalOrderContract::resolveFulfillmentType(['has_delivery_address' => true])
        );
    }

    public function testBuildWebhookEventId(): void
    {
        $this->assertSame(
            'ifood:evt-123',
            ExternalOrderContract::buildWebhookEventId('iFood', 'evt-123')
        );
    }

    public function testBuildWebhookEventIdRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExternalOrderContract::buildWebhookEventId('', 'x');
    }
}
