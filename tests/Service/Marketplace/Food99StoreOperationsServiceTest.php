<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\People;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Marketplace\Food99StoreOperationsService;
use PHPUnit\Framework\TestCase;

final class Food99StoreOperationsServiceTest extends TestCase
{
    public function testFindFood99EntityByExtraDataUsesExtraDataServiceLookup(): void
    {
        $resolved = new \stdClass();
        $extraDataService = $this->createMock(\ControleOnline\Service\ExtraDataService::class);
        $extraDataService->expects(self::once())
            ->method('getEntityByExtraData')
            ->with(
                'Food99',
                'code',
                '3',
                People::class
            )
            ->willReturn($resolved);

        $service = (new \ReflectionClass(Food99StoreOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $this->invokePrivateMethod(
            $service,
            'findFood99EntityByExtraData',
            'People',
            'code',
            '3',
            People::class
        );

        self::assertSame($resolved, $result);
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
}
