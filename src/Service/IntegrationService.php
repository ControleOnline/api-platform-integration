<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use ControleOnline\Service\StatusService;

class IntegrationService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private StatusService $statusService
    ) {}


    public function getOpenMessages(string $queueNane, array $devices = []): array
    {
        $search = [
            'queueName' => $queueNane,
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        if ($devices)
            $search['device'] = $this->manager->getRepository(Device::class)->findBy(['device' => $devices]);

        return $this->manager->getRepository(Integration::class)->findBy($search);
    }

    public function setDelivered(Integration $integration)
    {
        $status = $this->statusService->discoveryStatus('closed', 'closed', 'integration');

        $integration->setStatus($status);
        $this->manager->persist($integration);
        $this->manager->flush();

        return $integration;
    }

    public function setError(Integration $integration)
    {
        $status = $this->statusService->discoveryStatus('pending', 'error', 'integration');

        $integration->setStatus($status);
        $this->manager->persist($integration);
        $this->manager->flush();

        return $integration;
    }

    public function addIntegration(string $message, string $queueNane, ?Device $device): Integration
    {
        $status = $this->statusService->discoveryStatus('open', 'open', 'integration');

        $integration = new Integration();
        $integration->setDevice($device);
        $integration->setStatus($status);
        $integration->setQueueName($queueNane);
        $integration->setBody($message);

        $this->manager->persist($integration);
        $this->manager->flush();

        return $integration;
    }
}
