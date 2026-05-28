<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Marketplace\Food99OrderOperationsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    public function testSyncProviderWebhookReceiptStateDelegatesToFood99Service(): void
    {
        $food99Service = new class {
            public array $calls = [];

            public function syncProviderWebhookReceiptState(array $json): void
            {
                $this->calls[] = $json;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($food99Service);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $this->invokePrivateMethod(
            $service,
            'syncProviderWebhookReceiptState',
            [
                'type' => 'orderNew',
                'data' => ['order_id' => '5764671854459555132'],
            ]
        );

        self::assertSame(
            [
                [
                    'type' => 'orderNew',
                    'data' => ['order_id' => '5764671854459555132'],
                ],
            ],
            $food99Service->calls
        );
    }

    public function testExtractIncomingOrderIdentifiersDelegatesToFood99Service(): void
    {
        $food99Service = new class {
            public array $calls = [];

            public function extractIncomingOrderIdentifiers(array $json): array
            {
                $this->calls[] = $json;

                return [
                    'order_id' => '5764671854459555132',
                    'order_index' => '570004',
                    'order_code' => '570004',
                ];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($food99Service);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $result = $this->invokePrivateMethod(
            $service,
            'extractIncomingOrderIdentifiers',
            [
                'type' => 'orderNew',
                'data' => [
                    'order_id' => '5764671854459555132',
                ],
            ]
        );

        self::assertSame([
            'order_id' => '5764671854459555132',
            'order_index' => '570004',
            'order_code' => '570004',
        ], $result);
        self::assertSame(
            [
                [
                    'type' => 'orderNew',
                    'data' => [
                        'order_id' => '5764671854459555132',
                    ],
                ],
            ],
            $food99Service->calls
        );
    }

    public function testSyncStoreStatusWebhookDelegatesToFood99StoreService(): void
    {
        $storeService = new class {
            public array $calls = [];

            public function syncStoreStatusWebhook(array $json): void
            {
                $this->calls[] = $json;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($storeService);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $this->invokePrivateMethod(
            $service,
            'syncStoreStatusWebhook',
            [
                'type' => 'shopStatus',
                'data' => ['shop_id' => '3'],
            ]
        );

        self::assertSame(
            [
                [
                    'type' => 'shopStatus',
                    'data' => ['shop_id' => '3'],
                ],
            ],
            $storeService->calls
        );
    }

    public function testSyncFood99ClientDataDelegatesToFood99PeopleService(): void
    {
        $client = new \ControleOnline\Entity\People();
        $peopleService = new class($client) {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\People $client)
            {
            }

            public function syncFood99ClientData(
                \ControleOnline\Entity\People $client,
                \ControleOnline\Entity\People $provider,
                array $address,
                string $remoteClientId = ''
            ): \ControleOnline\Entity\People {
                $this->calls[] = [$client, $provider, $address, $remoteClientId];

                return $this->client;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($peopleService);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $provider = new \ControleOnline\Entity\People();
        $result = $this->invokePrivateMethod(
            $service,
            'syncFood99ClientData',
            $client,
            $provider,
            ['street_name' => 'Rua A'],
            '123'
        );

        self::assertSame($client, $result);
        self::assertCount(1, $peopleService->calls);
        self::assertSame($client, $peopleService->calls[0][0]);
        self::assertSame($provider, $peopleService->calls[0][1]);
        self::assertSame(['street_name' => 'Rua A'], $peopleService->calls[0][2]);
        self::assertSame('123', $peopleService->calls[0][3]);
    }

    public function testResolveOrderClientDelegatesToFood99Service(): void
    {
        $resolvedClient = new \ControleOnline\Entity\People();
        $food99Service = new class($resolvedClient) {
            public array $calls = [];

            public function __construct(private \ControleOnline\Entity\People $client)
            {
            }

            public function resolveOrderClient(
                \ControleOnline\Entity\People $provider,
                array $address,
                array $payload,
                string $orderId
            ): \ControleOnline\Entity\People {
                $this->calls[] = [$provider, $address, $payload, $orderId];

                return $this->client;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($food99Service);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $provider = new \ControleOnline\Entity\People();
        $result = $this->invokePrivateMethod(
            $service,
            'resolveOrderClient',
            $provider,
            ['street_name' => 'Rua A'],
            ['data' => ['order_id' => '5764671854459555132']],
            '5764671854459555132'
        );

        self::assertSame($resolvedClient, $result);
        self::assertCount(1, $food99Service->calls);
        self::assertSame($provider, $food99Service->calls[0][0]);
        self::assertSame(['street_name' => 'Rua A'], $food99Service->calls[0][1]);
        self::assertSame(['data' => ['order_id' => '5764671854459555132']], $food99Service->calls[0][2]);
        self::assertSame('5764671854459555132', $food99Service->calls[0][3]);
    }

    public function testDiscoveryClientUsesInjectedExtraDataServiceLookup(): void
    {
        $resolved = new \ControleOnline\Entity\People();
        $extraDataService = $this->createMock(\ControleOnline\Service\ExtraDataService::class);
        $extraDataService->expects(self::once())
            ->method('getEntityByExtraData')
            ->with(
                'Food99',
                'code',
                'remote-1',
                \ControleOnline\Entity\People::class
            )
            ->willReturn($resolved);

        $peopleService = new class {
            public array $calls = [];

            public function resolveFood99RemoteClientId(array $address, array $payload = []): string
            {
                $this->calls[] = [$address, $payload];

                return 'remote-1';
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($peopleService);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'extraDataService', $extraDataService);

        $result = $this->invokePrivateMethod(
            $service,
            'discoveryClient',
            ['street_name' => 'Rua A'],
            ['data' => ['order_id' => '5764671854459555132']]
        );

        self::assertSame($resolved, $result);
        self::assertSame([
            [
                ['street_name' => 'Rua A'],
                ['data' => ['order_id' => '5764671854459555132']],
            ],
        ], $peopleService->calls);
    }

    public function testStoredOrderIntegrationStateAndConfirmResultDelegateToFood99Service(): void
    {
        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 901);

        $food99Service = new class {
            public array $calls = [];

            public function getStoredOrderIntegrationState(\ControleOnline\Entity\Order $order): array
            {
                $this->calls[] = ['state', $order->getId()];

                return [
                    'food99_id' => '5764671883811294471',
                    'confirm_errno' => '0',
                ];
            }

            public function persistOrderConfirmResult(\ControleOnline\Entity\Order $order, ?array $response): array
            {
                $this->calls[] = ['confirm', $order->getId(), $response];

                return $response ?? [
                    'errno' => 10001,
                    'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.',
                    'data' => [],
                ];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($food99Service);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        $storedState = $this->invokePrivateMethod(
            $service,
            'getStoredOrderIntegrationState',
            $order
        );

        $confirmResult = $this->invokePrivateMethod(
            $service,
            'persistOrderConfirmResult',
            $order,
            ['errno' => 0, 'errmsg' => 'ok', 'data' => []]
        );

        self::assertSame([
            'food99_id' => '5764671883811294471',
            'confirm_errno' => '0',
        ], $storedState);
        self::assertSame(['errno' => 0, 'errmsg' => 'ok', 'data' => []], $confirmResult);
        self::assertSame([
            ['state', 901],
            ['confirm', 901, ['errno' => 0, 'errmsg' => 'ok', 'data' => []]],
        ], $food99Service->calls);
    }

    public function testThrowIfConfirmationShouldRetryIgnoresUnavailableConfirmationResponse(): void
    {
        $order = new \ControleOnline\Entity\Order();
        $this->setEntityIdOnOrder($order, 902);

        $service = (new \ReflectionClass(Food99OrderOperationsService::class))->newInstanceWithoutConstructor();

        $this->invokePrivateMethod(
            $service,
            'throwIfConfirmationShouldRetry',
            ['errno' => 10001, 'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.', 'data' => []],
            '5764671883811294471',
            $order
        );

        self::assertTrue(true);
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

    private function setEntityIdOnOrder(object $entity, int $id): void
    {
        $property = new \ReflectionProperty(\ControleOnline\Entity\Order::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
