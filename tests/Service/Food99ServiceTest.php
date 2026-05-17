<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Queue;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\Wallet;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ProductGroupService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
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
        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'entityManager', $this->entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'statusService', $this->statusService);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'loggerService', $loggerService);
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

    public function testArrivingRemoteStateDoesNotChangeLocalStatus(): void
    {
        $currentStatus = $this->createConfiguredMock(Status::class, [
            'getRealStatus' => 'open',
        ]);
        $order = $this->createMock(Order::class);

        $order->method('getStatus')->willReturn($currentStatus);
        $order->expects(self::never())->method('setStatus');

        $this->entityManager
            ->expects(self::never())
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

    public function testResolveFood99SettlementWalletReturnsWalletOnlyForTheActiveCompany(): void
    {
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 3,
        ]);
        $wallet = new Wallet();
        $wallet->setId(27);
        $wallet->setWallet('Pic Pay');
        $wallet->setPeople($provider);

        $walletRepository = $this->createMock(EntityRepository::class);
        $walletRepository
            ->expects(self::once())
            ->method('find')
            ->with(27)
            ->willReturn($wallet);

        $this->entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Wallet::class)
            ->willReturn($walletRepository);

        $resolvedWallet = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99SettlementWallet',
            $provider,
            '27'
        );

        self::assertSame($wallet, $resolvedWallet);
    }

    public function testResolveFood99SettlementWalletRejectsWalletFromAnotherCompany(): void
    {
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 3,
        ]);
        $otherCompany = $this->createConfiguredMock(People::class, [
            'getId' => 8,
        ]);
        $wallet = new Wallet();
        $wallet->setId(32);
        $wallet->setWallet('Pic Pay');
        $wallet->setPeople($otherCompany);

        $walletRepository = $this->createMock(EntityRepository::class);
        $walletRepository
            ->expects(self::once())
            ->method('find')
            ->with(32)
            ->willReturn($wallet);

        $this->entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Wallet::class)
            ->willReturn($walletRepository);

        $resolvedWallet = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99SettlementWallet',
            $provider,
            '32'
        );

        self::assertNull($resolvedWallet);
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

    public function testFood99WebhookOnlineStateUsesBizStatusAsPrimarySignal(): void
    {
        $resolvedState = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99WebhookOnlineState',
            [
                'biz_status' => 1,
                'online' => false,
            ]
        );

        self::assertTrue($resolvedState);

        $resolvedState = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99WebhookOnlineState',
            [
                'biz_status' => 2,
                'online' => true,
            ]
        );

        self::assertFalse($resolvedState);
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

    public function testResolveFood99RemoteClientIdUsesReceiveAddressUidOnly(): void
    {
        $remoteClientId = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99RemoteClientId',
            ['name' => 'Cliente', 'uid' => 'client-123']
        );

        self::assertSame('client-123', $remoteClientId);
    }

    public function testExtractOrderRiderFieldsFromNestedCourierPayload(): void
    {
        $json = [
            'data' => [
                'order_info' => [
                    'FOOD99COURIER' => [
                        'name' => 'PAULO VINICIUS CLEMENTINO DIAS',
                        'phone' => '11950751998',
                        'eta_to_store' => 'Arrives In 1778983633 min',
                    ],
                ],
            ],
        ];

        self::assertSame(
            'PAULO VINICIUS CLEMENTINO DIAS',
            $this->invokePrivateMethod($this->service, 'extractOrderRiderName', $json)
        );
        self::assertSame(
            '11950751998',
            $this->invokePrivateMethod($this->service, 'extractOrderRiderPhone', $json)
        );
        self::assertSame(
            'Arrives In 1778983633 min',
            $this->invokePrivateMethod($this->service, 'extractOrderRiderToStoreEta', $json)
        );
    }

    public function testSyncFood99CourierFromDeliveryStateCreatesCourierAndLinksOrder(): void
    {
        $courier = $this->createConfiguredMock(People::class, [
            'getId' => 321,
            'getName' => 'PAULO VINICIUS CLEMENTINO DIAS',
        ]);
        $order = $this->createMock(Order::class);
        $order->method('getDeliveryPeople')->willReturn(null);
        $order->expects(self::once())
            ->method('setDeliveryPeople')
            ->with($courier);
        $order->expects(self::once())
            ->method('setAlterDate')
            ->with(self::isInstanceOf(\DateTime::class));

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'name' => 'PAULO VINICIUS CLEMENTINO DIAS',
                'peopleType' => 'F',
            ])
            ->willReturn(null);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->expects(self::once())
            ->method('discoveryPeople')
            ->with(
                null,
                null,
                [
                    'ddi' => 55,
                    'ddd' => 11,
                    'phone' => 950751998,
                ],
                'PAULO VINICIUS CLEMENTINO DIAS',
                'F'
            )
            ->willReturn($courier);

        $this->entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(People::class)
            ->willReturn($peopleRepository);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'peopleService', $peopleService);

        $this->invokePrivateMethod(
            $this->service,
            'syncFood99CourierFromDeliveryState',
            $order,
            [
                'rider_name' => 'PAULO VINICIUS CLEMENTINO DIAS',
                'rider_phone' => '11950751998',
            ]
        );
    }

    public function testResolveAppShopIdUsesProviderIdAndIgnoresLegacyEnvFallbacks(): void
    {
        $previousAppShopId = array_key_exists('OAUTH_99FOOD_APP_SHOP_ID', $_ENV)
            ? $_ENV['OAUTH_99FOOD_APP_SHOP_ID']
            : null;
        $previousShopId = array_key_exists('OAUTH_99FOOD_SHOP_ID', $_ENV)
            ? $_ENV['OAUTH_99FOOD_SHOP_ID']
            : null;

        $_ENV['OAUTH_99FOOD_APP_SHOP_ID'] = 'legacy-app';
        $_ENV['OAUTH_99FOOD_SHOP_ID'] = 'legacy-shop';

        try {
            self::assertNull(
                $this->invokePrivateMethod($this->service, 'resolveAppShopId', null)
            );

            $provider = $this->createConfiguredMock(People::class, [
                'getId' => 2,
            ]);

            self::assertSame(
                '2',
                $this->invokePrivateMethod($this->service, 'resolveAppShopId', $provider)
            );
        } finally {
            if ($previousAppShopId === null) {
                unset($_ENV['OAUTH_99FOOD_APP_SHOP_ID']);
            } else {
                $_ENV['OAUTH_99FOOD_APP_SHOP_ID'] = $previousAppShopId;
            }

            if ($previousShopId === null) {
                unset($_ENV['OAUTH_99FOOD_SHOP_ID']);
            } else {
                $_ENV['OAUTH_99FOOD_SHOP_ID'] = $previousShopId;
            }
        }
    }

    public function testResolveFood99QuoteDeliveryAreaMatchChoosesMostSpecificPolygon(): void
    {
        $deliveryAreasResponse = [
            'errno' => 0,
            'data' => [
                'area_group' => [
                    [
                        'id' => 'area-big',
                        'price' => 700,
                        'avg_delivery_eta' => 1800,
                        'points' => [
                            ['latitude' => 0.0, 'longitude' => 0.0],
                            ['latitude' => 0.0, 'longitude' => 2.0],
                            ['latitude' => 2.0, 'longitude' => 2.0],
                            ['latitude' => 2.0, 'longitude' => 0.0],
                        ],
                    ],
                    [
                        'id' => 'area-small',
                        'price' => 500,
                        'avg_delivery_eta' => 600,
                        'points' => [
                            ['latitude' => 0.5, 'longitude' => 0.5],
                            ['latitude' => 0.5, 'longitude' => 1.5],
                            ['latitude' => 1.5, 'longitude' => 1.5],
                            ['latitude' => 1.5, 'longitude' => 0.5],
                        ],
                    ],
                ],
            ],
        ];
        $dropoffAddress = $this->createConfiguredMock(Address::class, [
            'getLatitude' => 1.0,
            'getLongitude' => 1.0,
        ]);

        $match = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99QuoteDeliveryAreaMatch',
            $deliveryAreasResponse,
            $dropoffAddress
        );

        self::assertIsArray($match);
        self::assertSame('area-small', $match['delivery_area_id']);
        self::assertSame(5.0, $match['price']);
        self::assertSame('10 min', $match['eta']);
    }

    public function testRequestDeliveryFromQuoteReturnsSuccessWhenQuoteIsReady(): void
    {
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 2,
        ]);
        $order = $this->createMock(Order::class);
        $order
            ->method('getProvider')
            ->willReturn($provider);
        $order
            ->method('getId')
            ->willReturn(71148);
        $order
            ->method('getOtherInformations')
            ->with(true)
            ->willReturn([
                'logistics' => [
                    'quote_state' => 'ready',
                    'price' => 10.99,
                    'eta' => '10 min',
                ],
            ]);

        $result = $this->invokePrivateMethod(
            $this->service,
            'requestDeliveryFromQuote',
            $order
        );

        self::assertSame(0, $result['errno']);
        self::assertSame('selected', $result['data']['quote_state']);
        self::assertSame(10.99, $result['data']['quote_price']);
        self::assertSame('10 min', $result['data']['quote_eta']);
    }

    public function testDiscoveryClientReusesExistingPeopleByReceiveAddressUid(): void
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
            ['name' => 'Cliente', 'uid' => 'client-123']
        );

        self::assertSame($existingClient, $resolvedClient);
    }

    public function testResolveFood99MarketplacePeopleIgnoresSharedLegacyFoodPeopleState(): void
    {
        $legacyFoodPeople = $this->createConfiguredMock(People::class, [
            'getId' => 9001,
        ]);
        $food99People = $this->createConfiguredMock(People::class, [
            'getId' => 9900,
        ]);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->expects(self::once())
            ->method('discoveryPeople')
            ->with('6012920000123', null, null, '99 Food', 'J')
            ->willReturn($food99People);

        $this->setStaticProperty(DefaultFoodService::class, 'foodPeople', $legacyFoodPeople);
        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'peopleService', $peopleService);

        $resolvedPeople = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99MarketplacePeople'
        );

        self::assertSame($food99People, $resolvedPeople);
    }

    public function testResolveFood99ProviderPaymentTypeKeepsInvoiceTypeOffWalletWhenNoWalletIsProvided(): void
    {
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 123,
        ]);
        $paymentType = $this->createConfiguredMock(PaymentType::class, [
            'getPaymentType' => 'PIX',
        ]);
        $paymentTypeRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $paymentTypeRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'people' => $provider,
                'paymentType' => 'PIX',
            ])
            ->willReturn(null);

        $walletService = $this->createMock(\ControleOnline\Service\WalletService::class);
        $walletService
            ->expects(self::once())
            ->method('discoverPaymentType')
            ->with(
                $provider,
                [
                    'paymentType' => 'PIX',
                    'frequency' => 'single',
                    'installments' => 'single',
                ]
            )
            ->willReturn($paymentType);
        $walletService
            ->expects(self::never())
            ->method('discoverWalletPaymentType');

        $this->entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(PaymentType::class)
            ->willReturn($paymentTypeRepository);

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'walletService', $walletService);

        $resolvedPaymentType = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99ProviderPaymentType',
            $provider,
            [
                'paymentType' => 'PIX',
                'aliases' => [],
                'frequency' => 'single',
                'installments' => 'single',
                'paymentCode' => '212',
            ]
        );

        self::assertSame($paymentType, $resolvedPaymentType);
    }

    public function testFood99WeeklyDueDateUsesNextWednesdayAfterWeekClose(): void
    {
        $tuesdayOrder = $this->createConfiguredMock(Order::class, [
            'getOrderDate' => new \DateTime('2026-05-05 20:29:49'),
        ]);
        $mondayOrder = $this->createConfiguredMock(Order::class, [
            'getOrderDate' => new \DateTime('2026-05-11 10:00:00'),
        ]);

        $tuesdayDueDate = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99WeeklyDueDate',
            $tuesdayOrder
        );
        $mondayDueDate = $this->invokePrivateMethod(
            $this->service,
            'resolveFood99WeeklyDueDate',
            $mondayOrder
        );

        self::assertSame('2026-05-13', $tuesdayDueDate->format('Y-m-d'));
        self::assertSame('2026-05-20', $mondayDueDate->format('Y-m-d'));
    }

    public function testFood99PayableInvoiceUsesMarketplacePeopleAndWeeklyDueDate(): void
    {
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 123,
        ]);
        $order = $this->createConfiguredMock(Order::class, [
            'getProvider' => $provider,
        ]);
        $paymentType = $this->createMock(PaymentType::class);
        $status = $this->createMock(Status::class);
        $providerWallet = $this->createMock(Wallet::class);
        $food99Wallet = $this->createMock(Wallet::class);
        $food99People = $this->createConfiguredMock(People::class, [
            'getId' => 9900,
        ]);
        $dueDate = new \DateTime('2026-05-13');
        $invoice = new Invoice();

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService
            ->expects(self::once())
            ->method('createInvoice')
            ->with(
                $order,
                $provider,
                $food99People,
                12.34,
                $status,
                $dueDate,
                $providerWallet,
                $food99Wallet
            )
            ->willReturn($invoice);

        $this->setObjectProperty(DefaultFoodService::class, $this->service, 'invoiceService', $invoiceService);

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with($invoice);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $result = $this->invokePrivateMethod(
            $this->service,
            'createFood99PayableInvoice',
            $order,
            $paymentType,
            12.34,
            $status,
            $providerWallet,
            $food99Wallet,
            $food99People,
            $dueDate,
            'delivery_fee',
            ['component_value' => 12.34]
        );

        self::assertSame($invoice, $result);
        self::assertSame($status, $invoice->getStatus());
        self::assertSame($providerWallet, $invoice->getSourceWallet());
        self::assertSame($food99Wallet, $invoice->getDestinationWallet());
    }

    public function testFood99HomologationSnapshotUsesFood99PayloadAndIgnoresLegacyIfoodData(): void
    {
        $order = $this->createMock(Order::class);
        $payload = json_decode(json_encode([
            'latest_event_type' => 'iFood',
            'Food99' => [
                'latest_event_type' => 'orderNew',
                'orderNew' => [
                    'data' => [
                        'order_info' => [
                            'order_index' => '570004',
                            'delivery_type' => '1',
                            'pay_type' => '1',
                            'pay_method' => '1',
                            'pay_channel' => '212',
                            'promotions' => [
                                [
                                    'promo_type' => 2,
                                    'promo_discount' => 1000,
                                    'shop_subside_price' => 1000,
                                ],
                            ],
                        ],
                        'price' => [
                            'order_price' => 10000,
                            'customer_need_paying_money' => 9700,
                            'real_pay_price' => 9700,
                            'real_price' => 7351,
                            'shop_paid_money' => 7351,
                            'store_charged_delivery_price' => 500,
                            'others_fees' => [
                                'service_price' => 200,
                            ],
                        ],
                    ],
                ],
            ],
            'iFood' => [
                'latest_event_type' => 'orderNew',
                'orderNew' => [
                    'data' => [
                        'order_info' => [
                            'delivery_type' => '2',
                        ],
                        'price' => [
                            'order_price' => 25000,
                            'customer_need_paying_money' => 25000,
                            'real_price' => 25000,
                            'shop_paid_money' => 25000,
                        ],
                    ],
                ],
            ],
        ]));

        $order->method('getOtherInformations')->willReturnCallback(
            static fn(bool $decode = false) => $decode ? $payload : json_encode($payload)
        );

        $snapshot = $this->service->getOrderHomologationSnapshot($order);

        self::assertTrue($snapshot['raw_payload_available']);
        self::assertSame(100.0, $snapshot['financial']['items_total']);
        self::assertSame(73.51, $snapshot['financial']['weekly_settlement_amount']);
        self::assertSame(7.11, $snapshot['financial']['commission_distribution_amount']);
        self::assertSame(2.88, $snapshot['financial']['payment_processing_amount']);
        self::assertSame(4.5, $snapshot['financial']['logistics_cost_amount']);
        self::assertSame(16.49, $snapshot['financial']['platform_charges_amount']);
        self::assertSame(97.0, $snapshot['payment']['amount_paid']);
        self::assertSame('570004', $snapshot['identifiers']['order_index']);
    }

    public function testFood99HomologationSnapshotPrefersPersistedFinancialAndPaymentBlocks(): void
    {
        $order = $this->createMock(Order::class);
        $payload = json_decode(json_encode([
            'latest_event_type' => 'orderNew',
            'Food99' => [
                'latest_event_type' => 'orderNew',
                'orderNew' => [
                    'data' => [
                        'order_info' => [
                            'delivery_type' => '2',
                            'pay_type' => '2',
                            'pay_method' => '2',
                            'pay_channel' => '153',
                        ],
                        'price' => [
                            'order_price' => 25000,
                            'customer_need_paying_money' => 25000,
                            'real_pay_price' => 25000,
                            'real_price' => 25000,
                            'shop_paid_money' => 25000,
                            'store_charged_delivery_price' => 999,
                            'others_fees' => [
                                'service_price' => 0,
                                'small_order_price' => 0,
                            ],
                        ],
                        'financial' => [
                            'items_total' => 12345,
                            'delivery_fee' => 678,
                            'service_fee_amount' => 111,
                            'small_order_fee_amount' => 222,
                            'meal_top_up_fee_amount' => 333,
                            'tip_total' => 444,
                            'subtotal_before_discounts' => 13666,
                            'discount_total' => 500,
                            'store_discount_total' => 400,
                            'platform_discount_total' => 100,
                            'store_non_delivery_discount_total' => 300,
                            'platform_non_delivery_discount_total' => 100,
                            'store_delivery_discount_total' => 0,
                            'platform_delivery_discount_total' => 0,
                            'charge_base_amount' => 1200,
                            'commission_distribution_amount' => 777,
                            'payment_processing_amount' => 888,
                            'logistics_cost_amount' => 999,
                            'platform_charges_amount' => 2664,
                            'weekly_settlement_amount' => 9876,
                            'promotions_total' => 500,
                            'items_discount_total' => 0,
                            'delivery_discount_total' => 0,
                            'coupon_discount_total' => 0,
                            'customer_total' => 11999,
                            'customer_need_paying_money' => 11999,
                            'store_receivable_total' => 25000,
                            'real_pay_total' => 25000,
                            'refund_total' => 0,
                            'shop_paid_money' => 25000,
                        ],
                        'payment' => [
                            'amount_paid' => 11999,
                            'amount_pending' => 0,
                            'customer_need_paying_money' => 11999,
                            'change_for' => 0,
                            'change_amount' => 0,
                            'is_fully_paid' => true,
                            'should_confirm_payment' => false,
                            'is_paid_online' => false,
                            'delivery_99_always_paid_rule' => false,
                        ],
                    ],
                ],
            ],
        ]));

        $order->method('getOtherInformations')->willReturnCallback(
            static fn(bool $decode = false) => $decode ? $payload : json_encode($payload)
        );

        $snapshot = $this->service->getOrderHomologationSnapshot($order);

        self::assertTrue($snapshot['raw_payload_available']);
        self::assertSame(123.45, $snapshot['financial']['items_total']);
        self::assertSame(6.78, $snapshot['financial']['delivery_fee']);
        self::assertSame(1.11, $snapshot['financial']['service_fee']);
        self::assertSame(2.22, $snapshot['financial']['small_order_fee']);
        self::assertSame(12.00, $snapshot['financial']['charge_base_amount']);
        self::assertSame(7.77, $snapshot['financial']['commission_distribution_amount']);
        self::assertSame(8.88, $snapshot['financial']['payment_processing_amount']);
        self::assertSame(9.99, $snapshot['financial']['logistics_cost_amount']);
        self::assertSame(26.64, $snapshot['financial']['platform_charges_amount']);
        self::assertSame(98.76, $snapshot['financial']['weekly_settlement_amount']);
        self::assertSame(119.99, $snapshot['payment']['amount_paid']);
        self::assertFalse($snapshot['payment']['is_paid_online']);
        self::assertFalse($snapshot['payment']['should_confirm_payment']);
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
