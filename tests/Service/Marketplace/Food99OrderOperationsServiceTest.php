<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\Marketplace\Food99OrderOperationsService;
use PHPUnit\Framework\TestCase;

final class Food99OrderOperationsServiceTest extends TestCase
{
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
}
