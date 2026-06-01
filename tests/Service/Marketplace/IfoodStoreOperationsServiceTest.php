<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\IfoodClient;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\Marketplace\IfoodOrderOperationsService;
use ControleOnline\Service\Marketplace\IfoodStoreOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    public function testPerformReadyActionDelegatesToIfoodOrderOperationsService(): void
    {
        $order = $this->createStub(Order::class);
        $orderOperationsService = $this->createMock(IfoodOrderOperationsService::class);
        $orderOperationsService
            ->expects(self::once())
            ->method('performReadyAction')
            ->with($order)
            ->willReturn([
                'errno' => 0,
                'errmsg' => 'ok',
            ]);

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('has')
            ->willReturnMap([
                [IfoodOrderOperationsService::class, true],
                [\ControleOnline\Service\iFoodService::class, false],
            ]);
        $container
            ->method('get')
            ->with(IfoodOrderOperationsService::class)
            ->willReturn($orderOperationsService);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        self::assertSame([
            'errno' => 0,
            'errmsg' => 'ok',
        ], $service->performReadyAction($order));
    }

    public function testChangeStatusDelegatesToIfoodOrderOperationsService(): void
    {
        $order = $this->createStub(Order::class);
        $orderOperationsService = $this->createMock(IfoodOrderOperationsService::class);
        $orderOperationsService
            ->expects(self::once())
            ->method('changeStatus')
            ->with($order)
            ->willReturn(null);

        $service = (new \ReflectionClass(IfoodStoreOperationsService::class))->newInstanceWithoutConstructor();
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('has')
            ->willReturnMap([
                [IfoodOrderOperationsService::class, true],
                [\ControleOnline\Service\iFoodService::class, false],
            ]);
        $container
            ->method('get')
            ->with(IfoodOrderOperationsService::class)
            ->willReturn($orderOperationsService);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        self::assertNull($service->changeStatus($order));
    }

    public function testEmitStoreStatusChangeBroadcastsClosedNotificationOnFirstKnownClosedState(): void
    {
        $service = (new \ReflectionClass(IfoodStoreOperationsServiceProbe::class))->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(People::class));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $extraDataService = $this->createStub(ExtraDataService::class);
        $extraDataService
            ->method('getExtraDataValue')
            ->willReturn(null);

        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);
        $provider
            ->method('getName')
            ->willReturn('Mercado Central');
        $provider
            ->method('getOtherInformations')
            ->willReturn([]);
        $provider
            ->expects(self::once())
            ->method('setOtherInformations');

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $service->emitStoreStatusChangeValue($provider, 'merchant-123', 'UNAVAILABLE', false);

        self::assertCount(1, $service->capturedEvents);
        self::assertSame('store.closed', $service->capturedEvents[0][1][0]['event']);
        self::assertSame('Mercado Central foi fechada', $service->capturedEvents[0][1][0]['notificationHeader']);
        self::assertSame('Vendas do dia: R$ 123,45', $service->capturedEvents[0][1][0]['notificationSubheader']);
        self::assertSame('Fatura da semana: R$ 678,90', $service->capturedEvents[0][1][0]['notificationBody']);
    }

    public function testSyncIntegrationStateBroadcastsClosedNotificationOnFirstKnownClosedState(): void
    {
        $service = (new \ReflectionClass(IfoodStoreOperationsServiceProbe::class))->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $provider = $this->createStub(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);
        $provider
            ->method('getName')
            ->willReturn('Mercado Central');
        $provider
            ->method('getOtherInformations')
            ->willReturn([]);
        $provider
            ->method('setOtherInformations');

        $service->storedIntegrationState = [
            'merchant_id' => 'merchant-123',
            'merchant_status' => 'AVAILABLE',
            'online' => 1,
        ];

        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->with(self::isInstanceOf(People::class));

        $extraDataService = new class extends ExtraDataService {
            public function __construct()
            {
            }

            public function getExtraDataValue(
                string $context,
                string $entityName,
                int $entityId,
                string $fieldName = 'code',
                string $fieldType = 'text'
            ): ?string {
                return null;
            }

            public function upsertExtraDataValue(
                string $context,
                string $entityName,
                int $entityId,
                string $fieldName,
                mixed $value,
                string $fieldType = 'text',
                ?string $source = null
            ): void {
            }
        };

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getContent')
            ->with(false)
            ->willReturn(json_encode([
                'merchants' => [
                    [
                        'merchant_id' => 'merchant-123',
                        'name' => 'Mercado Central',
                        'status' => 'UNAVAILABLE',
                    ],
                ],
            ]));

        $ifoodClient = $this->createMock(IfoodClient::class);
        $ifoodClient
            ->method('isAuthAvailable')
            ->willReturn(true);
        $ifoodClient
            ->method('requestMerchantEndpoint')
            ->with('GET', '/merchants')
            ->willReturn($response);

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'ifoodClient', $ifoodClient);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $service->syncIntegrationState($provider);

        self::assertSame(0, $result['errno']);
        self::assertCount(1, $service->capturedEvents);
        self::assertSame('store.closed', $service->capturedEvents[0][1][0]['event']);
        self::assertSame('Mercado Central foi fechada', $service->capturedEvents[0][1][0]['notificationHeader']);
        self::assertSame('Fechada', $service->capturedEvents[0][1][0]['notificationStatusLabel']);
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setEntityId(object $object, int $id): void
    {
        $reflection = new \ReflectionObject($object);
        while ($reflection) {
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($object, $id);
                return;
            }

            $reflection = $reflection->getParentClass();
        }

        throw new \RuntimeException('Unable to set entity id in test.');
    }
}

final class IfoodStoreOperationsServiceProbe extends IfoodStoreOperationsService
{
    public array $capturedEvents = [];
    public array $storedIntegrationState = [];

    public function getStoredIntegrationState(People $provider, bool $includeAuthCheck = false): array
    {
        return $this->storedIntegrationState;
    }

    public function persistProviderIntegrationState(People $provider, array $fields): void
    {
        $this->storedIntegrationState = array_merge($this->storedIntegrationState, $fields);
    }

    public function emitStoreStatusChangeValue(
        People $provider,
        string $merchantId,
        string $merchantStatus,
        bool $currentOnline,
        bool $forceNotify = false
    ): void {
        $method = new \ReflectionMethod(IfoodStoreOperationsService::class, 'emitStoreStatusChange');
        $method->setAccessible(true);
        $method->invoke($this, $provider, $merchantId, $merchantStatus, $currentOnline, $forceNotify);
    }

    protected function broadcastCompanyWebsocketEvents(People $company, array $events): void
    {
        $this->capturedEvents[] = [$company, $events];
    }

    protected function sendStoreClosingNotifications(
        People $company,
        string $app,
        ?\DateTime $referenceDate = null
    ): array {
        return [
            'daily_sales_amount' => 123.45,
            'weekly_settlement_amount' => 678.90,
        ];
    }
}
