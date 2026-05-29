<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\Food99Client;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\Marketplace\Food99StoreOperationsService;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('providePortalEndpointDelegations')]
    public function testPortalEndpointDelegations(
        string $serviceMethod,
        array $arguments,
        string $expectedMethod,
        string $expectedUri
    ): void {
        $fakeClient = new FakeFood99Client();
        $service = $this->newServiceWithFakeFood99Client($fakeClient);

        if ($serviceMethod === 'getAuthorizationPage') {
            $domainService = $this->createMock(DomainService::class);
            $domainService->expects(self::once())
                ->method('getMainDomain')
                ->willReturn('api.custom-domain.test');
            $this->setObjectProperty(DefaultFoodService::class, $service, 'domainService', $domainService);
        }

        $response = $service->{$serviceMethod}(...$arguments);

        $expectedPayload = $arguments[0] ?? [];
        if ($serviceMethod === 'getAuthorizationPage') {
            $expectedPayload['app_domain'] = 'api.custom-domain.test';
        }

        self::assertCount(1, $fakeClient->appCalls);
        self::assertSame([], $fakeClient->storeCalls);
        self::assertSame($expectedMethod, $fakeClient->appCalls[0]['method']);
        self::assertSame($expectedUri, $fakeClient->appCalls[0]['uri']);
        self::assertSame($expectedPayload, $fakeClient->appCalls[0]['payload']);
        self::assertSame([
            'errno' => 0,
            'data' => [
                'method' => $expectedMethod,
                'uri' => $expectedUri,
                'payload' => $expectedPayload,
            ],
        ], $response);
    }

    public function testUnbindStoreDelegatesToPortalUnbindEndpoint(): void
    {
        $fakeClient = new FakeFood99Client();
        $service = $this->newServiceWithFakeFood99Client($fakeClient);
        $provider = $this->newTestPeople(3);

        $response = $service->unbindStore($provider, [
            'shop_id' => '5764612470103345070',
        ]);

        self::assertCount(1, $fakeClient->appCalls);
        self::assertSame([], $fakeClient->storeCalls);
        self::assertSame('POST', $fakeClient->appCalls[0]['method']);
        self::assertSame('/shop_center/v1/authorize/unbind', $fakeClient->appCalls[0]['uri']);
        self::assertSame([
            'shop_id' => '5764612470103345070',
        ], $fakeClient->appCalls[0]['payload']);
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

    private function newServiceWithFakeFood99Client(FakeFood99Client $fakeClient): Food99StoreOperationsService
    {
        $service = (new \ReflectionClass(Food99StoreOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(DefaultFoodService::class, $service, 'food99Client', $fakeClient);

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

final class FakeFood99Client extends Food99Client
{
    /**
     * @var array<int, array{method:string, uri:string, payload:array}>
     */
    public array $appCalls = [];

    /**
     * @var array<int, array{method:string, uri:string, payload:array, provider_id:int|null}>
     */
    public array $storeCalls = [];

    public function __construct()
    {
    }

    public function callAppEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
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

    public function callStoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
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

    public function callStoreMultipartEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
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
