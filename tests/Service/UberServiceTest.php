<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Cep;
use ControleOnline\Entity\City;
use ControleOnline\Entity\District;
use ControleOnline\Entity\State;
use ControleOnline\Entity\Street;
use ControleOnline\Service\UberService;
use PHPUnit\Framework\TestCase;

class UberServiceTest extends TestCase
{
    public function testBuildWebhookSignatureUsesHmacSha256(): void
    {
        $service = (new \ReflectionClass(UberService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            hash_hmac('sha256', '{"event":"delivery.updated"}', 'secret-key'),
            $service->buildWebhookSignature('{"event":"delivery.updated"}', 'secret-key')
        );
    }

    public function testBuildAddressPayloadIncludesFormattedAddressAndLocation(): void
    {
        $service = (new \ReflectionClass(UberService::class))->newInstanceWithoutConstructor();
        $address = $this->address();

        $payload = $this->invokePrivateMethod($service, 'buildAddressPayload', $address);

        self::assertSame('RUA TESTE, 123 - CENTRO - SAO PAULO - SP - 01234567', $payload['formatted_address']);
        self::assertSame('APTO 10', $payload['apt_floor_suite']);
        self::assertSame(-23.55, $payload['location']['latitude']);
        self::assertSame(-46.63, $payload['location']['longitude']);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function address(): Address
    {
        $state = new State();
        $state->setState('Sao Paulo');
        $state->setUf('SP');

        $city = new City();
        $city->setCity('Sao Paulo');
        $city->setState($state);

        $district = new District();
        $district->setDistrict('Centro');
        $district->setCity($city);

        $cep = new Cep();
        $cep->setCep(1234567);

        $street = new Street();
        $street->setStreet('Rua Teste');
        $street->setDistrict($district);
        $street->setCep($cep);

        $address = new Address();
        $address->setNumber(123);
        $address->setComplement('Apto 10');
        $address->setStreet($street);
        $address->setLatitude(-23.55);
        $address->setLongitude(-46.63);

        return $address;
    }
}
