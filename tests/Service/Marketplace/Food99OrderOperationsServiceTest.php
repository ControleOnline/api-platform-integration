<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Marketplace\Food99OrderOperationsService;
use ControleOnline\Entity\Status;
use PHPUnit\Framework\TestCase;

final class Food99OrderOperationsServiceTest extends TestCase
{
    public function testExtractOrderEventTimestampConvertsRemoteUtcToAppTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $eventService = new class extends \ControleOnline\Service\Marketplace\Food99PeopleOperationsService {
                public function __construct()
                {
                }

                public function searchPayloadValueByKeys(mixed $payload, array $keys): ?string
                {
                    return is_array($payload) ? ($payload['createdAt'] ?? null) : null;
                }
            };

            $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
            $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99PeopleOperationsService', $eventService);

            $result = $this->invokePrivateMethod(
                $service,
                'extractOrderEventTimestamp',
                [
                    'createdAt' => '2026-05-29T23:37:20.421Z',
                ]
            );

            self::assertSame('2026-05-29 20:37:20', $result);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testBuildLogContextUsesIntegrationAndWebhookPayloadData(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $integration = new Integration();
        $this->setEntityId($integration, 263227);

        $context = $this->invokePrivateMethod(
            $service,
            'buildLogContext',
            $integration,
            [
                'type' => 'orderNew',
                'data' => [
                    'order_id' => '5764671854459555132',
                    'order_info' => [
                        'order_index' => '570004',
                        'shop' => [
                            'shop_id' => '5764612470103345070',
                            'shop_name' => 'Gyros Greek Barbecue',
                        ],
                    ],
                ],
            ],
            [
                'retry' => 4,
            ]
        );

        self::assertSame(263227, $context['integration_id']);
        self::assertSame($integration, $context['logEntity']);
        self::assertSame('orderNew', $context['event_type']);
        self::assertSame('5764671854459555132', $context['order_id']);
        self::assertSame('570004', $context['order_index']);
        self::assertSame('5764612470103345070', $context['shop_id']);
        self::assertSame('Gyros Greek Barbecue', $context['shop_name']);
        self::assertSame(4, $context['retry']);
    }

    public function testSyncProviderWebhookReceiptStateDelegatesToFood99Service(): void
    {
        $food99Service = new class extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct()
            {
            }

            public function syncProviderWebhookReceiptState(array $json): void
            {
                $this->calls[] = $json;
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);

        $this->invokePrivateMethod(
            $service,
            'syncProviderWebhookReceiptState',
            [
                'type' => 'orderNew',
                'data' => ['order_id' => '5764671854459555132'],
            ]
        );

        self::assertSame(
            [
                [
                    'type' => 'orderNew',
                    'data' => ['order_id' => '5764671854459555132'],
                ],
            ],
            $food99Service->calls
        );
    }

    public function testExtractIncomingOrderIdentifiersDelegatesToFood99Service(): void
    {
        $food99Service = new class extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct()
            {
            }

            public function extractIncomingOrderIdentifiers(array $json): array
            {
                $this->calls[] = $json;

                return [
                    'order_id' => '5764671854459555132',
                    'order_index' => '570004',
                    'order_code' => '570004',
                ];
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);

        $result = $this->invokePrivateMethod(
            $service,
            'extractIncomingOrderIdentifiers',
            [
                'type' => 'orderNew',
                'data' => [
                    'order_id' => '5764671854459555132',
                ],
            ]
        );

        self::assertSame([
            'order_id' => '5764671854459555132',
            'order_index' => '570004',
            'order_code' => '570004',
        ], $result);
        self::assertSame(
            [
                [
                    'type' => 'orderNew',
                    'data' => [
                        'order_id' => '5764671854459555132',
                    ],
                ],
            ],
            $food99Service->calls
        );
    }

    public function testSyncStoreStatusWebhookDelegatesToFood99StoreService(): void
    {
        $storeService = new class extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct()
            {
            }

            public function syncStoreStatusWebhook(array $json): void
            {
                $this->calls[] = $json;
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $storeService);

        $this->invokePrivateMethod(
            $service,
            'syncStoreStatusWebhook',
            [
                'type' => 'shopStatus',
                'data' => ['shop_id' => '3'],
            ]
        );

        self::assertSame(
            [
                [
                    'type' => 'shopStatus',
                    'data' => ['shop_id' => '3'],
                ],
            ],
            $storeService->calls
        );
    }

