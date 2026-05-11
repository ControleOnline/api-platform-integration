<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Queue;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ProductGroupService;
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

    public function testArrivingRemoteStateAppliesPendingWayStatus(): void
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
            'arriving'
        );
    }

    public function testFood99OrderIsMarkedReadyWhenLastQueueEntryReachesOutStatus(): void
    {
        $service = $this->getMockBuilder(Food99Service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['performReadyAction'])
            ->getMock();

        $oldStatus = $this->createConfiguredMock(Status::class, ['getId' => 1]);
        $outStatus = $this->createConfiguredMock(Status::class, ['getId' => 2]);
        $orderStatus = $this->createConfiguredMock(Status::class, ['getRealStatus' => 'open']);
        $queue = $this->createConfiguredMock(Queue::class, ['getStatusOut' => $outStatus]);

        $order = $this->createMock(Order::class);
        $orderProduct = $this->createMock(\ControleOnline\Entity\OrderProduct::class);
        $oldQueue = $this->createConfiguredMock(OrderProductQueue::class, [
            'getQueue' => $queue,
            'getStatus' => $oldStatus,
        ]);
        $newQueue = $this->createConfiguredMock(OrderProductQueue::class, [
            'getQueue' => $queue,
            'getStatus' => $outStatus,
            'getOrderProduct' => $orderProduct,
        ]);

        $order->method('getApp')->willReturn(Order::APP_FOOD99);
        $order->method('getStatus')->willReturn($orderStatus);
        $order->method('getOrderProducts')->willReturn([$orderProduct]);
        $orderProduct->method('getOrder')->willReturn($order);
        $orderProduct->method('getOrderProductQueues')->willReturn([$newQueue]);

        $service
            ->expects(self::once())
            ->method('performReadyAction')
            ->with($order)
            ->willReturn(['errno' => 0]);

        $this->invokePrivateMethod(
            $service,
            'handleOrderProductQueueReadyTransition',
            $oldQueue,
            $newQueue
        );
    }

    public function testFood99OrderIsNotMarkedReadyWhenSomeQueueEntryIsStillWorking(): void
    {
        $service = $this->getMockBuilder(Food99Service::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['performReadyAction'])
            ->getMock();

        $oldStatus = $this->createConfiguredMock(Status::class, ['getId' => 1]);
        $outStatus = $this->createConfiguredMock(Status::class, ['getId' => 2]);
        $workingStatus = $this->createConfiguredMock(Status::class, ['getId' => 3]);
        $orderStatus = $this->createConfiguredMock(Status::class, ['getRealStatus' => 'open']);
        $queue = $this->createConfiguredMock(Queue::class, ['getStatusOut' => $outStatus]);

        $order = $this->createMock(Order::class);
        $orderProduct = $this->createMock(\ControleOnline\Entity\OrderProduct::class);
        $oldQueue = $this->createConfiguredMock(OrderProductQueue::class, [
            'getQueue' => $queue,
            'getStatus' => $oldStatus,
        ]);
        $newQueue = $this->createConfiguredMock(OrderProductQueue::class, [
            'getQueue' => $queue,
            'getStatus' => $outStatus,
            'getOrderProduct' => $orderProduct,
        ]);
        $workingQueue = $this->createConfiguredMock(OrderProductQueue::class, [
            'getQueue' => $queue,
            'getStatus' => $workingStatus,
        ]);

        $order->method('getApp')->willReturn(Order::APP_FOOD99);
        $order->method('getStatus')->willReturn($orderStatus);
        $order->method('getOrderProducts')->willReturn([$orderProduct]);
        $orderProduct->method('getOrder')->willReturn($order);
        $orderProduct->method('getOrderProductQueues')->willReturn([$newQueue, $workingQueue]);

        $service
            ->expects(self::never())
            ->method('performReadyAction');

        $this->invokePrivateMethod(
            $service,
            'handleOrderProductQueueReadyTransition',
            $oldQueue,
            $newQueue
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
            'courier accepted' => ['130', 'courier_to_store'],
            'picked up' => ['140', 'picked_up'],
            'arriving' => ['150', 'arriving'],
            'delivered' => ['160', 'delivered'],
        ];
    }

    public function testPromotionFundingBreakdownSeparatesDeliveryAndNonDeliverySubsidies(): void
    {
        $breakdown = $this->invokePrivateMethod(
            $this->service,
            'buildPromotionFundingBreakdown',
            [
                [
                    'promo_type' => 2,
                    'promo_discount' => 2594,
                    'shop_subside_price' => 2275,
                ],
                [
                    'promo_type' => 3,
                    'promo_discount' => 999,
                    'shop_subside_price' => 990,
                ],
                [
                    'promo_type' => 11,
                    'promo_discount' => 1200,
                    'shop_subside_price' => 0,
                ],
            ]
        );

        self::assertSame(
            [
                'store_total' => 32.65,
                'platform_total' => 15.28,
                'store_delivery_total' => 9.90,
                'platform_delivery_total' => 0.09,
                'store_non_delivery_total' => 22.75,
                'platform_non_delivery_total' => 15.19,
            ],
            $breakdown
        );
    }

    public function testIncomingProductGroupUsesContentNameWhenFood99OmitsGroupId(): void
    {
        $parentProduct = $this->createMock(Product::class);
        $product = $this->createMock(Product::class);
        $productGroup = $this->createMock(ProductGroup::class);
        $productGroupService = $this->createMock(ProductGroupService::class);

        $productGroupService
            ->expects(self::once())
            ->method('discoveryProductGroup')
            ->with($parentProduct, 'Esolha o tempero da sua Batata')
            ->willReturn($productGroup);

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'productGroupService', $productGroupService);

        $resolvedGroup = $this->invokePrivateMethod(
            $this->service,
            'resolveIncomingProductGroup',
            $parentProduct,
            $product,
            [
                'app_content_id' => '',
                'content_name' => 'Esolha o tempero da sua Batata',
            ]
        );

        self::assertSame($productGroup, $resolvedGroup);
    }

    public function testResolveFood99RemoteClientIdFindsNestedCustomerClientId(): void
    {
        $remoteClientId = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99RemoteClientId',
            ['name' => 'Cliente'],
            [
                'data' => [
                    'order_info' => [
                        'customer' => [
                            'client_id' => 'client-123',
                        ],
                    ],
                ],
            ]
        );

        self::assertSame('client-123', $remoteClientId);
    }

    public function testDiscoveryClientReusesExistingPeopleByNestedClientId(): void
    {
        $existingClient = $this->createConfiguredMock(People::class, [
            'getId' => 1234,
        ]);

        $extraDataService = $this->createMock(ExtraDataService::class);
        $extraDataService
            ->expects(self::once())
            ->method('getEntityByExtraData')
            ->with(Order::APP_FOOD99, 'code', 'client-123', People::class)
            ->willReturn($existingClient);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->expects(self::never())
            ->method('discoveryPeople');

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'extraDataService', $extraDataService);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'peopleService', $peopleService);

        $resolvedClient = $this->invokePrivateMethod(
            $this->service,
            'discoveryClient',
            ['name' => 'Cliente', 'phone' => '11999999999'],
            [
                'data' => [
                    'order_info' => [
                        'customer' => [
                            'client_id' => 'client-123',
                        ],
                    ],
                ],
            ]
        );

        self::assertSame($existingClient, $resolvedClient);
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
