<?php

namespace ControleOnline\Integration\Tests\Service\Doctrine;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\Doctrine\DoctrinePersistRetryHelper;
use ControleOnline\Service\LoggerService;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoctrinePersistRetryHelperTest extends TestCase
{
    private function makeConnectionLost(): ConnectionLost
    {
        // DBAL ConnectionLost is typically constructed with Driver\Exception; use reflection for unit test isolation.
        $ref = new \ReflectionClass(ConnectionLost::class);
        if ($ref->getConstructor() && $ref->getConstructor()->getNumberOfRequiredParameters() > 0) {
            return $ref->newInstanceWithoutConstructor();
        }

        return new ConnectionLost('connection lost');
    }

    public function testPersistAndFlushSucceedsOnFirstAttempt(): void
    {
        $entity = $this->createStub(Integration::class);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($entity);
        $manager->expects($this->once())->method('flush');
        $manager->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('resetManager');

        $helper = new DoctrinePersistRetryHelper($manager, $registry);
        $result = $helper->persistAndFlushWithRetry($entity);

        $this->assertSame($manager, $result);
    }

    public function testPersistAndFlushRetriesOnceOnConnectionLostThenSucceeds(): void
    {
        $entity = $this->createStub(Integration::class);
        $lost = $this->makeConnectionLost();

        $manager1 = $this->createMock(EntityManagerInterface::class);
        $manager1->method('isOpen')->willReturn(true);
        $manager1->expects($this->once())->method('persist')->with($entity);
        $manager1->expects($this->once())->method('flush')->willThrowException($lost);

        $manager2 = $this->createMock(EntityManagerInterface::class);
        $manager2->expects($this->once())->method('persist')->with($entity);
        $manager2->expects($this->once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager');
        $registry->expects($this->once())
            ->method('getManagerForClass')
            ->with(Integration::class)
            ->willReturn($manager2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->with('integration')->willReturn($logger);

        $helper = new DoctrinePersistRetryHelper($manager1, $registry, $loggerService);
        $result = $helper->persistAndFlushWithRetry($entity);

        $this->assertSame($manager2, $result);
    }

    public function testPersistAndFlushPropagatesConnectionLostWhenRetryAlsoFails(): void
    {
        $entity = $this->createStub(Integration::class);
        $lost = $this->makeConnectionLost();

        $manager1 = $this->createMock(EntityManagerInterface::class);
        $manager1->method('isOpen')->willReturn(true);
        $manager1->method('persist');
        $manager1->method('flush')->willThrowException($lost);

        $manager2 = $this->createMock(EntityManagerInterface::class);
        $manager2->method('persist');
        $manager2->method('flush')->willThrowException($lost);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('resetManager');
        $registry->method('getManagerForClass')->willReturn($manager2);

        $helper = new DoctrinePersistRetryHelper($manager1, $registry);

        $this->expectException(ConnectionLost::class);
        $helper->persistAndFlushWithRetry($entity);
    }

    public function testGetManagerResetsWhenClosed(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);

        $open = $this->createMock(EntityManagerInterface::class);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager');
        $registry->expects($this->once())
            ->method('getManagerForClass')
            ->with(Integration::class)
            ->willReturn($open);

        $helper = new DoctrinePersistRetryHelper($closed, $registry);
        $this->assertSame($open, $helper->getManager());
    }
}
