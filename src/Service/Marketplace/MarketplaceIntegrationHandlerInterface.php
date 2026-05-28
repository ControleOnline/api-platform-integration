<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;

interface MarketplaceIntegrationHandlerInterface extends MarketplaceProviderInterface
{
    public function integrate(Integration $integration): ?Order;
}
