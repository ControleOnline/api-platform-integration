<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\StatusService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class IntegrationServiceTest extends TestCase
{
    public function testAddManagerPushIntegrationsQueuesOnlyManagerDevicesWithToken(): void
    {
        $company = $this->createStub(People::class);
        $targetDevice = new Device();
        $targetDevice->setDevice('android-manager');
        $targetDevice->setMetadata([
            'pushTokens' => [
                'manager' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-1',
                    ],
                ],
            ],
        ]);

        $duplicateTokenDevice = new Device();
        $duplicateTokenDevice->setDevice('android-manager-duplicate');
        $duplicateTokenDevice->setMetadata([
            'pushTokens' => [
                'manager' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-1',
                    ],
                ],
            ],
        ]);

        $deviceWithoutToken = new Device();
        $deviceWithoutToken->setDevice('android-manager-no-token');

        $pdvDevice = new Device();
        $pdvDevice->setDevice('pdv');
        $pdvDevice->setMetadata([
            'pushTokens' => [
                'manager' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-pdv',
                    ],
                ],
            ],
        ]);

        $targetConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($targetDevice)
            ->setType('MANAGER');
        $duplicateConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($duplicateTokenDevice)
            ->setType('MANAGER');
        $noTokenConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($deviceWithoutToken)
            ->setType('MANAGER');
        $pdvConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($pdvDevice)
            ->setType('PDV');

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $company])
            ->willReturn([$targetConfig, $duplicateConfig, $noTokenConfig, $pdvConfig]);

        $openStatus = $this->createStub(Status::class);
        $statusService = $this->createMock(StatusService::class);
        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'open', 'integration')
            ->willReturn($openStatus);

        $persistedIntegrations = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (Integration $integration) use (&$persistedIntegrations): void {
                $this->setEntityId($integration, 555);
                $persistedIntegrations[] = $integration;
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn(object $message, array $stamps = []): Envelope => new Envelope($message, $stamps));

        $service = $this->buildService($entityManager, $statusService, $bus);

        $count = $service->addManagerPushIntegrations('{"event":"order.created"}', $company);

        self::assertSame(1, $count);
        self::assertCount(1, $persistedIntegrations);
        self::assertSame('PushNotification', $persistedIntegrations[0]->getQueueName());
        self::assertSame($targetDevice, $persistedIntegrations[0]->getDevice());
        self::assertSame($company, $persistedIntegrations[0]->getPeople());
    }

    public function testAddManagerPushIntegrationsIgnoresDispatchFailuresForEphemeralQueues(): void
    {
        $company = $this->createStub(People::class);
        $targetDevice = new Device();
        $targetDevice->setDevice('android-manager');
        $targetDevice->setMetadata([
            'pushTokens' => [
                'manager' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-1',
                    ],
                ],
            ],
        ]);

        $targetConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($targetDevice)
            ->setType('MANAGER');

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $company])
            ->willReturn([$targetConfig]);

        $openStatus = $this->createStub(Status::class);
        $statusService = $this->createMock(StatusService::class);
        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'open', 'integration')
            ->willReturn($openStatus);

        $persistedIntegrations = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (Integration $integration) use (&$persistedIntegrations): void {
                $this->setEntityId($integration, 556);
                $persistedIntegrations[] = $integration;
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('messenger transport unavailable'));

        $service = $this->buildService($entityManager, $statusService, $bus);

        $count = $service->addManagerPushIntegrations('{"event":"order.created"}', $company);

        self::assertSame(1, $count);
        self::assertCount(1, $persistedIntegrations);
        self::assertSame('PushNotification', $persistedIntegrations[0]->getQueueName());
        self::assertSame($targetDevice, $persistedIntegrations[0]->getDevice());
        self::assertSame($company, $persistedIntegrations[0]->getPeople());
    }

    public function testExecuteIntegrationResetsClosedEntityManagerBeforePersistingRetryFailure(): void
    {
        $integration = new Integration();
        $integration->setQueueName('iFood');
        $integration->setBody('{"orderId":"6557f98b-1926-41bc-99a6-7f2d49d1fe3d"}');
        $this->setEntityId($integration, 77);

        $reloadedIntegration = new Integration();
        $reloadedIntegration->setQueueName('iFood');
        $reloadedIntegration->setBody('{"orderId":"6557f98b-1926-41bc-99a6-7f2d49d1fe3d"}');
        $this->setEntityId($reloadedIntegration, 77);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT GET_LOCK(:lockKey, 0)', ['lockKey' => 'integration:execute:77'])
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT RELEASE_LOCK(:lockKey)', ['lockKey' => 'integration:execute:77']);

        $freshManager = $this->createMock(EntityManagerInterface::class);
        $freshManager->method('isOpen')->willReturn(true);
        $freshManager->method('getConnection')->willReturn($connection);
        $freshManager->expects(self::exactly(2))
            ->method('getRepository')
            ->with(Integration::class)
            ->willReturn($repository);
        $freshManager->expects(self::exactly(2))
            ->method('persist')
            ->with($reloadedIntegration);
        $freshManager->expects(self::exactly(2))
            ->method('flush');

        $repository
            ->expects(self::exactly(2))
            ->method('find')
            ->with(77)
            ->willReturn($reloadedIntegration);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())->method('resetManager');
        $managerRegistry->expects(self::once())
            ->method('getManagerForClass')
            ->with(Integration::class)
            ->willReturn($freshManager);

        $closedManager = $this->createMock(EntityManagerInterface::class);
        $closedManager->expects(self::once())
            ->method('isOpen')
            ->willReturn(false);
        $closedManager->expects(self::never())->method('getRepository');
        $closedManager->expects(self::never())->method('persist');
        $closedManager->expects(self::never())->method('flush');

        $openStatus = $this->createStub(Status::class);
        $openStatus->method('getStatus')->willReturn('open');
        $openStatus->method('getRealStatus')->willReturn('open');
        $reloadedIntegration->setStatus($openStatus);

        $processingStatus = $this->createStub(Status::class);
        $processingStatus->method('getStatus')->willReturn('pending');
        $processingStatus->method('getRealStatus')->willReturn('processing');

        $status = $this->createStub(Status::class);
        $status->method('getStatus')->willReturn('pending');
        $status->method('getRealStatus')->willReturn('error');

        $statusService = $this->createMock(StatusService::class);
        $statusService->expects(self::exactly(2))
            ->method('discoveryStatus')
            ->willReturnCallback(static function (string $statusName, string $realStatus, string $context) use ($processingStatus, $status): Status {
                if ($statusName === 'pending' && $realStatus === 'processing' && $context === 'integration') {
                    return $processingStatus;
                }

                if ($statusName === 'pending' && $realStatus === 'error' && $context === 'integration') {
                    return $status;
                }

                throw new \RuntimeException('Unexpected status discovery call.');
            });

        $lock = $this->createStub(SharedLockInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())
            ->method('createLock')
            ->with('integration:start')
            ->willReturn($lock);

        $containerService = new class() {
            public function integrate(Integration $integration)
            {
                throw new \RuntimeException('downstream failure');
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('has')
            ->with('ControleOnline\\Service\\iFoodService')
            ->willReturn(true);
        $container->expects(self::once())
            ->method('get')
            ->with('ControleOnline\\Service\\iFoodService')
            ->willReturn($containerService);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Integration queue execution failed',
                self::callback(static function (array $context) use ($reloadedIntegration): bool {
                    return $context['logEntity'] === $reloadedIntegration
                        && $context['integrationId'] === 77
                        && $context['queueName'] === 'iFood'
                        && $context['retry'] === 1
                        && $context['class'] === \RuntimeException::class
                        && $context['message'] === 'downstream failure'
                        && $context['body'] === '{"orderId":"6557f98b-1926-41bc-99a6-7f2d49d1fe3d"}';
                })
            );

        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->expects(self::once())
            ->method('getLogger')
            ->with('integration')
            ->willReturn($logger);

        $security = $this->createStub(TokenStorageInterface::class);

        $service = new IntegrationService(
            $closedManager,
            $managerRegistry,
            $security,
            $statusService,
            $lockFactory,
            $container,
            $bus,
            $loggerService
        );

        $service->executeIntegration($integration);

        self::assertSame(1, $reloadedIntegration->getRetry());
        self::assertSame('pending', $reloadedIntegration->getStatus()->getStatus());
        self::assertSame('error', $reloadedIntegration->getStatus()->getRealStatus());
    }

    public function testIfoodMarketplaceFinancialGenerationTriggersOnlyForConclusionEvents(): void
    {
        $service = $this->buildService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StatusService::class)
        );

        $integration = new Integration();
        $integration->setQueueName('iFood');
        $integration->setBody('{"orderId":"ifood-order-1","type":"CONCLUDED"}');

        $order = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_IFOOD,
        ]);

        self::assertTrue(
            $this->invokePrivateMethod(
                $service,
                'shouldGenerateMarketplaceFinancial',
                $integration,
                $order,
            )
        );

        $integration->setBody('{"orderId":"ifood-order-1","type":"PLACED"}');

        self::assertFalse(
            $this->invokePrivateMethod(
                $service,
                'shouldGenerateMarketplaceFinancial',
                $integration,
                $order,
            )
        );
    }

    private function buildService(
        EntityManagerInterface $entityManager,
        StatusService $statusService,
        ?MessageBusInterface $bus = null
    ): IntegrationService {
        $lock = $this->createStub(SharedLockInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory
            ->method('createLock')
            ->with('integration:start')
            ->willReturn($lock);

        return new IntegrationService(
            $entityManager,
            $this->createStub(ManagerRegistry::class),
            $this->createStub(TokenStorageInterface::class),
            $statusService,
            $lockFactory,
            $this->createStub(ContainerInterface::class),
            $bus ?? $this->createMock(MessageBusInterface::class)
        );
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
