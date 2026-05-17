<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Cep;
use ControleOnline\Entity\City;
use ControleOnline\Entity\District;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Entity\State;
use ControleOnline\Entity\Street;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\UberService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class UberServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetUberAuthTokenCache();
    }

    public function testBuildWebhookSignatureUsesHmacSha256(): void
    {
        $service = (new \ReflectionClass(UberService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            hash_hmac('sha256', '{"event":"delivery.updated"}', 'secret-key'),
            $service->buildWebhookSignature('{"event":"delivery.updated"}', 'secret-key')
        );
    }

    public function testBuildAddressPayloadIncludesFormattedAddressAndLocation(): void
    {
        $service = (new \ReflectionClass(UberService::class))->newInstanceWithoutConstructor();
        $address = $this->address();

        $payload = $this->invokePrivateMethod($service, 'buildAddressPayload', $address);

        self::assertSame('RUA TESTE, 123 - CENTRO - SAO PAULO - SP - 01234567', $payload['formatted_address']);
        self::assertSame('APTO 10', $payload['apt_floor_suite']);
        self::assertSame(-23.55, $payload['location']['latitude']);
        self::assertSame(-46.63, $payload['location']['longitude']);
    }

    public function testResolveUberCredentialsUseEnvironmentValues(): void
    {
        $provider = $this->createMock(People::class);
        $service = $this->createService(
            new MockHttpClient(),
            $this->createConfigService([
                'OAUTH_UBER_STORE_ID' => 'company-store-id',
            ])
        );

        $previousEnv = $this->setEnvironmentValues([
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
        ]);

        try {
            self::assertSame('env-app-id', $this->invokePrivateMethod($service, 'resolveClientId', $provider));
            self::assertSame('env-secret', $this->invokePrivateMethod($service, 'resolveClientSecret', $provider));
            self::assertSame('company-store-id', $this->invokePrivateMethod($service, 'resolveConfiguredStoreId', $provider));
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testResolveUberStoreIdDoesNotFallBackToEnvironment(): void
    {
        $provider = $this->createMock(People::class);
        $service = $this->createService(
            new MockHttpClient(),
            $this->createConfigService([
                'OAUTH_UBER_STORE_ID' => '',
            ])
        );

        $previousEnv = $this->setEnvironmentValues([
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
            'OAUTH_UBER_STORE_ID' => 'env-store-alias',
        ]);

        try {
            self::assertSame('env-app-id', $this->invokePrivateMethod($service, 'resolveClientId', $provider));
            self::assertSame('env-secret', $this->invokePrivateMethod($service, 'resolveClientSecret', $provider));
            self::assertSame('', $this->invokePrivateMethod($service, 'resolveConfiguredStoreId', $provider));
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testGetAccessTokenUsesEnvironmentValuesInOAuthRequest(): void
    {
        $provider = $this->createMock(People::class);
        $capturedRequest = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest) {
            $capturedRequest = compact('method', 'url', 'options');

            return new MockResponse(
                json_encode([
                    'access_token' => 'token-123',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200]
            );
        });

        $service = $this->createService(
            $httpClient,
            $this->createConfigService([
                'OAUTH_UBER_STORE_ID' => 'company-store-id',
            ])
        );

        $previousEnv = $this->setEnvironmentValues([
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
        ]);

        try {
            $token = $this->invokePrivateMethod($service, 'getAccessToken', $provider);

            self::assertSame('token-123', $token);
            self::assertIsArray($capturedRequest);
            parse_str((string) ($capturedRequest['options']['body'] ?? ''), $parsedBody);
            self::assertSame('env-app-id', $parsedBody['client_id'] ?? null);
            self::assertSame('env-secret', $parsedBody['client_secret'] ?? null);
            self::assertSame('client_credentials', $parsedBody['grant_type'] ?? null);
            self::assertSame('eats.deliveries', $parsedBody['scope'] ?? null);
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testConnectStoreViaOAuthSelectsNearestStoreAndPersistsStoreId(): void
    {
        $provider = $this->createMock(People::class);
        $provider->method('getId')->willReturn(123);
        $provider->method('getAddress')->willReturn([$this->address()]);

        $persistedConfig = null;
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getConfig')->willReturnCallback(
            static function (People $people, string $key, bool $json = false) {
                return match ($key) {
                    'OAUTH_UBER_STORE_ID' => '',
                    default => null,
                };
            }
        );
        $configService->method('discoveryConfig')->willReturnCallback(
            static function (People $people, string $key, bool $create = true) use (&$persistedConfig) {
                $persistedConfig = new Config();
                $persistedConfig->setPeople($people);
                $persistedConfig->setConfigKey($key);

                return $persistedConfig;
            }
        );

        $persistedValues = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function ($entity) use (&$persistedValues): bool {
                if (!$entity instanceof Config) {
                    return false;
                }

                $persistedValues['configKey'] = $entity->getConfigKey();
                $persistedValues['configValue'] = $entity->getConfigValue();

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            return match (count($requests)) {
                1 => new MockResponse(
                    json_encode([
                        'access_token' => 'user-token',
                        'expires_in' => 3600,
                    ], JSON_THROW_ON_ERROR),
                    ['http_code' => 200]
                ),
                2 => new MockResponse(
                    json_encode([
                        'next_key' => null,
                        'stores' => [
                            [
                                'store_id' => 'near-store-id',
                                'name' => 'Near Store',
                                'status' => 'active',
                                'location' => [
                                    'latitude' => -23.5501,
                                    'longitude' => -46.6298,
                                ],
                            ],
                            [
                                'store_id' => 'far-store-id',
                                'name' => 'Far Store',
                                'status' => 'active',
                                'location' => [
                                    'latitude' => -22.9000,
                                    'longitude' => -43.2000,
                                ],
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    ['http_code' => 200]
                ),
                3 => new MockResponse('', ['http_code' => 204]),
                default => new MockResponse('', ['http_code' => 500]),
            };
        });

        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        $service = new UberService(
            $entityManager,
            $httpClient,
            $loggerService,
            $this->createStub(RequestPayloadService::class),
            $configService
        );

        $previousEnv = $this->setEnvironmentValues([
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
        ]);

        try {
            $result = $service->connectStoreViaOAuth($provider, 'auth-code-123', 'https://frontend.example/uber-integration-page');

            self::assertSame(0, $result['errno']);
            self::assertSame('near-store-id', $result['data']['store_id'] ?? null);
            self::assertSame('OAUTH_UBER_STORE_ID', $persistedValues['configKey'] ?? null);
            self::assertSame('near-store-id', $persistedValues['configValue'] ?? null);

            self::assertCount(3, $requests);
            self::assertSame('POST', $requests[0]['method']);
            self::assertStringContainsString('auth.uber.com/oauth/v2/token', $requests[0]['url']);

            parse_str((string) ($requests[0]['options']['body'] ?? ''), $tokenBody);
            self::assertSame('env-app-id', $tokenBody['client_id'] ?? null);
            self::assertSame('env-secret', $tokenBody['client_secret'] ?? null);
            self::assertSame('authorization_code', $tokenBody['grant_type'] ?? null);
            self::assertSame('https://frontend.example/uber-integration-page', $tokenBody['redirect_uri'] ?? null);
            self::assertSame('auth-code-123', $tokenBody['code'] ?? null);

            self::assertSame('GET', $requests[1]['method']);
            self::assertStringContainsString('/v1/eats/stores', $requests[1]['url']);

            self::assertSame('POST', $requests[2]['method']);
            self::assertStringContainsString('/v1/eats/stores/near-store-id/pos_data', $requests[2]['url']);
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testResolveDropoffAddressAcceptsObjectIds(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $resolvedAddress = $this->createConfiguredMock(Address::class, [
            'getId' => 2059,
        ]);
        $order = $this->createMock(\ControleOnline\Entity\Order::class);
        $candidate = new class {
            public function getId(): int
            {
                return 2059;
            }
        };

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('find')
            ->with(2059)
            ->willReturn($resolvedAddress);

        $service = $this->createService(
            new MockHttpClient(),
            $this->createConfigService([]),
            $entityManager
        );

        $order->method('getAddressDestination')->willReturn($candidate);

        self::assertSame($resolvedAddress, $this->invokePrivateMethod($service, 'resolveDropoffAddress', $order));
    }

    private function createService(
        MockHttpClient $httpClient,
        ConfigService $configService,
        ?EntityManagerInterface $entityManager = null
    ): UberService
    {
        $requestPayloadService = $this->createStub(RequestPayloadService::class);
        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        return new UberService(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $httpClient,
            $loggerService,
            $requestPayloadService,
            $configService
        );
    }

    private function createConfigService(array $values): ConfigService
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getConfig')->willReturnCallback(
            static function (People $people, string $key, bool $json = false) use ($values) {
                return $values[$key] ?? null;
            }
        );

        return $configService;
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function setEnvironmentValues(array $values): array
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = [
                'getenv' => getenv($name),
                '_ENV' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
                '_SERVER' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            ];
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return $previous;
    }

    private function restoreEnvironmentValues(array $previousValues): void
    {
        foreach ($previousValues as $name => $value) {
            $previousGetenv = $value['getenv'] ?? false;
            $previousEnv = $value['_ENV'] ?? null;
            $previousServer = $value['_SERVER'] ?? null;

            if ($previousGetenv === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value['getenv']);
            }

            if ($previousEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previousServer;
            }
        }
    }

    private function resetUberAuthTokenCache(): void
    {
        $reflection = new \ReflectionClass(UberService::class);
        $property = $reflection->getProperty('authTokenCache');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function address(): Address
    {
        $state = new State();
        $state->setState('Sao Paulo');
        $state->setUf('SP');

        $city = new City();
        $city->setCity('Sao Paulo');
        $city->setState($state);

        $district = new District();
        $district->setDistrict('Centro');
        $district->setCity($city);

        $cep = new Cep();
        $cep->setCep(1234567);

        $street = new Street();
        $street->setStreet('Rua Teste');
        $street->setDistrict($district);
        $street->setCep($cep);

        $address = new Address();
        $address->setNumber(123);
        $address->setComplement('Apto 10');
        $address->setStreet($street);
        $address->setLatitude(-23.55);
        $address->setLongitude(-46.63);

        return $address;
    }
}
