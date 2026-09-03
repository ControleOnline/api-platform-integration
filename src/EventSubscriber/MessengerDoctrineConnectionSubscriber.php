<?php

namespace ControleOnline\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Releases the tenant connection between Messenger polling cycles.
 *
 * Messenger workers are intentionally long-lived for low latency, but an idle
 * worker must not keep one MySQL connection open for its entire lifetime.
 */
final class MessengerDoctrineConnectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private ManagerRegistry $managerRegistry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'releaseAfterCycle',
            WorkerStoppedEvent::class => 'releaseAfterCycle',
        ];
    }

    public function releaseAfterCycle(WorkerRunningEvent|WorkerStoppedEvent $event): void
    {
        $manager = $this->managerRegistry->getManagerForClass(\ControleOnline\Entity\Integration::class);
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $connection = $manager->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }

        $manager->clear();
        if ($connection->isConnected()) {
            $connection->close();
        }
    }
}
