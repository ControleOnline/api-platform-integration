<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\Food99Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class Food99ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Food99Client::resetAccessTokenCache();

        parent::tearDown();
    }

    public function testGetAccessTokenUsesServerFallbackAndCachesToken(): void
    {
        $previousValues = [
            'OAUTH_99FOOD_CLIENT_ID' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_SECRET'] : null,
            'OAUTH_99FOOD_CLIENT_ID_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] : null,
        ];
        unset($_ENV['OAUTH_99FOOD_CLIENT_ID'], $_ENV['OAUTH_99FOOD_CLIENT_SECRET']);

        $_SERVER['OAUTH_99FOOD_CLIENT_ID'] = 'server-app-id';
        $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] = 'server-app-secret';

        $requestCount = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestCount) {
            $requestCount++;

            self::assertSame('GET', $method);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/get', $url);
            self::assertSame([
                'app_id' => 'server-app-id',
                'app_secret' => 'server-app-secret',
                'app_shop_id' => '3',
            ], $options['query']);

            return new MockResponse(
                json_encode([
                    'errno' => 0,
                    'data' => [
                        'auth_token' => 'token-123',
                        'token_expiration_time' => time() + 3600,
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200]
            );
        });

        $client = new Food99Client($httpClient);
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 3,
        ]);

        try {
            self::assertSame('token-123', $client->getAccessToken($provider));
            self::assertSame('token-123', $client->getAccessToken($provider));
            self::assertSame(1, $requestCount);
        } finally {
            $this->restoreEnvironmentValues($previousValues);
        }
    }

    public function testResolveIntegrationAccessTokenUsesClientCredentials(): void
    {
        $previousValues = [
            'OAUTH_99FOOD_CLIENT_ID' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_SECRET'] : null,
            'OAUTH_99FOOD_CLIENT_ID_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] : null,
        ];
        unset($_ENV['OAUTH_99FOOD_CLIENT_ID'], $_ENV['OAUTH_99FOOD_CLIENT_SECRET']);

        $_SERVER['OAUTH_99FOOD_CLIENT_ID'] = 'server-app-id';
        $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] = 'server-app-secret';

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            if (str_contains($url, '/v1/auth/authtoken/refresh')) {
                return new MockResponse(
                    json_encode([
                        'errno' => 0,
                        'errmsg' => 'ok',
                    ], JSON_THROW_ON_ERROR),
                    ['http_code' => 200]
                );
            }

            return new MockResponse(
                json_encode([
                    'errno' => 0,
                    'data' => [
                        'auth_token' => 'token-456',
                        'token_expiration_time' => time() + 3600,
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200]
            );
        });

        $client = new Food99Client($httpClient);
        $provider = $this->createConfiguredMock(People::class, [
            'getId' => 3,
        ]);

        try {
            self::assertSame('token-456', $client->resolveIntegrationAccessToken($provider));
            self::assertCount(2, $requests);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/refresh', $requests[0]['url']);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/get', $requests[1]['url']);
        } finally {
            $this->restoreEnvironmentValues($previousValues);
        }
    }

    public function testCallAppEndpointWithResponseInjectsAppCredentials(): void
    {
        $previousValues = [
            'OAUTH_99FOOD_CLIENT_ID' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_ENV) ? $_ENV['OAUTH_99FOOD_CLIENT_SECRET'] : null,
            'OAUTH_99FOOD_CLIENT_ID_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_ID', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_ID'] : null,
            'OAUTH_99FOOD_CLIENT_SECRET_SERVER' => array_key_exists('OAUTH_99FOOD_CLIENT_SECRET', $_SERVER) ? $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] : null,
        ];
        unset($_ENV['OAUTH_99FOOD_CLIENT_ID'], $_ENV['OAUTH_99FOOD_CLIENT_SECRET']);

        $_SERVER['OAUTH_99FOOD_CLIENT_ID'] = 'app-id';
        $_SERVER['OAUTH_99FOOD_CLIENT_SECRET'] = 'app-secret';

        $capturedRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest) {
            $capturedRequest = compact('method', 'url', 'options');

            return new MockResponse(
                json_encode([
                    'errno' => 0,
                    'data' => [],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200]
            );
        });

        $client = new Food99Client($httpClient);

        try {
            self::assertSame(
                [
                    'errno' => 0,
                    'data' => [],
                ],
                $client->callAppEndpointWithResponse('POST', '/v1/auth/authorizationpage/getUrl', [
                    'foo' => 'bar',
                ])
            );

            self::assertIsArray($capturedRequest);
            self::assertSame('POST', $capturedRequest['method']);
            self::assertSame('https://b.99app.com/v1/auth/authorizationpage/getUrl', $capturedRequest['url']);
            self::assertSame([
                'foo' => 'bar',
                'app_id' => 'app-id',
                'app_secret' => 'app-secret',
                ], $capturedRequest['options']['json']);
        } finally {
            $this->restoreEnvironmentValues($previousValues);
        }
    }

    private function restoreEnvironmentValues(array $previousValues): void
    {
        foreach ($previousValues as $key => $value) {
            if (str_ends_with($key, '_SERVER')) {
                $actualKey = str_replace('_SERVER', '', $key);

                if ($value === null) {
                    unset($_SERVER[$actualKey]);
                } else {
                    $_SERVER[$actualKey] = $value;
                }

                continue;
            }

            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }
    }
}
