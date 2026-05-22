<?php

namespace ControleOnline\Integration\Tests\MessageHandler;

use ControleOnline\Entity\Integration;
use ControleOnline\Message\SendIntegrationMessage;
use ControleOnline\MessageHandler\IntegrationMessageHandler;
use ControleOnline\Service\IntegrationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class IntegrationMessageHandlerTest extends TestCase
{
    public function testInvokeResetsClosedEntityManagerBeforeLoadingIntegration(): void
    {
        $integration = new Integration();
        $integration->setQueueName('MockQueue');
        $this->setEntityId($integration, 42);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();

        $freshManager = $this->createMock(EntityManagerInterface::class);
        $freshManager->expects(self::once())
            ->method('getRepository')
            ->with(Integration::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('find')
            ->with(42)
            ->willReturn($integration);

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

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with(true)
            ->willReturn(true);
        $lock->expects(self::once())
            ->method('isAcquired')
            ->willReturn(true);
        $lock->expects(self::once())
            ->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::once())
            ->method('createLock')
            ->with('integration:start')
            ->willReturn($lock);

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService->expects(self::once())
            ->method('executeIntegration')
            ->with($integration);

        $handler = new IntegrationMessageHandler(
            $integrationService,
            $lockFactory,
            $closedManager,
            $managerRegistry
        );

        $handler(new SendIntegrationMessage(42));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
