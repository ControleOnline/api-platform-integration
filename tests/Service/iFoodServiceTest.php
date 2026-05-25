<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Cep;
use ControleOnline\Entity\City;
use ControleOnline\Entity\District;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\State;
use ControleOnline\Entity\Street;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\iFoodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class iFoodServiceTest extends TestCase
{
    public function testWebhookMerchantStatusIsNormalizedToAvailabilityStates(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            'OPEN',
            $this->invokePrivateMethod($service, 'resolveWebhookMerchantStatus', [
                'status' => 'OPEN',
            ])
        );

        self::assertSame(
            'CLOSED',
            $this->invokePrivateMethod($service, 'resolveWebhookMerchantStatus', [
                'merchantStatus' => 'CLOSED',
            ])
        );
    }

    public function testStoreStatusWebhookEventDetectionRequiresMerchantWithoutOrder(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        self::assertTrue($this->invokePrivateMethod(
            $service,
            'isStoreStatusWebhookEvent',
            [
                'merchantId' => '123',
                'status' => 'AVAILABLE',
            ],
            'AVAILABLE'
        ));

        self::assertFalse($this->invokePrivateMethod(
            $service,
            'isStoreStatusWebhookEvent',
            [
                'merchantId' => '123',
                'orderId' => '999',
                'status' => 'AVAILABLE',
            ],
            'AVAILABLE'
        ));
    }

    public function testOnlyEntryEventsCanCreateLocalIfoodOrder(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        self::assertTrue($this->invokePrivateMethod($service, 'shouldCreateOrderFromEvent', 'PLACED'));
        self::assertFalse($this->invokePrivateMethod($service, 'shouldCreateOrderFromEvent', 'CONFIRMED'));
        self::assertFalse($this->invokePrivateMethod($service, 'shouldCreateOrderFromEvent', 'READY_TO_PICKUP'));
        self::assertFalse($this->invokePrivateMethod($service, 'shouldCreateOrderFromEvent', 'CONCLUDED'));
    }

    public function testStoredQuoteStateReadsCurrentIfoodContextSnapshot(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $order = $this->createMock(Order::class);
        $order->method('getOtherInformations')->with(true)->willReturn((object) [
            'iFood' => (object) [
                'quote' => (object) [
                    'quote_state' => 'ready',
                    'quote_id' => 'quote-123',
                ],
            ],
        ]);

        self::assertSame(
            [
                'quote_state' => 'ready',
                'quote_id' => 'quote-123',
            ],
            $this->invokePrivateMethod($service, 'getStoredIfoodQuoteState', $order)
        );
    }

    public function testStoredQuoteStateFallsBackToLegacyLogisticsSnapshot(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $order = $this->createMock(Order::class);
        $order->method('getOtherInformations')->with(true)->willReturn((object) [
            'logistics' => (object) [
                'quote_state' => 'selected',
                'quote_id' => 'quote-legacy',
            ],
        ]);

        self::assertSame(
            [
                'quote_state' => 'selected',
                'quote_id' => 'quote-legacy',
            ],
            $this->invokePrivateMethod($service, 'getStoredIfoodQuoteState', $order)
        );
    }

    public function testShippingAddressPayloadUsesStringStreetNumber(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $cep = $this->createStub(Cep::class);
        $cep->method('getCep')->willReturn('07063080');
        $state = $this->createStub(State::class);
        $state->method('getUf')->willReturn('SP');
        $state->method('getState')->willReturn('Sao Paulo');
        $city = $this->createStub(City::class);
        $city->method('getCity')->willReturn('Guarulhos');
        $city->method('getState')->willReturn($state);
        $district = $this->createStub(District::class);
        $district->method('getDistrict')->willReturn('Jardim Alianca');
        $district->method('getCity')->willReturn($city);
        $street = $this->createStub(Street::class);
        $street->method('getStreet')->willReturn('Rua Antonio Rabello');
        $street->method('getDistrict')->willReturn($district);
        $street->method('getCep')->willReturn($cep);
        $address = $this->createStub(Address::class);
        $address->method('getStreet')->willReturn($street);
        $address->method('getNumber')->willReturn(22);
        $address->method('getComplement')->willReturn(null);
        $address->method('getLocator')->willReturn(null);
        $address->method('getNickname')->willReturn('Default');
        $address->method('getLatitude')->willReturn(0.0);
        $address->method('getLongitude')->willReturn(0.0);

        $payload = $this->invokePrivateMethod($service, 'buildIfoodShippingAddressPayload', $address);

        self::assertSame('22', $payload['streetNumber']);
        self::assertSame('07063080', $payload['postalCode']);
        self::assertSame('BR', $payload['country']);
    }

    public function testQuoteRouteValidationRejectsEqualPickupAndDropoffAddresses(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $pickupAddress = $this->createComparableAddressStub();
        $dropoffAddress = $this->createComparableAddressStub();

        self::assertSame(
            'Endereco de coleta e entrega nao podem ser iguais.',
            $this->invokePrivateMethod($service, 'validateIfoodQuoteRoute', $pickupAddress, $dropoffAddress)
        );
    }

    public function testQuoteRouteValidationRejectsIncompleteDropoffAddress(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $pickupAddress = $this->createComparableAddressStub();
        $dropoffAddress = $this->createIncompleteAddressStub();

        self::assertSame(
            'Pedido sem endereco de entrega valido.',
            $this->invokePrivateMethod($service, 'validateIfoodQuoteRoute', $pickupAddress, $dropoffAddress)
        );
    }

    public function testResolveOrderDetailsUsesEventSnapshotWithoutRemoteLookup(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $this->setStaticProperty(DefaultFoodService::class, 'logger', $this->createNullLoggerStub());
        $this->setStaticProperty(DefaultFoodService::class, 'app', 'iFood');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $this->setObjectProperty($service, 'httpClient', $httpClient);

        $event = [
            'orderId' => 'ifood-order-1',
            'merchantId' => 'merchant-1',
            'order' => [
                'displayId' => 'ABC-123',
                'customer' => [
                    'name' => 'Cliente Teste',
                ],
                'delivery' => [
                    'deliveryAddress' => [
                        'formattedAddress' => 'Rua A, 123',
                    ],
                ],
            ],
        ];

        $orderDetails = $this->invokePrivateMethod($service, 'resolveOrderDetailsFromEvent', 'ifood-order-1', $event, null);

        self::assertSame('ABC-123', $orderDetails['displayId']);
        self::assertSame('Cliente Teste', $orderDetails['customer']['name']);
        self::assertSame('Rua A, 123', $orderDetails['delivery']['deliveryAddress']['formattedAddress']);
    }

    public function testResolveOrderDetailsUsesStoredSnapshotAndPersistsItBack(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $this->setStaticProperty(DefaultFoodService::class, 'logger', $this->createNullLoggerStub());
        $this->setStaticProperty(DefaultFoodService::class, 'app', 'iFood');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $this->setObjectProperty($service, 'httpClient', $httpClient);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Order::class));
        $this->setObjectProperty($service, 'entityManager', $entityManager);

        $storedEvent = (object) [
            'iFood' => (object) [
                'latest_event_type' => 'PLACED',
                'PLACED' => [
                    'orderId' => 'ifood-order-2',
                    'merchantId' => 'merchant-2',
                    'order' => [
                        'displayId' => 'XYZ-987',
                        'customer' => [
                            'name' => 'Cliente Cache',
                        ],
                        'delivery' => [
                            'deliveryAddress' => [
                                'formattedAddress' => 'Av. Cache, 99',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $order = $this->createMock(Order::class);
        $order->expects(self::exactly(2))
            ->method('getOtherInformations')
            ->with(true)
            ->willReturn($storedEvent);
        $order->expects(self::once())
            ->method('setOtherInformations')
            ->with(self::callback(static function (array $otherInformations): bool {
                $event = $otherInformations['iFood']['PLACED'] ?? null;

                return is_array($event)
                    && isset($event['order']['displayId'])
                    && $event['order']['displayId'] === 'XYZ-987'
                    && isset($event['order_details_cached_at']);
            }))
            ->willReturnSelf();

        $orderDetails = $this->invokePrivateMethod(
            $service,
            'resolveOrderDetailsFromEvent',
            'ifood-order-2',
            ['orderId' => 'ifood-order-2', 'merchantId' => 'merchant-2'],
            $order
        );

        self::assertSame('XYZ-987', $orderDetails['displayId']);
        self::assertSame('Cliente Cache', $orderDetails['customer']['name']);
        self::assertSame('Av. Cache, 99', $orderDetails['delivery']['deliveryAddress']['formattedAddress']);
    }

    public function testResolveOrderDetailsForExistingOrderDoesNotFetchWhenSnapshotIsMissing(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $this->setStaticProperty(DefaultFoodService::class, 'logger', $this->createNullLoggerStub());
        $this->setStaticProperty(DefaultFoodService::class, 'app', 'iFood');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $this->setObjectProperty($service, 'httpClient', $httpClient);

        $order = $this->createMock(Order::class);
        $order->expects(self::exactly(2))
            ->method('getOtherInformations')
            ->willReturn((object) [
                'iFood' => (object) [
                    'latest_event_type' => 'CONFIRMED',
                    'CONFIRMED' => [
                        'orderId' => 'ifood-order-3',
                    ],
                ],
            ]);
        $order->expects(self::never())->method('setOtherInformations');

        $orderDetails = $this->invokePrivateMethod(
            $service,
            'resolveOrderDetailsFromEvent',
            'ifood-order-3',
            ['orderId' => 'ifood-order-3', 'fullCode' => 'CONFIRMED'],
            $order
        );

        self::assertSame([], $orderDetails);
    }

    public function testPersistIfoodQuoteStateKeepsOrderDetailsSnapshot(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $this->setObjectProperty($service, 'entityManager', $entityManager);

        $order = $this->createMock(Order::class);
        $order->method('getOtherInformations')->willReturn((object) [
            'iFood' => (object) [
                'latest_event_type' => 'PLACED',
                'PLACED' => [
                    'order' => [
                        'displayId' => 'SNAP-1',
                    ],
                ],
            ],
        ]);
        $order->expects(self::once())
            ->method('setOtherInformations')
            ->with(self::callback(static function (array $otherInformations): bool {
                return ($otherInformations['iFood']['PLACED']['order']['displayId'] ?? null) === 'SNAP-1'
                    && ($otherInformations['iFood']['quote']['quote_id'] ?? null) === 'quote-456'
                    && ($otherInformations['logistics']['quote_id'] ?? null) === 'quote-456';
            }));
        $order->method('setAlterDate')->willReturnSelf();
        $order->method('setPrice')->willReturnSelf();

        $this->invokePrivateMethod(
            $service,
            'persistIfoodQuoteState',
            $order,
            ['quote_id' => 'quote-456'],
            ['quote_id' => 'quote-456']
        );
    }

    public function testIfoodBenefitSnapshotSeparatesSponsorAndDeliveryTarget(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $snapshot = $this->invokePrivateMethod($service, 'extractOrderBenefitSnapshot', [
            'benefits' => [
                [
                    'value' => 7.0,
                    'target' => 'ITEM',
                    'campaign' => ['code' => 'CUPOMITEM'],
                    'sponsorshipValues' => [
                        ['name' => 'IFOOD', 'value' => 2.0],
                        ['name' => 'MERCHANT', 'value' => 3.0],
                        ['name' => 'CHAIN', 'value' => 2.0],
                    ],
                ],
                [
                    'value' => 5.0,
                    'target' => 'DELIVERY_FEE',
                    'voucherCode' => 'ENTREGA',
                    'sponsorshipValues' => [
                        ['name' => 'IFOOD', 'value' => 1.5],
                        ['name' => 'MERCHANT', 'value' => 2.5],
                        ['name' => 'EXTERNAL', 'value' => 1.0],
                    ],
                ],
            ],
        ]);

        self::assertSame('12', $snapshot['discount_total']);
        self::assertSame('6.5', $snapshot['ifood_subsidy']);
        self::assertSame('5.5', $snapshot['merchant_subsidy']);
        self::assertSame('2.5', $snapshot['store_delivery_discount_total']);
        self::assertSame('3', $snapshot['store_non_delivery_discount_total']);
        self::assertSame('2.5', $snapshot['platform_delivery_discount_total']);
        self::assertSame('4', $snapshot['platform_non_delivery_discount_total']);
        self::assertSame('CUPOMITEM, ENTREGA', $snapshot['voucher_code']);
    }

    public function testIfoodAdditionalFeeSnapshotUsesMerchantLiabilityOnly(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();

        $snapshot = $this->invokePrivateMethod($service, 'extractAdditionalFeeSnapshot', [
            [
                'type' => 'SMALL_ORDER_FEE',
                'value' => 10.0,
                'liabilities' => [
                    ['name' => 'IFOOD', 'percentage' => 60],
                    ['name' => 'MERCHANT', 'percentage' => 40],
                ],
            ],
            [
                'type' => 'MERCHANT_SUBSCRIPTION_FEE',
                'value' => 5.0,
                'liabilities' => [
                    ['name' => 'IFOOD', 'percentage' => 100],
                ],
            ],
            [
                'type' => 'OTHER_FEE',
                'value' => 7.0,
            ],
        ]);

        self::assertSame(22.0, $snapshot['total']);
        self::assertSame(4.0, $snapshot['merchant_total']);
        self::assertSame(0.0, $snapshot['merchant_service_fee']);
        self::assertSame(4.0, $snapshot['merchant_small_order_fee']);
        self::assertSame(0.0, $snapshot['merchant_meal_top_up_fee']);
    }

    public function testIfoodOrderHomologationSnapshotBuildsStoreReceivableFromMerchantRevenue(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $loggerService = $this->createMock(\ControleOnline\Service\LoggerService::class);
        $loggerService->method('getLogger')->willReturn($this->createNullLoggerStub());
        $this->setObjectProperty($service, 'loggerService', $loggerService);

        $order = $this->createMock(Order::class);
        $order->method('getOtherInformations')->willReturn((object) [
            'iFood' => [
                'latest_event_type' => 'PLACED',
                'PLACED' => [
                    'orderId' => 'ifood-order-financial',
                    'order' => [
                        'displayId' => 'FIN-1',
                        'total' => [
                            'subTotal' => 100.0,
                            'deliveryFee' => 10.0,
                            'additionalFees' => 7.0,
                            'benefits' => 15.0,
                            'orderAmount' => 102.0,
                        ],
                        'additionalFees' => [
                            [
                                'type' => 'SMALL_ORDER_FEE',
                                'value' => 5.0,
                                'liabilities' => [
                                    ['name' => 'IFOOD', 'percentage' => 100],
                                ],
                            ],
                            [
                                'type' => 'MERCHANT_SUBSCRIPTION_FEE',
                                'value' => 2.0,
                                'liabilities' => [
                                    ['name' => 'MERCHANT', 'percentage' => 50],
                                    ['name' => 'IFOOD', 'percentage' => 50],
                                ],
                            ],
                        ],
                        'benefits' => [
                            [
                                'value' => 11.0,
                                'target' => 'ITEM',
                                'sponsorshipValues' => [
                                    ['name' => 'MERCHANT', 'value' => 11.0],
                                ],
                            ],
                            [
                                'value' => 4.0,
                                'target' => 'DELIVERY_FEE',
                                'sponsorshipValues' => [
                                    ['name' => 'IFOOD', 'value' => 4.0],
                                ],
                            ],
                        ],
                        'payments' => [
                            'prepaid' => 102.0,
                            'pending' => 0.0,
                            'methods' => [
                                [
                                    'method' => 'CREDIT',
                                    'type' => 'ONLINE',
                                    'value' => 102.0,
                                ],
                            ],
                        ],
                        'delivery' => [
                            'deliveredBy' => 'IFOOD',
                        ],
                    ],
                ],
            ],
        ]);

        $snapshot = $service->getOrderHomologationSnapshot($order);

        self::assertSame(102.0, $snapshot['financial']['customer_total']);
        self::assertSame(117.0, $snapshot['financial']['subtotal_before_discounts']);
        self::assertSame(88.0, $snapshot['financial']['store_receivable_total']);
        self::assertSame(1.0, $snapshot['financial']['service_fee']);
        self::assertSame(0.0, $snapshot['financial']['small_order_fee']);
        self::assertSame(7.0, $snapshot['financial']['additional_fees_total']);
        self::assertSame(1.0, $snapshot['financial']['merchant_additional_fee_total']);
        self::assertSame(11.0, $snapshot['financial']['store_non_delivery_discount_total']);
        self::assertSame(4.0, $snapshot['financial']['platform_delivery_discount_total']);
        self::assertSame(4.0, $snapshot['financial']['delivery_discount_total']);
        self::assertTrue($snapshot['payment']['is_paid_online']);
        self::assertTrue($snapshot['delivery']['is_platform_delivery']);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function setObjectProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private function createNullLoggerStub(): object
    {
        return new class() {
            public function info(mixed ...$arguments): void
            {
            }

            public function warning(mixed ...$arguments): void
            {
            }

            public function error(mixed ...$arguments): void
            {
            }
        };
    }

    private function createComparableAddressStub(): Address
    {
        $state = $this->createStub(State::class);
        $state->method('getUf')->willReturn('SP');
        $state->method('getState')->willReturn('Sao Paulo');

        $city = $this->createStub(City::class);
        $city->method('getCity')->willReturn('Guarulhos');
        $city->method('getState')->willReturn($state);

        $district = $this->createStub(District::class);
        $district->method('getDistrict')->willReturn('Jardim Aida');
        $district->method('getCity')->willReturn($city);

        $cep = $this->createStub(Cep::class);
        $cep->method('getCep')->willReturn('07060000');

        $street = $this->createStub(Street::class);
        $street->method('getStreet')->willReturn('Alameda Yayá');
        $street->method('getDistrict')->willReturn($district);
        $street->method('getCep')->willReturn($cep);

        $address = $this->createStub(Address::class);
        $address->method('getStreet')->willReturn($street);
        $address->method('getNumber')->willReturn(424);
        $address->method('getComplement')->willReturn(null);
        $address->method('getLatitude')->willReturn(-23.4434);
        $address->method('getLongitude')->willReturn(-46.5123);

        return $address;
    }

    private function createIncompleteAddressStub(): Address
    {
        $address = $this->createStub(Address::class);
        $address->method('getStreet')->willReturn(null);
        $address->method('getNumber')->willReturn(null);
        $address->method('getComplement')->willReturn(null);
        $address->method('getLatitude')->willReturn(0.0);
        $address->method('getLongitude')->willReturn(0.0);

        return $address;
    }
}
