<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Status;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class Food99ServiceTest extends TestCase
{
    private Food99Service $service;
    private EntityManagerInterface $entityManager;
    private StatusService $statusService;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(Food99Service::class))->newInstanceWithoutConstructor();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->statusService = $this->createMock(StatusService::class);

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'entityManager', $this->entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'statusService', $this->statusService);
        $this->setStaticProperty(DefaultFoodService::class, 'logger', new NullLogger());
    }

    public function testDeliveryStatusFallbackPromotesReadyOrderToPickedUp(): void
    {
        $status = $this->createConfiguredMock(Status::class, [
            'getRealStatus' => 'pending',
            'getStatus' => 'ready',
        ]);
        $order = $this->createConfiguredMock(Order::class, [
            'getStatus' => $status,
            'getId' => 70552,
        ]);

        $resolvedState = $this->invokePrivateMethod(
            $this->service,
            'resolveFallbackRemoteOrderStateForDeliveryEvent',
            $order,
            'deliveryStatus',
            null,
            'ready'
        );

        self::assertSame('picked_up', $resolvedState);
    }

    public function testDeliveryStatusFallbackPromotesPreparingOpenOrderToPickedUp(): void
    {
        $status = $this->createConfiguredMock(Status::class, [
            'getRealStatus' => 'open',
            'getStatus' => 'preparing',
        ]);
        $order = $this->createConfiguredMock(Order::class, [
            'getStatus' => $status,
            'getId' => 70535,
        ]);

        $resolvedState = $this->invokePrivateMethod(
            $this->service,
            'resolveFallbackRemoteOrderStateForDeliveryEvent',
            $order,
            'deliveryStatus',
            null,
            null
        );

        self::assertSame('picked_up', $resolvedState);
    }

    public function testPickedUpRemoteStateAppliesPendingWayStatus(): void
    {
        $currentStatus = $this->createConfiguredMock(Status::class, [
            'getRealStatus' => 'open',
        ]);
        $nextStatus = $this->createMock(Status::class);
        $order = $this->createMock(Order::class);

        $order->method('getStatus')->willReturn($currentStatus);
        $order->expects(self::once())->method('setStatus')->with($nextStatus);

        $this->statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('pending', 'way', 'order')
            ->willReturn($nextStatus);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with($order);

        $this->invokePrivateMethod(
            $this->service,
            'applyLocalLifecycleStatusFromRemoteState',
            $order,
            'picked_up'
        );
    }

    #[DataProvider('deliveryStatusNumericMappingProvider')]
    public function testNumericDeliveryStatusMapsToExpectedRemoteState(string $deliveryStatus, string $expectedRemoteState): void
    {
        $resolvedState = $this->invokePrivateMethod(
            $this->service,
            'resolveRemoteOrderStateFromDeliveryStatus',
            $deliveryStatus
        );

        self::assertSame($expectedRemoteState, $resolvedState);
    }

    public static function deliveryStatusNumericMappingProvider(): array
    {
        return [
            'courier to store' => ['120', 'courier_to_store'],
            'picked up' => ['130', 'picked_up'],
            'delivering' => ['140', 'delivering'],
            'arriving' => ['150', 'arriving'],
            'delivered' => ['160', 'delivered'],
        ];
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
