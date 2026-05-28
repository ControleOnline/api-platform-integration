<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\People;

interface MarketplaceIntegrationStateProviderInterface extends MarketplaceProviderInterface
{
    public function getStoredIntegrationState(People $provider): array;
}
