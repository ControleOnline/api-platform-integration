<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\People;
use ControleOnline\Service\DefaultFoodService;

abstract class AbstractMarketplaceService extends DefaultFoodService implements MarketplaceProviderInterface
{
    public function getMarketplaceKey(): string
    {
        return $this->getMarketplaceApp();
    }

    protected function init(): void
    {
        self::$app = $this->getMarketplaceApp();

        if (isset($this->loggerService)) {
            self::$logger = $this->loggerService->getLogger($this->getMarketplaceKey());
        }

        if (isset($this->peopleService)) {
            $foodPeople = $this->resolveMarketplacePeople();
            if ($foodPeople instanceof People) {
                self::$foodPeople = $foodPeople;
            }
        }
    }

    protected function normalizeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    protected function normalizeDigits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    abstract protected function getMarketplaceApp(): string;

    protected function resolveMarketplacePeople(): ?People
    {
        return null;
    }
}
