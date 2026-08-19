<?php

namespace ControleOnline\Service\Doctrine;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\LoggerService;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Small helper: open EM + single reconnect retry on MySQL ConnectionLost
 * (PHP-FPM idle connection past wait_timeout).
 */
class DoctrinePersistRetryHelper
{
    public function __construct(
        private EntityManagerInterface $manager,
        private ManagerRegistry $managerRegistry,
        private ?LoggerService $loggerService = null,
        private string $entityClassForManager = Integration::class,
        private string $logChannel = 'integration',
    ) {
    }

    public function getManager(): EntityManagerInterface
    {
        if (method_exists($this->manager, 'isOpen') && $this->manager->isOpen()) {
            return $this->manager;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass($this->entityClassForManager);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Doctrine entity manager unavailable after reset.');
        }

        $this->manager = $manager;

        return $this->manager;
    }

    /**
     * Persist + flush with a single reconnect retry on ConnectionLost.
     *
     * @return EntityManagerInterface Manager used for the successful flush
     */
    public function persistAndFlushWithRetry(object $entity): EntityManagerInterface
    {
        $manager = $this->getManager();
        try {
            $manager->persist($entity);
            $manager->flush();
        } catch (ConnectionLost $e) {
            $this->logWarning('Doctrine ConnectionLost on persist/flush — reconnecting and retrying once', [
                'entity' => $entity::class,
                'message' => $e->getMessage(),
            ]);

            $this->managerRegistry->resetManager();
            $manager = $this->managerRegistry->getManagerForClass($this->entityClassForManager);
            if (!$manager instanceof EntityManagerInterface) {
                throw new \RuntimeException('Doctrine entity manager unavailable after ConnectionLost reset.');
            }
            $this->manager = $manager;

            $manager->persist($entity);
            $manager->flush();
        }

        return $manager;
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (!$this->loggerService instanceof LoggerService) {
            return;
        }
        $logger = $this->loggerService->getLogger($this->logChannel);
        if ($logger instanceof LoggerInterface) {
            $logger->warning($message, $context);
        }
    }
}
