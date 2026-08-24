<?php

namespace ControleOnline\Service;

use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * MySQL GET_LOCK / RELEASE_LOCK helpers for integration execution exclusivity.
 */
class IntegrationExecutionLock
{
    public function __construct(
        private EntityManagerInterface $manager,
    ) {
    }

    public function setManager(EntityManagerInterface $manager): void
    {
        $this->manager = $manager;
    }

    private function buildKey(int $integrationId): string
    {
        return sprintf('integration:execute:%d', $integrationId);
    }

    public function acquire(int $integrationId): bool
    {
        if ($integrationId <= 0) {
            return false;
        }

        try {
            $result = $this->manager->getConnection()->fetchOne(
                'SELECT GET_LOCK(:lockKey, 0)',
                ['lockKey' => $this->buildKey($integrationId)]
            );
        } catch (Throwable) {
            return false;
        }

        return (int) $result === 1;
    }

    public function release(int $integrationId): void
    {
        if ($integrationId <= 0) {
            return;
        }

        try {
            $this->manager->getConnection()->executeQuery(
                'SELECT RELEASE_LOCK(:lockKey)',
                ['lockKey' => $this->buildKey($integrationId)]
            );
        } catch (Throwable) {
        }
    }
}
