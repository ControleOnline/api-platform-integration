<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\Food99Client;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

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

        $httpClient = new RecordingHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('GET', $method);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/get?app_id=server-app-id&app_secret=server-app-secret&app_shop_id=3', $url);
            self::assertSame([
                'app_id' => 'server-app-id',
                'app_secret' => 'server-app-secret',
                'app_shop_id' => '3',
            ], $options['query']);

            return RecordedResponse::json([
                'errno' => 0,
                'data' => [
                    'auth_token' => 'token-123',
                    'token_expiration_time' => time() + 3600,
                ],
            ]);
        });

        $client = new Food99Client($httpClient);
        $provider = $this->newTestPeople(3);

        try {
            self::assertSame('token-123', $client->getAccessToken($provider));
            self::assertSame('token-123', $client->getAccessToken($provider));
            self::assertCount(1, $httpClient->requests);
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
        $httpClient = new RecordingHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = compact('method', 'url', 'options');

            if (str_contains($url, '/v1/auth/authtoken/refresh')) {
                return RecordedResponse::json([
                    'errno' => 0,
                    'errmsg' => 'ok',
                ]);
            }

            return RecordedResponse::json([
                'errno' => 0,
                'data' => [
                    'auth_token' => 'token-456',
                    'token_expiration_time' => time() + 3600,
                ],
            ]);
        });

        $client = new Food99Client($httpClient);
        $provider = $this->newTestPeople(3);

        try {
            self::assertSame('token-456', $client->resolveIntegrationAccessToken($provider));
            self::assertCount(2, $requests);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/refresh?app_id=server-app-id&app_secret=server-app-secret&app_shop_id=3', $requests[0]['url']);
            self::assertSame('https://openapi.99food.com/v1/auth/authtoken/get?app_id=server-app-id&app_secret=server-app-secret&app_shop_id=3', $requests[1]['url']);
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
        $httpClient = new RecordingHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest) {
            $capturedRequest = compact('method', 'url', 'options');

            return RecordedResponse::json([
                'errno' => 0,
                'data' => [],
            ]);
        });

        $client = new Food99Client($httpClient);

        try {
            self::assertSame(
                [
                    'errno' => 0,
                    'data' => [],
                ],
                $client->callAppEndpointWithResponse('POST', '/shop_center/v1/authorize/get_url', [
                    'foo' => 'bar',
                ])
            );

            self::assertIsArray($capturedRequest);
            self::assertSame('POST', $capturedRequest['method']);
            self::assertSame('https://openplatform-portal-food.99app.com/shop_center/v1/authorize/get_url', $capturedRequest['url']);
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

final class RecordingHttpClient implements HttpClientInterface
{
    /**
     * @var array<int, array{method:string, url:string, options:array}>
     */
    public array $requests = [];

    /**
     * @param callable(string, string, array):ResponseInterface $responseFactory
     */
    public function __construct(
        private readonly \Closure $responseFactory
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $recordedUrl = $url;

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $recordedUrl .= '?' . http_build_query($options['query']);
        }

        $this->requests[] = [
            'method' => $method,
            'url' => $recordedUrl,
            'options' => $options,
        ];

        $response = ($this->responseFactory)($method, $recordedUrl, $options);

        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('The response factory must return a ResponseInterface instance.');
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return new EmptyResponseStream();
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class RecordedResponse implements ResponseInterface
{
    public function __construct(
        private readonly string $content,
        private readonly int $statusCode = 200,
        private readonly array $headers = ['content-type' => ['application/json']],
    ) {}

    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self(json_encode($payload, JSON_THROW_ON_ERROR), $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->content;
    }

    public function toArray(bool $throw = true): array
    {
        $decoded = json_decode($this->content, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(string $type = null): mixed
    {
        return match ($type) {
            'http_code' => $this->statusCode,
            default => null,
        };
    }
}

final class EmptyResponseStream implements ResponseStreamInterface
{
    public function key(): ResponseInterface
    {
        return RecordedResponse::json([]);
    }

    public function current(): ChunkInterface
    {
        return new EmptyChunk();
    }

    public function next(): void
    {
    }

    public function valid(): bool
    {
        return false;
    }

    public function rewind(): void
    {
    }
}

final class EmptyChunk implements ChunkInterface
{
    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return true;
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return '';
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}
