<?php

namespace ControleOnline\Service;

use ControleOnline\Message\SendIntegrationMessage;
use ControleOnline\Entity\Integration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class IntegrationBatchDispatcher
{
    public function __construct(private EntityManagerInterface $manager, private MessageBusInterface $bus) {}

    public function queueDevices(string $message, string $queueName, array $devices, StatusService $statuses): array
    {
        if ($devices === []) return [];
        $status = $statuses->discoveryStatus('open', 'open', 'integration');
        $integrations = [];
        foreach ($devices as $device) {
            $integration = new Integration();
            $integration->setDevice($device);
            $integration->setStatus($status);
            $integration->setQueueName($queueName);
            $integration->setBody($message);
            $integrations[] = $integration;
        }
        $this->dispatch($integrations);
        return $integrations;
    }

    public function dispatch(array $integrations): void
    {
        if ($integrations === []) return;
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($integrations as $integration) $this->manager->persist($integration);
            $this->manager->flush();
            foreach ($integrations as $integration) {
                $id = $integration->getId();
                if ($id === null) throw new \RuntimeException('Integration ID was not generated before Messenger dispatch.');
                $this->bus->dispatch(new SendIntegrationMessage($id));
            }
            // Doctrine Messenger uses the same connection: all envelopes and rows commit together.
            $connection->commit();
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            throw $error;
        }
    }
}
