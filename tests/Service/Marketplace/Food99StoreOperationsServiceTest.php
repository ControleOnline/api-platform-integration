<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\People;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\Marketplace\Food99StoreOperationsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    #[DataProvider('providePortalEndpointDelegations')]
    public function testPortalEndpointDelegations(
        string $serviceMethod,
        array $arguments,
        string $expectedMethod,
        string $expectedUri
    ): void {
        $fakeService = new FakeFood99Service();
        $service = $this->newServiceWithFakeFood99Service($fakeService);

        $response = $service->{$serviceMethod}(...$arguments);

        self::assertCount(1, $fakeService->appCalls);
        self::assertSame([], $fakeService->storeCalls);
        self::assertSame($expectedMethod, $fakeService->appCalls[0]['method']);
        self::assertSame($expectedUri, $fakeService->appCalls[0]['uri']);
        self::assertSame($arguments[0] ?? [], $fakeService->appCalls[0]['payload']);
        self::assertSame([
            'errno' => 0,
            'data' => [
                'method' => $expectedMethod,
                'uri' => $expectedUri,
                'payload' => $arguments[0] ?? [],
            ],
        ], $response);
    }

    public function testUnbindStoreDelegatesToPortalUnbindEndpoint(): void
    {
        $fakeService = new FakeFood99Service();
        $service = $this->newServiceWithFakeFood99Service($fakeService);
        $provider = $this->newTestPeople(3);

        $response = $service->unbindStore($provider, [
            'shop_id' => '5764612470103345070',
        ]);

        self::assertCount(1, $fakeService->appCalls);
        self::assertSame([], $fakeService->storeCalls);
        self::assertSame('POST', $fakeService->appCalls[0]['method']);
        self::assertSame('/shop_center/v1/authorize/unbind', $fakeService->appCalls[0]['uri']);
        self::assertSame([
            'shop_id' => '5764612470103345070',
        ], $fakeService->appCalls[0]['payload']);
        self::assertSame([
            'errno' => 0,
            'data' => [
                'method' => 'POST',
                'uri' => '/shop_center/v1/authorize/unbind',
                'payload' => [
                    'shop_id' => '5764612470103345070',
                ],
            ],
        ], $response);
    }

    public static function providePortalEndpointDelegations(): iterable
    {
        yield 'authorization page' => [
            'serviceMethod' => 'getAuthorizationPage',
            'arguments' => [[
                'app_shop_id' => '3',
                'provider_id' => '3',
            ]],
            'expectedMethod' => 'POST',
            'expectedUri' => '/shop_center/v1/authorize/get_url',
        ];

        yield 'bind store' => [
            'serviceMethod' => 'bindStore',
            'arguments' => [[
                'app_shop_id' => '3',
                'shop_id' => '5764612470103345070',
            ]],
            'expectedMethod' => 'POST',
            'expectedUri' => '/shop_center/v1/authorize/bind',
        ];

        yield 'list authorized stores' => [
            'serviceMethod' => 'listAuthorizedStores',
            'arguments' => [[
                'page' => 1,
            ]],
            'expectedMethod' => 'GET',
            'expectedUri' => '/shop_center/v1/authorize/list',
        ];

        yield 'list bind stores' => [
            'serviceMethod' => 'listBindStores',
            'arguments' => [[
                'page' => 1,
            ]],
            'expectedMethod' => 'GET',
            'expectedUri' => '/shop_center/v1/shop/list',
        ];
    }

    private function newServiceWithFakeFood99Service(FakeFood99Service $fakeService): Food99StoreOperationsService
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->with(Food99Service::class)
            ->willReturn(true);
        $container->method('get')
            ->with(Food99Service::class)
            ->willReturn($fakeService);

        $service = (new \ReflectionClass(Food99StoreOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'container', $container);

        return $service;
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

    private function newTestPeople(int $id): People
    {
        return new class($id) extends People {
            public function __construct(
                private readonly int $idValue,
            ) {}

            public function getId(): ?int
            {
                return $this->idValue;
            }
        };
    }
}

final class FakeFood99Service
{
    /**
     * @var array<int, array{method:string, uri:string, payload:array}>
     */
    public array $appCalls = [];

    /**
     * @var array<int, array{method:string, uri:string, payload:array, provider_id:int|null}>
     */
    public array $storeCalls = [];

    public function call99AppEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
    {
        $this->appCalls[] = [
            'method' => $method,
            'uri' => $uri,
            'payload' => $payload,
        ];

        return [
            'errno' => 0,
            'data' => [
                'method' => $method,
                'uri' => $uri,
                'payload' => $payload,
            ],
        ];
    }

    public function call99StoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $this->storeCalls[] = [
            'method' => $method,
            'uri' => $uri,
            'payload' => $payload,
            'provider_id' => $provider?->getId(),
        ];

        return [
            'errno' => 0,
            'data' => [
                'method' => $method,
                'uri' => $uri,
                'payload' => $payload,
                'provider_id' => $provider?->getId(),
            ],
        ];
    }
}
