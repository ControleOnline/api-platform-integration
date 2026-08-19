<?php

namespace ControleOnline\Integration\Tests\Service\Doctrine;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\Doctrine\ConnectionLostRetryHelper;
use ControleOnline\Service\LoggerService;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConnectionLostRetryHelperTest extends TestCase
{
    private function createConnectionLost(): ConnectionLost
    {
        $driverEx = $this->createMock(\Doctrine\DBAL\Driver\Exception::class);
        // Doctrine DBAL ConnectionLost typically wraps a driver exception
        return new ConnectionLost($driverEx, null);
    }

    public function testPersistAndFlushSucceedsOnFirstAttempt(): void
    {
        $entity = new Integration();
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($entity);
        $manager->expects($this->once())->method('flush');
        $manager->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Integration::class)->willReturn($manager);

        $helper = new ConnectionLostRetryHelper($registry, null);
        $result = $helper->persistAndFlushWithRetry($entity);

        $this->assertSame($manager, $result);
    }

    public function testPersistAndFlushRetriesOnceOnConnectionLostThenSucceeds(): void
    {
        $entity = new Integration();
        $manager1 = $this->createMock(EntityManagerInterface::class);
        $manager1->method('isOpen')->willReturn(true);
        $manager1->expects($this->once())->method('persist')->with($entity);
        $manager1->expects($this->once())->method('flush')
            ->willThrowException($this->createConnectionLost());

        $manager2 = $this->createMock(EntityManagerInterface::class);
        $manager2->expects($this->once())->method('persist')->with($entity);
        $manager2->expects($this->once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->exactly(2))
            ->method('getManagerForClass')
            ->with(Integration::class)
            ->willReturnOnConsecutiveCalls($manager1, $manager2);
        $registry->expects($this->once())->method('resetManager');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with(
                $this->stringContains('ConnectionLost'),
                $this->arrayHasKey('entity')
            );

        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->with('integration')->willReturn($logger);

        $helper = new ConnectionLostRetryHelper($registry, $loggerService);
        $result = $helper->persistAndFlushWithRetry($entity);

        $this->assertSame($manager2, $result);
    }

    public function testPersistAndFlushPropagatesConnectionLostWhenRetryAlsoFails(): void
    {
        $entity = new Integration();
        $ex = $this->createConnectionLost();

        $manager1 = $this->createMock(EntityManagerInterface::class);
        $manager1->method('isOpen')->willReturn(true);
        $manager1->method('persist');
        $manager1->method('flush')->willThrowException($ex);

        $manager2 = $this->createMock(EntityManagerInterface::class);
        $manager2->method('persist');
        $manager2->method('flush')->willThrowException($ex);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnOnConsecutiveCalls($manager1, $manager2);
        $registry->method('resetManager');

        $helper = new ConnectionLostRetryHelper($registry, null);

        $this->expectException(ConnectionLost::class);
        $helper->persistAndFlushWithRetry($entity);
    }

    public function testGetManagerResetsWhenClosed(): void
    {
        $managerClosed = $this->createMock(EntityManagerInterface::class);
        $managerClosed->method('isOpen')->willReturn(false);

        $managerOpen = $this->createMock(EntityManagerInterface::class);
        $managerOpen->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->exactly(2))
            ->method('getManagerForClass')
            ->with(Integration::class)
            ->willReturnOnConsecutiveCalls($managerClosed, $managerOpen);
        $registry->expects($this->once())->method('resetManager');

        $helper = new ConnectionLostRetryHelper($registry, null);
        $result = $helper->getManagerFor(Integration::class);

        $this->assertSame($managerOpen, $result);
    }
}
