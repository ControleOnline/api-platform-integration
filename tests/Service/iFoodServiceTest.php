<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Cep;
use ControleOnline\Entity\City;
use ControleOnline\Entity\District;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\State;
use ControleOnline\Entity\Street;
use ControleOnline\Service\iFoodService;
use PHPUnit\Framework\TestCase;

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

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
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
