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

    public function testStoredQuoteStateReadsCurrentIfoodContextSnapshot(): void
    {
        $service = (new \ReflectionClass(iFoodService::class))->newInstanceWithoutConstructor();
        $order = $this->createMock(Order::class);
        $order->method('getOtherInformations')->with(true)->willReturn((object) [
            'iFood' => (object) [
                'quote_state' => 'ready',
                'quote_id' => 'quote-123',
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
