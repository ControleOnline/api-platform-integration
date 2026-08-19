<?php

namespace ControleOnline\Service\Doctrine;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\LoggerService;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reusable Doctrine persist/flush with a single reconnect retry on MySQL ConnectionLost
 * (e.g. PHP-FPM idle connection exceeded MySQL wait_timeout).
 */
class ConnectionLostRetryHelper
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private ?LoggerService $loggerService = null,
    ) {
    }

    public function getManagerFor(string $entityClass = Integration::class): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass($entityClass);

        if ($manager instanceof EntityManagerInterface && method_exists($manager, 'isOpen') && $manager->isOpen()) {
            return $manager;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass($entityClass);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException(sprintf(
                'Doctrine entity manager unavailable for %s.',
                $entityClass
            ));
        }

        return $manager;
    }

    /**
     * Persist + flush with a single reconnect retry on ConnectionLost.
     * Returns the (possibly reset) EntityManager used for the successful flush.
     *
     * @throws ConnectionLost when the second attempt also fails with ConnectionLost
     * @throws \Throwable other persistence errors propagate unchanged
     */
    public function persistAndFlushWithRetry(object $entity, string $entityClass = Integration::class): EntityManagerInterface
    {
        $manager = $this->getManagerFor($entityClass);

        try {
            $manager->persist($entity);
            $manager->flush();
        } catch (ConnectionLost $e) {
            if ($this->loggerService instanceof LoggerService) {
                $this->loggerService
                    ->getLogger('integration')
                    ->warning('Doctrine ConnectionLost on persist/flush — reconnecting and retrying once', [
                        'entity' => $entity::class,
                        'message' => $e->getMessage(),
                    ]);
            }

            $this->managerRegistry->resetManager();
            $manager = $this->managerRegistry->getManagerForClass($entityClass);

            if (!$manager instanceof EntityManagerInterface) {
                throw new \RuntimeException(sprintf(
                    'Doctrine entity manager unavailable after ConnectionLost reset for %s.',
                    $entityClass
                ));
            }

            // Entity may be detached after reset; re-persist on the fresh manager.
            $manager->persist($entity);
            $manager->flush();
        }

        return $manager;
    }
}
