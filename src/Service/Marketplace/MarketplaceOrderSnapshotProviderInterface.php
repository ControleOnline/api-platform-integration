<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\Order;

interface MarketplaceOrderSnapshotProviderInterface extends MarketplaceProviderInterface
{
    public function getStoredOrderIntegrationState(Order $order): array;

    public function getOrderHomologationSnapshot(Order $order): array;
}
