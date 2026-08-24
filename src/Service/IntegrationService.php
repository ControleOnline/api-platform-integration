<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use ControleOnline\Message\SendIntegrationMessage;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceProviderRegistry;
use ControleOnline\Service\Doctrine\DoctrinePersistRetryHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use ControleOnline\Service\StatusService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

class IntegrationService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 60000;

    private $lock;
    private DoctrinePersistRetryHelper $persistRetryHelper;
    private IntegrationExecutionLock $executionLock;
    private IntegrationLogSupport $logSupport;
    private IntegrationMarketplaceFinancialGuard $marketplaceFinancialGuard;

    public function __construct(
        private EntityManagerInterface $manager,
        private ManagerRegistry $managerRegistry,
        private Security $security,
        private StatusService $statusService,
        private LockFactory $lockFactory,
        private ContainerInterface $container,
        private MessageBusInterface $bus,
        private ?LoggerService $loggerService = null,
        private ?MarketplaceProviderRegistry $marketplaceProviderRegistry = null,
    ) {
        $this->lock = $this->lockFactory->createLock('integration:start');
        $this->persistRetryHelper = new DoctrinePersistRetryHelper(
            $this->manager,
            $this->managerRegistry,
            $this->loggerService,
        );
        $this->executionLock = new IntegrationExecutionLock($this->manager);
        $this->logSupport = new IntegrationLogSupport($this->loggerService);
        $this->marketplaceFinancialGuard = new IntegrationMarketplaceFinancialGuard();
    }

    private function getManager(): EntityManagerInterface
    {
        $manager = $this->persistRetryHelper->getManager();
        $this->manager = $manager;
        $this->executionLock->setManager($manager);
        return $manager;
    }

    private function persistAndFlushWithRetry(object $entity): EntityManagerInterface
    {
        $manager = $this->persistRetryHelper->persistAndFlushWithRetry($entity);
        $this->manager = $manager;
        $this->executionLock->setManager($manager);
        return $manager;
    }

    private function reloadIntegration(int $integrationId): ?Integration
    {
        $manager = $this->getManager();
        $integration = $manager->getRepository(Integration::class)->find($integrationId);

        return $integration instanceof Integration ? $integration : null;
    }


    private function acquireIntegrationExecutionLock(int $integrationId): bool
    {
        $this->getManager();
        return $this->executionLock->acquire($integrationId);
    }

    private function releaseIntegrationExecutionLock(int $integrationId): void
    {
        $this->getManager();
        $this->executionLock->release($integrationId);
    }

    private function claimIntegrationForProcessing(int $integrationId): ?Integration
    {
        $integration = $this->reloadIntegration($integrationId);
        if (!$integration instanceof Integration) {
            return null;
        }

        $status = $integration->getStatus();
        $statusName = strtolower(trim((string) ($status?->getStatus() ?? '')));
        $realStatus = strtolower(trim((string) ($status?->getRealStatus() ?? '')));
        if ($statusName !== 'open' || $realStatus !== 'open') {
            return null;
        }

        $integration->setStatus($this->statusService->discoveryStatus('pending', 'processing', 'integration'));
        $manager = $this->getManager();
        $manager->persist($integration);
        $manager->flush();

        return $integration;
    }

    public function getAllOpenIntegrations($limit = 100): array
    {
        $manager = $this->getManager();
        $search = [
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        $queryBuilder = $manager->getRepository(Integration::class)->createQueryBuilder('i')
            ->andWhere('i.queueName NOT IN (:queueNames)')
            ->andWhere('i.status = :status')
            ->setParameter('queueNames', ['Websocket'])
            ->setParameter('status', $search['status'])
            ->setMaxResults($limit);
        return $queryBuilder->getQuery()->getResult();
    }

    public function executeIntegration(Integration $integration)
    {
        $integrationId = (int) $integration->getId();
        if (!$this->acquireIntegrationExecutionLock($integrationId)) {
            return;
        }

        try {
            $integration = $this->claimIntegrationForProcessing($integrationId);
            if (!$integration instanceof Integration) {
                return;
            }

            $this->logSupport->logIntegrationProcessingStart($integration);

            $method = 'integrate';
            $handled = false;
            $result = null;
            $service = null;
            if ($this->marketplaceProviderRegistry instanceof MarketplaceProviderRegistry) {
                $service = $this->marketplaceProviderRegistry->resolveIntegrationHandler((string) $integration->getQueueName());
            }
            if ($service instanceof MarketplaceIntegrationHandlerInterface) {
                $handled = true;
                $result = $service->$method($integration);
            }

            if (!$handled) {
                $serviceName = 'ControleOnline\\Service\\' . $integration->getQueueName() . 'Service';
                if ($this->container->has($serviceName)) {
                    $service = $this->container->get($serviceName);
                    if (method_exists($service, $method)) {
                        $handled = true;
                        $result = $service->$method($integration);
                    }
                }
            }

            if ($handled && $this->marketplaceFinancialGuard->shouldGenerateMarketplaceFinancial($integration, $result)) {
                try {
                    $this->container
                        ->get(MarketplaceOrderFinancialGenerationService::class)
                        ->generate($result);
                } catch (Throwable $exception) {
                    if (!str_contains($exception->getMessage(), 'Resumo financeiro da integracao indisponivel')) {
                        throw $exception;
                    }

                    if ($this->loggerService instanceof LoggerService) {
                        $this->loggerService
                            ->getLogger('integration')
                            ->warning('Marketplace financial generation skipped', [
                                'integrationId' => $integration->getId(),
                                'queueName' => $integration->getQueueName(),
                                'class' => $exception::class,
                                'message' => $exception->getMessage(),
                            ]);
                    }
                }
            }

            $managedIntegration = $this->reloadIntegration((int) $integration->getId());
            if (!$managedIntegration) {
                return;
            }

            if ($handled && IntegrationPureHelpers::isEphemeralQueue($managedIntegration)) {
                $manager = $this->getManager();
                $manager->remove($managedIntegration);
                $manager->flush();

                return;
            }

            if (!$handled) {
                $managedIntegration->setStatus($this->statusService->discoveryStatus('closed', 'not implemented', 'integration'));
            } else {
                $managedIntegration->setStatus($this->statusService->discoveryStatus('closed', 'closed', 'integration'));
            }

            $manager = $this->getManager();
            $manager->persist($managedIntegration);
            $manager->flush();
        } catch (Throwable $exception) {
            $this->handleRetryableFailure($integration, $exception);
        } finally {
            $this->releaseIntegrationExecutionLock($integrationId);
        }
    }

    private function handleRetryableFailure(Integration $integration, ?Throwable $exception = null): void
    {
        $integrationId = (int) $integration->getId();
        if ($integrationId <= 0) {
            return;
        }

        $managedIntegration = $this->reloadIntegration($integrationId);
        if (!$managedIntegration) {
            return;
        }

        $managedIntegration->incrementRetry();
        $this->logSupport->logIntegrationFailure($managedIntegration, $exception);

        if ($managedIntegration->getRetry() <= self::MAX_RETRIES) {
            $managedIntegration->setStatus($this->statusService->discoveryStatus('open', 'open', 'integration'));
            $manager = $this->getManager();
            $manager->persist($managedIntegration);
            $manager->flush();

            $this->bus->dispatch(
                new SendIntegrationMessage($managedIntegration->getId()),
                [new DelayStamp(self::RETRY_DELAY_MS * $managedIntegration->getRetry())]
            );

            return;
        }

        $managedIntegration->setStatus($this->statusService->discoveryStatus('pending', 'error', 'integration'));
        $manager = $this->getManager();
        $manager->persist($managedIntegration);
        $manager->flush();
    }

    public function getWebsocketOpen(array $devices = [], $limit = 100): array
    {
        $manager = $this->getManager();
        $search = [
            'queueName' => ['Websocket'],
            'status' => $this->statusService->discoveryStatus('open', 'open', 'integration')
        ];

        if (!empty($devices))
            $search['device'] = $manager->getRepository(Device::class)->findBy(['device' => $devices], null, $limit);

        return $manager->getRepository(Integration::class)->findBy($search);
    }

    public function setDelivered(Integration $integration)
    {
        $manager = $this->getManager();
        if (IntegrationPureHelpers::isEphemeralQueue($integration)) {
            $manager->remove($integration);
            $manager->flush();

            return $integration;
        }

        $status = $this->statusService->discoveryStatus('closed', 'closed', 'integration');

        $integration->setStatus($status);
        $manager->persist($integration);
        $manager->flush();

        return $integration;
    }

    public function cleanupExpiredEphemeralIntegrations(?\DateTimeInterface $cutoff = null): array
    {
        $cutoff ??= new \DateTimeImmutable('-24 hours');
        $manager = $this->getManager();
        $connection = $manager->getConnection();

        $deleted = (int) $connection->executeStatement(
            'DELETE FROM integration
             WHERE queue_name IN (:queueNames)
               AND created_at < :cutoff',
            [
                'queueNames' => IntegrationPureHelpers::EPHEMERAL_QUEUE_NAMES,
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ],
            [
                'queueNames' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        );

        return [
            'deletedTotal' => $deleted,
            'queueNames' => IntegrationPureHelpers::EPHEMERAL_QUEUE_NAMES,
            'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
        ];
    }

    public function setError(Integration $integration)
    {
        $manager = $this->getManager();
        $status = $this->statusService->discoveryStatus('pending', 'error', 'integration');

        $integration->setStatus($status);
        $manager->persist($integration);
        $manager->flush();

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

        $this->persistAndFlushWithRetry($integration);

        if (strcasecmp((string) $queueNane, 'Websocket') !== 0) {
            try {
                $this->bus->dispatch(
                    new SendIntegrationMessage(
                        integrationId: $integration->getId()
                    )
                );
            } catch (Throwable $exception) {
                if (strcasecmp((string) $queueNane, 'PushNotification') !== 0) {
                    throw $exception;
                }

                if ($this->loggerService instanceof LoggerService) {
                    $this->loggerService
                        ->getLogger('integration')
                        ->warning('Ephemeral integration queue dispatch skipped', [
                            'integrationId' => $integration->getId(),
                            'queueName' => $queueNane,
                            'class' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                }
            }
        }

        return $integration;
    }

    public function addManagerPushIntegrations(string $message, People $people, ?User $user = null): int
    {
        $count = 0;
        foreach ($this->resolveManagerPushTargetDevices($people) as $device) {
            $this->addIntegration($message, 'PushNotification', $device, $user, $people);
            $count++;
        }

        return $count;
    }

    private function resolveManagerPushTargetDevices(People $people): array
    {
        $deviceConfigs = $this->getManager()->getRepository(DeviceConfig::class)->findBy([
            'people' => $people,
        ]);

        $devices = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (
                !$deviceConfig instanceof DeviceConfig
                || strtoupper(trim((string) $deviceConfig->getType())) !== 'MANAGER'
            ) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            $token = IntegrationPureHelpers::extractManagerAndroidPushToken($device);
            if ($token === '') {
                continue;
            }

            $devices[$token] ??= $device;
        }

        return array_values($devices);
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

        $this->persistAndFlushWithRetry($integration);

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
        $manager = $this->getManager();
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
            $existingId = $manager->getConnection()->fetchOne($sql, [
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

            $existingId = $manager->getConnection()->fetchOne($fallbackSql, [
                'queueName' => $queueName,
                'needle' => '%"event_id":"' . str_replace('"', '\"', $normalizedEventId) . '"%',
                'bodyNeedle' => '%"__webhook":{"event_id":"' . str_replace('"', '\"', $normalizedEventId) . '"%',
            ]);
        }

        return is_numeric($existingId) ? (int) $existingId : null;
    }
}
