<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Service\Marketplace\IfoodStoreOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class IfoodStoreOperationsServiceTest extends TestCase
{
    public function testExtractEventTimestampConvertsRemoteUtcToAppTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
            $method = (new \ReflectionObject($service))->getMethod('extractEventTimestamp');
            $method->setAccessible(true);

            $result = $method->invoke($service, [
                'createdAt' => '2026-05-29T23:37:20.421Z',
            ]);

            self::assertSame('2026-05-29 20:37:20', $result);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testPersistOrderIntegrationStateMergesIfoodBlockIntoOrderOtherInformations(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Order::class));

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $entityManagerProperty = (new \ReflectionObject($service))->getProperty('entityManager');
        $entityManagerProperty->setAccessible(true);
        $entityManagerProperty->setValue($service, $entityManager);

        $order = new Order();
        $order->setOtherInformations([
            'iFood' => [
                'existing' => 'keep',
            ],
        ]);

        $method = (new \ReflectionClass($service))->getMethod('persistOrderIntegrationState');
        $method->setAccessible(true);
        $method->invoke($service, $order, [
            'last_event_type' => 'PLACED',
            'merchant_id' => '123',
            ' ' => 'ignored',
        ]);

        $decoded = json_decode((string) $order->getOtherInformations(), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('iFood', $decoded);
        self::assertSame('keep', $decoded['iFood']['existing']);
        self::assertSame('PLACED', $decoded['iFood']['last_event_type']);
        self::assertSame('123', $decoded['iFood']['merchant_id']);
        self::assertArrayNotHasKey('', $decoded['iFood']);
    }
}
