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

    public function testResolveUberCredentialsPreferCompanyConfigOverEnvironmentFallback(): void
    {
        $provider = $this->createMock(People::class);
        $service = $this->createService(
            new MockHttpClient(),
            $this->createConfigService([
                'OAUTH_UBER_APP_ID' => 'company-app-id',
                'OAUTH_UBER_CLIENT_SECRET' => 'company-secret',
                'OAUTH_UBER_STORE_ID' => 'company-store-id',
            ])
        );

        $previousEnv = $this->setEnvironmentValues([
            'UBER_CLIENT_ID' => 'env-client-id',
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'UBER_CLIENT_SECRET' => 'env-client-secret',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
            'UBER_STORE_ID' => 'env-store-id',
            'OAUTH_UBER_STORE_ID' => 'env-store-alias',
        ]);

        try {
            self::assertSame('company-app-id', $this->invokePrivateMethod($service, 'resolveClientId', $provider));
            self::assertSame('company-secret', $this->invokePrivateMethod($service, 'resolveClientSecret', $provider));
            self::assertSame('company-store-id', $this->invokePrivateMethod($service, 'resolveConfiguredStoreId', $provider));
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testResolveUberCredentialsFallBackToEnvironmentWhenCompanyConfigIsMissing(): void
    {
        $provider = $this->createMock(People::class);
        $service = $this->createService(
            new MockHttpClient(),
            $this->createConfigService([
                'OAUTH_UBER_APP_ID' => '',
                'OAUTH_UBER_CLIENT_SECRET' => '',
                'OAUTH_UBER_STORE_ID' => '',
            ])
        );

        $previousEnv = $this->setEnvironmentValues([
            'UBER_CLIENT_ID' => 'env-client-id',
            'OAUTH_UBER_APP_ID' => 'env-app-id',
            'UBER_CLIENT_SECRET' => 'env-client-secret',
            'OAUTH_UBER_CLIENT_SECRET' => 'env-secret',
            'UBER_STORE_ID' => 'env-store-id',
            'OAUTH_UBER_STORE_ID' => 'env-store-alias',
        ]);

        try {
            self::assertSame('env-client-id', $this->invokePrivateMethod($service, 'resolveClientId', $provider));
            self::assertSame('env-client-secret', $this->invokePrivateMethod($service, 'resolveClientSecret', $provider));
            self::assertSame('env-store-id', $this->invokePrivateMethod($service, 'resolveConfiguredStoreId', $provider));
        } finally {
            $this->restoreEnvironmentValues($previousEnv);
        }
    }

    public function testGetAccessTokenUsesCompanyConfigValuesInOAuthRequest(): void
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
                'OAUTH_UBER_APP_ID' => 'company-app-id',
                'OAUTH_UBER_CLIENT_SECRET' => 'company-secret',
                'OAUTH_UBER_STORE_ID' => 'company-store-id',
            ])
        );

        $token = $this->invokePrivateMethod($service, 'getAccessToken', $provider);

        self::assertSame('token-123', $token);
        self::assertIsArray($capturedRequest);
        parse_str((string) ($capturedRequest['options']['body'] ?? ''), $parsedBody);
        self::assertSame('company-app-id', $parsedBody['client_id'] ?? null);
        self::assertSame('company-secret', $parsedBody['client_secret'] ?? null);
        self::assertSame('client_credentials', $parsedBody['grant_type'] ?? null);
        self::assertSame('eats.deliveries', $parsedBody['scope'] ?? null);
    }

    private function createService(MockHttpClient $httpClient, ConfigService $configService): UberService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $requestPayloadService = $this->createStub(RequestPayloadService::class);
        $loggerService = $this->createMock(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        return new UberService(
            $entityManager,
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
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        return $previous;
    }

    private function restoreEnvironmentValues(array $previousValues): void
    {
        foreach ($previousValues as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $value);
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
