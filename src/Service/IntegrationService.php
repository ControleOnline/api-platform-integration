<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use ControleOnline\Service\StatusService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IntegrationService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private StatusService $statusService,
        private ContainerInterface $container
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

    public  function execute(Integration $integration)
    {
        $serviceName = 'ControleOnline\\Service\\' . $integration->getQueueName() . 'Service';
        $method = 'integrate';
        if ($this->container->has($serviceName)) {
            $service = $this->container->get($serviceName);
            if (method_exists($service, $method))
                $service->$method($integration);
        }
    }


    public function setError(Integration $integration)
    {
        $status = $this->statusService->discoveryStatus('pending', 'error', 'integration');

        $integration->setStatus($status);
        $this->manager->persist($integration);
        $this->manager->flush();

        return $integration;
    }

    public function addIntegration(string $message, string $queueNane, ?Device $device = null, ?User $user = null, ?People $people = null): Integration
    {
        $status = $this->statusService->discoveryStatus('open', 'open', 'integration');

        $integration = new Integration();
        $integration->setDevice($device);
        $integration->setStatus($status);
        $integration->setQueueName($queueNane);
        $integration->setBody($message);
        $integration->setUser($user);
        $integration->setPeople($people);

        $this->manager->persist($integration);
        $this->manager->flush();

        return $integration;
    }
}
