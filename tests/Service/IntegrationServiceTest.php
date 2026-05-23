<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Status;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\StatusService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class IntegrationServiceTest extends TestCase
{
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

        $security = $this->createStub(TokenStorageInterface::class);

        $service = new IntegrationService(
            $closedManager,
            $managerRegistry,
            $security,
            $statusService,
            $lockFactory,
            $container,
            $bus
        );

        $service->executeIntegration($integration);

        self::assertSame(1, $reloadedIntegration->getRetry());
        self::assertSame('pending', $reloadedIntegration->getStatus()->getStatus());
        self::assertSame('error', $reloadedIntegration->getStatus()->getRealStatus());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
