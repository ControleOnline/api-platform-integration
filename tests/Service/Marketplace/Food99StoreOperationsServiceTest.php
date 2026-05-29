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
        $previousDomainEnv = $this->captureFood99DomainEnvironment();
        $fakeClient = new FakeFood99Client();
        $service = $this->newServiceWithFakeFood99Client($fakeClient);

        if (in_array($serviceMethod, ['getAuthorizationPage', 'bindStore', 'listAuthorizedStores', 'listBindStores'], true)) {
            $this->clearFood99DomainEnvironment();
            $domainService = $this->createMock(DomainService::class);
            $domainService->expects(self::once())
                ->method('getDomain')
                ->willReturn('https://shop.custom-domain.test/path');
            $this->setObjectProperty(DefaultFoodService::class, $service, 'domainService', $domainService);
        }

        try {
            $response = $service->{$serviceMethod}(...$arguments);

            $expectedPayload = $arguments[0] ?? [];
            if (in_array($serviceMethod, ['getAuthorizationPage', 'bindStore', 'listAuthorizedStores', 'listBindStores'], true)) {
                $expectedPayload['app_domain'] = 'shop.custom-domain.test';
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
        } finally {
            $this->restoreFood99DomainEnvironment($previousDomainEnv);
        }
    }

    public function testUnbindStoreDelegatesToPortalUnbindEndpoint(): void
    {
        $previousDomainEnv = $this->captureFood99DomainEnvironment();
        $this->clearFood99DomainEnvironment();
        $fakeClient = new FakeFood99Client();
        $service = $this->newServiceWithFakeFood99Client($fakeClient);
        $provider = $this->newTestPeople(3);

        $domainService = $this->createMock(DomainService::class);
        $domainService->expects(self::once())
            ->method('getDomain')
            ->willReturn('shop.custom-domain.test');
        $this->setObjectProperty(DefaultFoodService::class, $service, 'domainService', $domainService);

        try {
            $response = $service->unbindStore($provider, [
                'shop_id' => '5764612470103345070',
            ]);

            self::assertCount(1, $fakeClient->appCalls);
            self::assertSame([], $fakeClient->storeCalls);
            self::assertSame('POST', $fakeClient->appCalls[0]['method']);
            self::assertSame('/shop_center/v1/authorize/unbind', $fakeClient->appCalls[0]['uri']);
            self::assertSame([
                'shop_id' => '5764612470103345070',
                'app_domain' => 'shop.custom-domain.test',
            ], $fakeClient->appCalls[0]['payload']);
            self::assertSame([
                'errno' => 0,
                'data' => [
                    'method' => 'POST',
                    'uri' => '/shop_center/v1/authorize/unbind',
                    'payload' => [
                        'shop_id' => '5764612470103345070',
                        'app_domain' => 'shop.custom-domain.test',
                    ],
                ],
            ], $response);
        } finally {
            $this->restoreFood99DomainEnvironment($previousDomainEnv);
        }
    }

    public function testPortalDomainIgnoresLocalhostAndFallsBackToConfiguredPublicDomain(): void
    {
        $previousDomainEnv = $this->captureFood99DomainEnvironment();
        $this->clearFood99DomainEnvironment();
        $_ENV['ADMIN_APP_DOMAIN'] = 'https://admin.controleonline.com/app';

        $fakeClient = new FakeFood99Client();
        $service = $this->newServiceWithFakeFood99Client($fakeClient);
        $domainService = $this->createMock(DomainService::class);
        $domainService->expects(self::once())
            ->method('getDomain')
            ->willReturn('localhost:8081');
        $this->setObjectProperty(DefaultFoodService::class, $service, 'domainService', $domainService);

        try {
            $service->bindStore([
                'shop_id' => '5764612470103345070',
                'app_domain' => '127.0.0.1:8081',
            ]);

            self::assertSame([
                'shop_id' => '5764612470103345070',
                'app_domain' => 'admin.controleonline.com',
            ], $fakeClient->appCalls[0]['payload']);
        } finally {
            $this->restoreFood99DomainEnvironment($previousDomainEnv);
        }
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

    private function captureFood99DomainEnvironment(): array
    {
        $keys = [
            'OAUTH_99FOOD_APP_DOMAIN',
            'OAUTH_99FOOD_PUBLIC_DOMAIN',
            'PUBLIC_APP_DOMAIN',
            'ADMIN_APP_DOMAIN',
            'APP_DOMAIN',
        ];
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = array_key_exists($key, $_ENV) ? $_ENV[$key] : null;
            $values[$key . '_SERVER'] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
            $getenvValue = getenv($key);
            $values[$key . '_GETENV'] = $getenvValue === false ? null : $getenvValue;
        }

        return $values;
    }

    private function clearFood99DomainEnvironment(): void
    {
        foreach (['OAUTH_99FOOD_APP_DOMAIN', 'OAUTH_99FOOD_PUBLIC_DOMAIN', 'PUBLIC_APP_DOMAIN', 'ADMIN_APP_DOMAIN', 'APP_DOMAIN'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    private function restoreFood99DomainEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if (str_ends_with($key, '_SERVER')) {
                $actualKey = substr($key, 0, -7);
                if ($value === null) {
                    unset($_SERVER[$actualKey]);
                    continue;
                }

                $_SERVER[$actualKey] = $value;
                continue;
            }

            if (str_ends_with($key, '_GETENV')) {
                $actualKey = substr($key, 0, -7);
                putenv($value === null ? $actualKey : $actualKey . '=' . $value);
                continue;
            }

            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }
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
     * @var array<int, array{method:string, uri:string, payload:array}>
     */
    public array $borderCalls = [];

    /**
     * @var array<int, array{method:string, uri:string, payload:array}>
     */
    public array $borderMultipartCalls = [];

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

    public function requestBorderWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        $this->borderCalls[] = [
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

    public function requestBorderMultipartWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        $this->borderMultipartCalls[] = [
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
}
