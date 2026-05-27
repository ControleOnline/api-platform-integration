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
    private const EPHEMERAL_QUEUE_NAMES = ['Websocket', 'PushNotification'];

    private $lock;
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
    }

    private function getManager(): EntityManagerInterface
    {
        if (method_exists($this->manager, 'isOpen') && $this->manager->isOpen()) {
            return $this->manager;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass(Integration::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Doctrine entity manager unavailable for IntegrationService.');
        }

        $this->manager = $manager;

        return $this->manager;
    }

    private function reloadIntegration(int $integrationId): ?Integration
    {
        $manager = $this->getManager();
        $integration = $manager->getRepository(Integration::class)->find($integrationId);

        return $integration instanceof Integration ? $integration : null;
    }

    private function resolveMarketplaceIntegrationHandler(Integration $integration): ?MarketplaceIntegrationHandlerInterface
    {
        if (!$this->marketplaceProviderRegistry instanceof MarketplaceProviderRegistry) {
            return null;
        }

        return $this->marketplaceProviderRegistry->resolveIntegrationHandler((string) $integration->getQueueName());
    }

    private function buildIntegrationExecutionLockKey(int $integrationId): string
    {
        return sprintf('integration:execute:%d', $integrationId);
    }

    private function acquireIntegrationExecutionLock(int $integrationId): bool
    {
        if ($integrationId <= 0) {
            return false;
        }

        try {
            $result = $this->getManager()->getConnection()->fetchOne(
                'SELECT GET_LOCK(:lockKey, 0)',
                ['lockKey' => $this->buildIntegrationExecutionLockKey($integrationId)]
            );
        } catch (Throwable) {
            return false;
        }

        return (int) $result === 1;
    }

    private function releaseIntegrationExecutionLock(int $integrationId): void
    {
        if ($integrationId <= 0) {
            return;
        }

        try {
            $this->getManager()->getConnection()->executeQuery(
                'SELECT RELEASE_LOCK(:lockKey)',
                ['lockKey' => $this->buildIntegrationExecutionLockKey($integrationId)]
            );
        } catch (Throwable) {
        }
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

            $method = 'integrate';
            $handled = false;
            $result = null;
            $service = $this->resolveMarketplaceIntegrationHandler($integration);
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

            if ($handled && $this->shouldGenerateMarketplaceFinancial($integration, $result)) {
                $this->container
                    ->get(MarketplaceOrderFinancialGenerationService::class)
                    ->generate($result);
            }

            $managedIntegration = $this->reloadIntegration((int) $integration->getId());
            if (!$managedIntegration) {
                return;
            }

            if ($handled && $this->isEphemeralQueue($managedIntegration)) {
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
        $this->logIntegrationFailure($managedIntegration, $exception);

        if (
            !$this->isIfoodOrderWebhook($managedIntegration)
            && $managedIntegration->getRetry() <= self::MAX_RETRIES
        ) {
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

    private function logIntegrationFailure(Integration $integration, ?Throwable $exception): void
    {
        if (!$exception instanceof Throwable || !$this->loggerService instanceof LoggerService) {
            return;
        }

        $previous = $exception->getPrevious();
        $context = array_filter([
            'logEntity' => $integration,
            'integrationId' => $integration->getId(),
            'queueName' => $integration->getQueueName(),
            'retry' => $integration->getRetry(),
            'deviceId' => $integration->getDevice()?->getId(),
            'peopleId' => $integration->getPeople()?->getId(),
            'userId' => $integration->getUser()?->getId(),
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'previousClass' => $previous ? $previous::class : null,
            'previousMessage' => $previous?->getMessage(),
            'body' => substr($integration->getBody(), 0, 2000),
        ], static fn($value) => $value !== null && $value !== '');

        $this->loggerService
            ->getLogger('integration')
            ->error('Integration queue execution failed', $context);
    }

    private function isIfoodOrderWebhook(Integration $integration): bool
    {
        if (strcasecmp((string) $integration->getQueueName(), 'iFood') !== 0) {
            return false;
        }

        $payload = json_decode((string) $integration->getBody(), true);
        if (!is_array($payload)) {
            return false;
        }

        return trim((string) ($payload['orderId'] ?? '')) !== '';
    }

    private function shouldGenerateMarketplaceFinancial(Integration $integration, mixed $result): bool
    {
        if (!$result instanceof Order) {
            return false;
        }

        if (strcasecmp((string) $result->getApp(), Order::APP_FOOD99) !== 0) {
            return false;
        }

        if (strcasecmp((string) $integration->getQueueName(), 'Food99') !== 0) {
            return false;
        }

        $body = json_decode((string) $integration->getBody(), true);
        if (!is_array($body)) {
            return false;
        }

        return strtolower(trim((string) ($body['type'] ?? ''))) === 'ordernew';
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
        if ($this->isEphemeralQueue($integration)) {
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
                'queueNames' => self::EPHEMERAL_QUEUE_NAMES,
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ],
            [
                'queueNames' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        );

        return [
            'deletedTotal' => $deleted,
            'queueNames' => self::EPHEMERAL_QUEUE_NAMES,
            'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
        ];
    }

    private function isEphemeralQueue(Integration $integration): bool
    {
        foreach (self::EPHEMERAL_QUEUE_NAMES as $queueName) {
            if (strcasecmp((string) $integration->getQueueName(), $queueName) === 0) {
                return true;
            }
        }

        return false;
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

        $manager = $this->getManager();
        $manager->persist($integration);
        $manager->flush();

        if (strcasecmp((string) $queueNane, 'Websocket') !== 0) {
            $this->bus->dispatch(
                new SendIntegrationMessage(
                    integrationId: $integration->getId()
                )
            );
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
            $token = $this->extractManagerAndroidPushToken($device);
            if ($token === '') {
                continue;
            }

            $devices[$token] ??= $device;
        }

        return array_values($devices);
    }

    private function extractManagerAndroidPushToken(Device $device): string
    {
        $metadata = $device->getMetadata();

        return trim((string) (
            $metadata['pushTokens']['manager']['android']['deviceToken'] ?? ''
        ));
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

        $manager = $this->getManager();
        $manager->persist($integration);
        $manager->flush();

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
