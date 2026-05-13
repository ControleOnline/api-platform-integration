<?php

namespace ControleOnline\Integration\Tests\Service;

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

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }
}
