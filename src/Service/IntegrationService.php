<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use ControleOnline\Message\SendIntegrationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use ControleOnline\Service\StatusService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;


class IntegrationService
{
    private $lock;
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private StatusService $statusService,
        private LockFactory $lockFactory,
        private ContainerInterface $container,
        private MessageBusInterface $bus,

    ) {
        $this->lock = $this->lockFactory->createLock('integration:start');
    }


    public function getAllOpenIntegrations($limit = 100): array
    {
        $search = [
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        $queryBuilder = $this->manager->getRepository(Integration::class)->createQueryBuilder('i')
            ->andWhere('i.queueName NOT IN (:queueNames)')
            ->andWhere('i.status = :status')
            ->setParameter('queueNames', ['Websocket'])
            ->setParameter('status', $search['status'])
            ->setMaxResults($limit);
        return $queryBuilder->getQuery()->getResult();
    }

    public function executeIntegration(Integration $integration)
    {
        if (strcasecmp((string) $integration->getQueueName(), 'Websocket') === 0) {
            return;
        }

        $serviceName = 'ControleOnline\\Service\\' . $integration->getQueueName() . 'Service';
        $method = 'integrate';
        $handled = false;
        if ($this->container->has($serviceName)) {
            $service = $this->container->get($serviceName);
            if (method_exists($service, $method)) {
                $handled = true;
                $service->$method($integration);
            }
        }

        if (!$handled) {
            $integration->setStatus($this->statusService->discoveryStatus('closed', 'not implemented', 'integration'));
        } else {
            $integration->setStatus($this->statusService->discoveryStatus('closed', 'closed', 'integration'));
        }

        $this->manager->persist($integration);
        $this->manager->flush();
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
        if (is_array($message) && isset($message['destination']))
            unset($message['destination']);
        $integration = new Integration();
        $integration->setDevice($device);
        $integration->setStatus($status);
        $integration->setQueueName($queueNane);
        $integration->setBody($message);
        $integration->setUser($user);
        $integration->setPeople($people);

        $this->manager->persist($integration);
        $this->manager->flush();

        if (strcasecmp((string) $queueNane, 'Websocket') !== 0) {
            $this->bus->dispatch(
                new SendIntegrationMessage(
                    integrationId: $integration->getId()
                )
            );
        }

        return $integration;
    }

    public function addIntegrationWithHeaders(
        string $message,
        string $queueNane,
        ?array $headers = null,
        ?Device $device = null,
        ?User $user = null,
        ?People $people = null
    ): Integration {
        $status = $this->statusService->discoveryStatus('open', 'open', 'integration');
        if (is_array($message) && isset($message['destination'])) {
            unset($message['destination']);
        }

        $integration = new Integration();
        $integration->setDevice($device);
        $integration->setStatus($status);
        $integration->setQueueName($queueNane);
        $integration->setBody($message);
        $integration->setHeaders($headers ? json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
        $integration->setUser($user);
        $integration->setPeople($people);

        $this->manager->persist($integration);
        $this->manager->flush();

        if (strcasecmp((string) $queueNane, 'Websocket') !== 0) {
            $this->bus->dispatch(
                new SendIntegrationMessage(
                    integrationId: $integration->getId()
                )
            );
        }

        return $integration;
    }

    public function findRecentIntegrationIdByWebhookEvent(
        string $queueName,
        string $eventId,
        int $lookbackHours = 72
    ): ?int {
        $normalizedEventId = trim((string) $eventId);
        if ($normalizedEventId === '') {
            return null;
        }

        $hours = max(1, min($lookbackHours, 24 * 30));
        $sql = <<<SQL
            SELECT id
            FROM integration
            WHERE queue_name = :queueName
              AND created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
              AND (
                    JSON_UNQUOTE(JSON_EXTRACT(headers, '$.webhook.event_id')) = :eventId
                    OR JSON_UNQUOTE(JSON_EXTRACT(body, '$.__webhook.event_id')) = :eventId
              )
            ORDER BY id DESC
            LIMIT 1
        SQL;

        try {
            $existingId = $this->manager->getConnection()->fetchOne($sql, [
                'queueName' => $queueName,
                'eventId' => $normalizedEventId,
            ]);
        } catch (\Throwable $e) {
            // Fallback for environments without JSON path support.
            $fallbackSql = <<<SQL
                SELECT id
                FROM integration
                WHERE queue_name = :queueName
                  AND created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                  AND (
                        headers LIKE :needle
                        OR body LIKE :bodyNeedle
                  )
                ORDER BY id DESC
                LIMIT 1
            SQL;

            $existingId = $this->manager->getConnection()->fetchOne($fallbackSql, [
                'queueName' => $queueName,
                'needle' => '%"event_id":"' . str_replace('"', '\"', $normalizedEventId) . '"%',
                'bodyNeedle' => '%"__webhook":{"event_id":"' . str_replace('"', '\"', $normalizedEventId) . '"%',
            ]);
        }

        return is_numeric($existingId) ? (int) $existingId : null;
    }
}