    public function testSyncFood99ClientDataDelegatesToFood99PeopleService(): void
    {
        $client = new \ControleOnline\Entity\People();
        $peopleService = new class($client) extends \ControleOnline\Service\Marketplace\Food99PeopleOperationsService {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\People $client)
            {
            }

            public function syncFood99ClientData(
                \ControleOnline\Entity\People $client,
                \ControleOnline\Entity\People $provider,
                array $address,
                string $remoteClientId = ''
            ): \ControleOnline\Entity\People {
                $this->calls[] = [$client, $provider, $address, $remoteClientId];

                return $this->client;
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99PeopleOperationsService', $peopleService);

        $provider = new \ControleOnline\Entity\People();
        $result = $this->invokePrivateMethod(
            $service,
            'syncFood99ClientData',
            $client,
            $provider,
            ['street_name' => 'Rua A'],
            '123'
        );

        self::assertSame($client, $result);
        self::assertCount(1, $peopleService->calls);
        self::assertSame($client, $peopleService->calls[0][0]);
        self::assertSame($provider, $peopleService->calls[0][1]);
        self::assertSame(['street_name' => 'Rua A'], $peopleService->calls[0][2]);
        self::assertSame('123', $peopleService->calls[0][3]);
    }

    public function testResolveOrderClientDelegatesToFood99Service(): void
    {
        $resolvedClient = new \ControleOnline\Entity\People();
        $food99Service = new class($resolvedClient) extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\People $client)
            {
            }

            public function resolveOrderClient(
                \ControleOnline\Entity\People $provider,
                array $address,
                array $payload,
                string $orderId
            ): \ControleOnline\Entity\People {
                $this->calls[] = [$provider, $address, $payload, $orderId];

                return $this->client;
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);

        $provider = new \ControleOnline\Entity\People();
        $result = $this->invokePrivateMethod(
            $service,
            'resolveOrderClient',
            $provider,
            ['street_name' => 'Rua A'],
            ['data' => ['order_id' => '5764671854459555132']],
            '5764671854459555132'
        );

        self::assertSame($resolvedClient, $result);
        self::assertCount(1, $food99Service->calls);
        self::assertSame($provider, $food99Service->calls[0][0]);
        self::assertSame(['street_name' => 'Rua A'], $food99Service->calls[0][1]);
        self::assertSame(['data' => ['order_id' => '5764671854459555132']], $food99Service->calls[0][2]);
        self::assertSame('5764671854459555132', $food99Service->calls[0][3]);
    }

    public function testDiscoveryClientUsesInjectedExtraDataServiceLookup(): void
    {
        $resolved = new \ControleOnline\Entity\People();
        $extraDataService = $this->createMock(\ControleOnline\Service\ExtraDataService::class);
        $extraDataService->expects(self::once())
            ->method('getEntityByExtraData')
            ->with(
                'Food99',
                'code',
                'remote-1',
                \ControleOnline\Entity\People::class
            )
            ->willReturn($resolved);

        $peopleService = new class extends \ControleOnline\Service\Marketplace\Food99PeopleOperationsService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function resolveFood99RemoteClientId(array $address, array $payload = []): string
            {
                $this->calls[] = [$address, $payload];

                return 'remote-1';
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99PeopleOperationsService', $peopleService);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $this->invokePrivateMethod(
            $service,
            'discoveryClient',
            ['street_name' => 'Rua A'],
            ['data' => ['order_id' => '5764671854459555132']]
        );

        self::assertSame($resolved, $result);
        self::assertSame([
            [
                ['street_name' => 'Rua A'],
                ['data' => ['order_id' => '5764671854459555132']],
            ],
        ], $peopleService->calls);
    }

    public function testFindExistingIntegratedOrderUsesStoredSnapshotFallbackWhenExtraDataIsMissing(): void
    {
        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 71674);

        $extraDataService = $this->createMock(\ControleOnline\Service\ExtraDataService::class);
        $extraDataService->expects(self::once())
            ->method('getEntityByExtraData')
            ->with(
                'Food99',
                'id',
                '5764671883811294471',
                \ControleOnline\Entity\Order::class
            )
            ->willReturn(null);

