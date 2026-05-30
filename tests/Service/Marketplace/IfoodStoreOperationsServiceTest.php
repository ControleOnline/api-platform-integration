<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Marketplace\IfoodOrderOperationsService;
use ControleOnline\Service\Marketplace\IfoodStoreOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IfoodStoreOperationsServiceTest extends TestCase
{
    public function testExtractEventTimestampConvertsRemoteUtcToAppTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
            $method = (new \ReflectionObject($service))->getMethod('extractEventTimestamp');
            $method->setAccessible(true);

            $result = $method->invoke($service, [
                'createdAt' => '2026-05-29T23:37:20.421Z',
            ]);

            self::assertSame('2026-05-29 20:37:20', $result);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testPersistOrderIntegrationStateMergesIfoodBlockIntoOrderOtherInformations(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Order::class));

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $entityManagerProperty = (new \ReflectionObject($service))->getProperty('entityManager');
        $entityManagerProperty->setAccessible(true);
        $entityManagerProperty->setValue($service, $entityManager);

        $order = new Order();
        $order->setOtherInformations([
            'iFood' => [
                'existing' => 'keep',
            ],
        ]);

        $method = (new \ReflectionClass($service))->getMethod('persistOrderIntegrationState');
        $method->setAccessible(true);
        $method->invoke($service, $order, [
            'last_event_type' => 'PLACED',
            'merchant_id' => '123',
            ' ' => 'ignored',
        ]);

        $decoded = json_decode((string) $order->getOtherInformations(), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('iFood', $decoded);
        self::assertSame('keep', $decoded['iFood']['existing']);
        self::assertSame('PLACED', $decoded['iFood']['last_event_type']);
        self::assertSame('123', $decoded['iFood']['merchant_id']);
        self::assertArrayNotHasKey('', $decoded['iFood']);
    }

    public function testPerformReadyActionDelegatesToIfoodOrderOperationsService(): void
    {
        $order = $this->createStub(Order::class);
        $orderOperationsService = $this->createMock(IfoodOrderOperationsService::class);
        $orderOperationsService
            ->expects(self::once())
            ->method('performReadyAction')
            ->with($order)
            ->willReturn([
                'errno' => 0,
                'errmsg' => 'ok',
            ]);

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('has')
            ->willReturnMap([
                [IfoodOrderOperationsService::class, true],
                [\ControleOnline\Service\iFoodService::class, false],
            ]);
        $container
            ->method('get')
            ->with(IfoodOrderOperationsService::class)
            ->willReturn($orderOperationsService);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        self::assertSame([
            'errno' => 0,
            'errmsg' => 'ok',
        ], $service->performReadyAction($order));
    }

    public function testChangeStatusDelegatesToIfoodOrderOperationsService(): void
    {
        $order = $this->createStub(Order::class);
        $orderOperationsService = $this->createMock(IfoodOrderOperationsService::class);
        $orderOperationsService
            ->expects(self::once())
            ->method('changeStatus')
            ->with($order)
            ->willReturn(null);

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('has')
            ->willReturnMap([
                [IfoodOrderOperationsService::class, true],
                [\ControleOnline\Service\iFoodService::class, false],
            ]);
        $container
            ->method('get')
            ->with(IfoodOrderOperationsService::class)
            ->willReturn($orderOperationsService);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        self::assertNull($service->changeStatus($order));
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
