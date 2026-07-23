<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\ExtraData;
use ControleOnline\Entity\ExtraFields;
use ControleOnline\Entity\Order;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\Marketplace\IfoodOrderOperationsService;
use PHPUnit\Framework\TestCase;

class IfoodOrderOperationsServiceTest extends TestCase
{
    public function testResolveRemoteOrderIdUsesCanonicalExtraDataOnly(): void
    {
        $service = (new \ReflectionClass(IfoodOrderOperationsService::class))->newInstanceWithoutConstructor();

        $extraDataService = $this->createStub(ExtraDataService::class);
        $extraDataService->method('getExtraDataFromEntity')->willReturn([
            $this->createIfoodExtraDataStub('code', '3984', 20),
            $this->createIfoodExtraDataStub('id', '71759', 10),
        ]);
        $this->setObjectProperty($service, 'extraDataService', $extraDataService);

        $order = $this->createMock(Order::class);
        $order->expects(self::never())->method('getOtherInformations');

        self::assertSame(
            '71759',
            $this->invokePrivateMethod($service, 'resolveRemoteOrderId', $order)
        );
    }

    public function testResolveRemoteOrderIdDoesNotFallBackToStoredState(): void
    {
        $service = (new \ReflectionClass(IfoodOrderOperationsService::class))->newInstanceWithoutConstructor();

        $extraDataService = $this->createStub(ExtraDataService::class);
        $extraDataService->method('getExtraDataFromEntity')->willReturn([]);
        $this->setObjectProperty($service, 'extraDataService', $extraDataService);

        $order = $this->createMock(Order::class);
        $order->expects(self::never())->method('getOtherInformations');

        self::assertNull(
            $this->invokePrivateMethod($service, 'resolveRemoteOrderId', $order)
        );
    }

    private function createIfoodExtraDataStub(string $fieldName, string $value, int $id): ExtraData
    {
        $extraFields = $this->createStub(ExtraFields::class);
        $extraFields->method('getContext')->willReturn('iFood');
        $extraFields->method('getName')->willReturn($fieldName);

        $extraData = $this->createStub(ExtraData::class);
        $extraData->method('getExtraFields')->willReturn($extraFields);
        $extraData->method('getValue')->willReturn($value);
        $extraData->method('getId')->willReturn($id);

        return $extraData;
    }

    private function invokePrivateMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invokeArgs($object, $arguments);
    }

    private function setObjectProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