        $food99Service = new class($order) extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\Order $order)
            {
            }

            public function findFood99OrderByStoredIntegrationState(string $orderId, string $orderCode = ''): ?\ControleOnline\Entity\Order
            {
                $this->calls[] = [$orderId, $orderCode];

                return $this->order;
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $this->invokePrivateMethod(
            $service,
            'findExistingIntegratedOrder',
            '5764671883811294471',
            '570001'
        );

        self::assertSame($order, $result);
        self::assertSame([
            ['5764671883811294471', '570001'],
        ], $food99Service->calls);
    }

    public function testStoredOrderIntegrationStateAndConfirmResultDelegateToFood99Service(): void
    {
        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 901);

        $food99Service = new class extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct()
            {
            }

            public function getStoredOrderIntegrationState(\ControleOnline\Entity\Order $order): array
            {
                $this->calls[] = ['state', $order->getId()];

                return [
                    'food99_id' => '5764671883811294471',
                    'confirm_errno' => '0',
                ];
            }

            public function persistOrderConfirmResult(\ControleOnline\Entity\Order $order, ?array $response): array
            {
                $this->calls[] = ['confirm', $order->getId(), $response];

                return $response ?? [
                    'errno' => 10001,
                    'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.',
                    'data' => [],
                ];
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);

        $storedState = $this->invokePrivateMethod(
            $service,
            'getStoredOrderIntegrationState',
            $order
        );

        $confirmResult = $this->invokePrivateMethod(
            $service,
            'persistOrderConfirmResult',
            $order,
            ['errno' => 0, 'errmsg' => 'ok', 'data' => []]
        );

        self::assertSame([
            'food99_id' => '5764671883811294471',
            'confirm_errno' => '0',
        ], $storedState);
        self::assertSame(['errno' => 0, 'errmsg' => 'ok', 'data' => []], $confirmResult);
        self::assertSame([
            ['state', 901],
            ['confirm', 901, ['errno' => 0, 'errmsg' => 'ok', 'data' => []]],
        ], $food99Service->calls);
    }

    public function testThrowIfConfirmationShouldRetryIgnoresUnavailableConfirmationResponse(): void
    {
        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 902);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        $this->invokePrivateMethod(
            $service,
            'throwIfConfirmationShouldRetry',
            ['errno' => 10001, 'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.', 'data' => []],
            '5764671883811294471',
            $order
        );

        self::assertTrue(true);
    }

    public function testHandleCancelledOrderConfirmationResponseMarksOrderAsCancelled(): void
    {
        $currentStatus = $this->createConfiguredMock(Status::class, [
            'getRealStatus' => 'open',
            'getStatus' => 'open',
        ]);
        $cancelledStatus = $this->createMock(Status::class);

        $order = $this->createMock(\ControleOnline\Entity\Order::class);
        $order->method('getId')->willReturn(903);
        $order->method('getStatus')->willReturn($currentStatus);
        $order->expects(self::once())->method('setStatus')->with($cancelledStatus);

        $statusService = $this->createMock(\ControleOnline\Service\StatusService::class);
        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('canceled', 'canceled', 'order')
            ->willReturn($cancelledStatus);

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with($order);
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(\ControleOnline\Service\DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(\ControleOnline\Service\DefaultFoodService::class, $service, 'statusService', $statusService);

        $result = $this->invokePrivateMethod(
            $service,
            'handleCancelledOrderConfirmationResponse',
            $order,
            '5764672126908958267',
            [
                'errno' => 400,
                'errmsg' => 'The order has been cancelled. Please check the order status.',
                'data' => [],
            ]
        );

        self::assertTrue($result);
    }

    public function testResolveIncomingProductCodePrefersRemoteItemIdentifiers(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            '1343',
            $this->invokePrivateMethod(
                $service,
                'resolveIncomingProductCode',
                [
                    'app_item_id' => '1343',
                    'mdu_id' => '41596A06507E5D0E6F5B03122F1CB380_2',
                    'app_external_id' => 'external-1',
                    'name' => 'Combo Alpha Gyros',
                    'sku_price' => 7300,
                ],
                'product'
            )
        );
    }

    public function testResolveIncomingProductCodeBuildsFallbackWhenIdentifiersAreMissing(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            'food99:66af4de2f6ef66e34eec3b76',
            $this->invokePrivateMethod(
                $service,
                'resolveIncomingProductCode',
                [
                    'name' => 'Combo Alpha Gyros',
                    'content_name' => 'Escolha sua batata',
                    'app_content_id' => 'mg_194',
                    'sku_price' => 7300,
                ],
                'product'
            )
        );
    }

    public function testFindIncomingProductByCodePrefersProductThatAlreadyHasQueue(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        $queuedProduct = new \ControleOnline\Entity\Product();
        $this->setEntityIdOnProduct($queuedProduct, 1923);
        $queuedProduct->setProduct('Zetta Gyros');
        $queuedProduct->setQueue($this->createMock(\ControleOnline\Entity\Queue::class));

        $duplicateProduct = new \ControleOnline\Entity\Product();
        $this->setEntityIdOnProduct($duplicateProduct, 1927);
        $duplicateProduct->setProduct('Zetta Gyros (Pernil)');

        $extraFields = $this->createMock(\ControleOnline\Entity\ExtraFields::class);
        $queuedExtraData = $this->createConfiguredMock(\ControleOnline\Entity\ExtraData::class, [
            'getEntityId' => '1923',
        ]);
        $duplicateExtraData = $this->createConfiguredMock(\ControleOnline\Entity\ExtraData::class, [
            'getEntityId' => '1927',
        ]);

        $extraDataRepository = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $extraDataRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(self::callback(function (array $criteria) use ($extraFields): bool {
                return ($criteria['extra_fields'] ?? null) === $extraFields
                    && ($criteria['entity_name'] ?? null) === 'Product'
                    && ($criteria['value'] ?? null) === '1923';
            }))
            ->willReturn([$duplicateExtraData, $queuedExtraData]);

        $productRepository = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $productRepository
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturnCallback(static function (int $id) use ($queuedProduct, $duplicateProduct): ?\ControleOnline\Entity\Product {
                return match ($id) {
                    1923 => $queuedProduct,
                    1927 => $duplicateProduct,
                    default => null,
                };
            });

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $className) use ($extraDataRepository, $productRepository): object {
                return match ($className) {
                    \ControleOnline\Entity\ExtraData::class => $extraDataRepository,
                    \ControleOnline\Entity\Product::class => $productRepository,
                    default => throw new \RuntimeException('Unexpected repository: ' . $className),
                };
            });

        $extraDataService = new class($extraFields) extends \ControleOnline\Service\ExtraDataService {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\ExtraFields $extraFields)
            {
            }

            public function discoveryExtraFields(
                string $fieldName,
                string $context,
                ?string $configs = '{}',
                ?string $fieldType = 'text',
                ?bool $required = false
            ): \ControleOnline\Entity\ExtraFields {
                $this->calls[] = [$fieldName, $context, $configs, $fieldType, $required];
                return $this->extraFields;
            }
        };

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $this->invokePrivateMethod(
            $service,
            'findIncomingProductByCode',
            '1923'
        );

        self::assertSame($queuedProduct, $result);
        self::assertSame([
            ['code', 'Food99', '{}', 'text', false],
        ], $extraDataService->calls);
    }

    public function testFilterOpenDeliveryEventsByWindowKeepsOnlyEventsWithinTheRequestedDay(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        $events = [
            [
                'event_id' => 'yesterday',
                'order_id' => '1',
                'original_event_type' => 'orderNew',
                'mapped_event_type' => 'orderNew',
                'created_at_ts' => (new \DateTimeImmutable('2026-05-28 12:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            ],
            [
                'event_id' => 'today',
                'order_id' => '2',
                'original_event_type' => 'orderNew',
                'mapped_event_type' => 'orderNew',
                'created_at_ts' => (new \DateTimeImmutable('2026-05-29 03:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            ],
            [
                'event_id' => 'before-window',
                'order_id' => '3',
                'original_event_type' => 'orderNew',
                'mapped_event_type' => 'orderNew',
                'created_at_ts' => (new \DateTimeImmutable('2026-05-27 23:59:59', new \DateTimeZone('UTC')))->getTimestamp(),
            ],
        ];

        $result = $this->invokePrivateMethod(
            $service,
            'filterOpenDeliveryEventsByWindow',
            $events,
            '2026-05-28T03:00:00Z',
            '2026-05-29T03:00:00Z'
        );

        self::assertCount(1, $result);
        self::assertSame('yesterday', $result[0]['event_id']);
    }

    public function testMapOpenDeliveryEventTypeKeepsCancellationRequestNeutral(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        self::assertSame('orderFinish', $this->invokePrivateMethod(
            $service,
            'mapOpenDeliveryEventType',
            'DELIVERED'
        ));
        self::assertSame('orderCancelRequest', $this->invokePrivateMethod(
            $service,
            'mapOpenDeliveryEventType',
            'CANCELLATION_REQUESTED'
        ));
        self::assertSame('orderDetailSync', $this->invokePrivateMethod(
            $service,
            'mapOpenDeliveryEventType',
            'CANCELLATION_REQUEST_DENIED'
        ));
    }

    public function testBuildOrderDetailSyncPayloadMaterializesFood99FinancialSnapshot(): void
    {
        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 3,
        ]);

        $payload = [
            'data' => [
                'order_id' => 5764672390386747126,
                'order_index' => 570006,
                'pay_type' => 1,
                'pay_method' => 1,
                'pay_channel' => 150,
                'delivery_type' => 1,
                'price' => [
                    'order_price' => 18370,
                    'others_fees' => [
                        'service_price' => 510,
                    ],
                    'refund_price' => 0,
                    'store_charged_delivery_price' => 599,
                    'in_sale_refund_to_c_fee' => 0,
                    'items_discount' => 8184,
                    'delivery_discount' => 599,
                ],
                'promotions' => [
                    [
                        'promo_type' => 2,
                        'promo_discount' => 5184,
                        'shop_subside_price' => 5184,
                    ],
                    [
                        'promo_type' => 3,
                        'promo_discount' => 599,
                        'shop_subside_price' => 599,
                    ],
                    [
                        'promo_type' => 11,
                        'promo_discount' => 3000,
                        'shop_subside_price' => 0,
                    ],
                ],
                'order_items' => [
                    [
                        'app_item_id' => '1343',
                        'app_external_id' => '',
                        'name' => 'Combo Alpha Gyros',
                        'total_price' => 8390,
                        'sku_price' => 7300,
                        'amount' => 1,
                        'submit_refund_amount' => 0,
                        'remark' => '',
                        'sub_item_list' => [
                            [
                                'app_item_id' => '1373',
                                'app_external_id' => '',
                                'name' => 'Batata frita do Combo - Grande (400g)',
                                'total_price' => 1090,
                                'sku_price' => 1090,
                                'amount' => 1,
                                'submit_refund_amount' => 0,
                                'sub_item_list' => [],
                            ],
                        ],
                    ],
                    [
                        'app_item_id' => '1923',
                        'app_external_id' => '',
                        'name' => 'Alpha Gyros (Fraldinha)',
                        'total_price' => 9980,
                        'sku_price' => 4990,
                        'amount' => 2,
                        'submit_refund_amount' => 0,
                        'remark' => '',
                        'sub_item_list' => [],
                    ],
                ],
            ],
        ];

        $snapshot = $service->buildOrderDetailSyncPayload($provider, $payload);

        self::assertSame('orderDetailSync', $snapshot['type']);
        self::assertSame(18370, $snapshot['financial']['items_total']);
        self::assertSame(599, $snapshot['financial']['delivery_fee']);
        self::assertSame(510, $snapshot['financial']['service_fee']);
        self::assertSame(8783, $snapshot['financial']['discount_total']);
        self::assertSame(8184, $snapshot['financial']['store_discount_total']);
        self::assertSame(599, $snapshot['financial']['platform_discount_total']);
        self::assertSame(10696, $snapshot['financial']['customer_total']);
        self::assertSame(9587, $snapshot['financial']['weekly_settlement_amount']);
        self::assertSame(9587, $snapshot['financial']['store_receivable_total']);
        self::assertSame(10696, $snapshot['financial']['real_pay_total']);
        self::assertSame(10696, $snapshot['payment']['amount_paid']);
        self::assertSame('1', $snapshot['payment']['pay_type']);
        self::assertSame('1', $snapshot['payment']['pay_method']);
        self::assertSame('150', $snapshot['payment']['pay_channel']);
        self::assertSame('1', $snapshot['data']['delivery_type']);
        self::assertSame('5764672390386747126', (string) $snapshot['identifiers']['remote_order_id']);
    }

    public function testReconcileOrderAfterEntryDelegatesOnlyWhenEnabled(): void
    {
        $food99Service = new class extends \ControleOnline\Service\Food99Service {
            public array $calls = [];

            public function __construct()
            {
            }

            public function reconcileOrder(\ControleOnline\Entity\Order $order): array
            {
                $this->calls[] = $order;

                return [
                    'errno' => 0,
                    'errmsg' => 'ok',
                ];
            }
        };

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99OrderOperationsService::class, $service, 'food99Service', $food99Service);

        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 71699);

        $this->invokePrivateMethod($service, 'reconcileOrderAfterEntry', $order, true);
        $this->invokePrivateMethod($service, 'reconcileOrderAfterEntry', $order, false);

        self::assertCount(1, $food99Service->calls);
        self::assertSame($order, $food99Service->calls[0]);
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

    private function setEntityIdOnOrder(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(\ControleOnline\Entity\Order::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function setEntityIdOnProduct(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(\ControleOnline\Entity\Product::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
