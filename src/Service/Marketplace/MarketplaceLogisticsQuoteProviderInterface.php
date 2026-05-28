<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\Order;

interface MarketplaceLogisticsQuoteProviderInterface extends MarketplaceProviderInterface
{
    public function quoteDelivery(Order $order): array;

    public function requestDeliveryFromQuote(Order $order): array;
}
