<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use ControleOnline\Service\StatusService;

class IntegrationService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private StatusService $statusService,
    ) {}


    public function getAllOpenIntegrations($limit = 100): array
    {
        $search = [
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        $queryBuilder = $this->manager->getRepository(Integration::class)->createQueryBuilder('i')
            ->where('i.queueName NOT IN (:queueNames)')
            ->andWhere('i.status = :status')
            ->setParameter('queueNames', ['Websocket'])
            //->setParameter('status', $search['status'])
            ->setMaxResults($limit);
        return $queryBuilder->getQuery()->getResult();
    }

    public function getWebsocketOpen(array $devices = [], $limit = 100): array
    {
        $search = [
            'queueName' => ['Websocket'],
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        if (!empty($devices))
            $search['device'] = $this->manager->getRepository(Device::class)->findBy(['device' => $devices], null, $limit);

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
