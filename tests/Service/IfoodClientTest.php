<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\IfoodClient;
use ControleOnline\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class IfoodClientTest extends TestCase
{
    protected function tearDown(): void
    {
        (new IfoodClient($this->createStub(HttpClientInterface::class)))->resetAccessTokenCache();

        parent::tearDown();
    }

    public function testGetAccessTokenPrefersConfigServiceOverEnvironment(): void
    {
        $previousValues = [
            'OAUTH_IFOOD_CLIENT_ID' => array_key_exists('OAUTH_IFOOD_CLIENT_ID', $_ENV) ? $_ENV['OAUTH_IFOOD_CLIENT_ID'] : null,
            'OAUTH_IFOOD_CLIENT_SECRET' => array_key_exists('OAUTH_IFOOD_CLIENT_SECRET', $_ENV) ? $_ENV['OAUTH_IFOOD_CLIENT_SECRET'] : null,
            'OAUTH_IFOOD_CLIENT_ID_SERVER' => array_key_exists('OAUTH_IFOOD_CLIENT_ID', $_SERVER) ? $_SERVER['OAUTH_IFOOD_CLIENT_ID'] : null,
            'OAUTH_IFOOD_CLIENT_SECRET_SERVER' => array_key_exists('OAUTH_IFOOD_CLIENT_SECRET', $_SERVER) ? $_SERVER['OAUTH_IFOOD_CLIENT_SECRET'] : null,
        ];

        $_ENV['OAUTH_IFOOD_CLIENT_ID'] = 'env-client-id';
        $_ENV['OAUTH_IFOOD_CLIENT_SECRET'] = 'env-client-secret';
        $_SERVER['OAUTH_IFOOD_CLIENT_ID'] = 'env-client-id';
        $_SERVER['OAUTH_IFOOD_CLIENT_SECRET'] = 'env-client-secret';

        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(4);

        $configService = $this->createMock(ConfigService::class);
        $callCount = 0;
        $configService
            ->method('getConfig')
            ->willReturnCallback(function (People $passedProvider, string $key) use (&$callCount, $provider): string {
                self::assertSame($provider, $passedProvider);

                if ($callCount === 0) {
                    self::assertSame('OAUTH_IFOOD_CLIENT_ID', $key);
                    $callCount++;

                    return 'db-client-id';
                }

                if ($callCount === 1) {
                    self::assertSame('OAUTH_IFOOD_CLIENT_SECRET', $key);
                    $callCount++;

                    return 'db-client-secret';
                }

                self::fail('Unexpected ConfigService::getConfig call.');
            });

        $httpClient = new IfoodRecordingHttpClient(function (string $method, string $url, array $options) {
            if (str_contains($url, '/authentication/v1.0/oauth/token')) {
                self::assertSame('POST', $method);
                self::assertSame('https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token', $url);
                self::assertSame('grantType=client_credentials&clientId=db-client-id&clientSecret=db-client-secret', $options['body']);

                return IfoodRecordedResponse::json([
                    'accessToken' => 'db-token',
                    'expiresIn' => 3600,
                ]);
            }

            self::assertSame('GET', $method);
            self::assertSame('https://merchant-api.ifood.com.br/merchant/v1.0/merchants', $url);
            self::assertSame('Bearer db-token', $options['headers']['Authorization'] ?? null);

            return IfoodRecordedResponse::json([
                'errno' => 0,
                'data' => [],
            ]);
        });

        $client = new IfoodClient($httpClient, null, $configService);

        try {
            self::assertSame(200, $client->requestMerchantEndpoint('GET', '/merchants', [], $provider)->getStatusCode());
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

final class IfoodRecordingHttpClient implements HttpClientInterface
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
        return new IfoodEmptyResponseStream();
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class IfoodRecordedResponse implements ResponseInterface
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

final class IfoodEmptyResponseStream implements ResponseStreamInterface
{
    public function key(): ResponseInterface
    {
        return IfoodRecordedResponse::json([]);
    }

    public function current(): ChunkInterface
    {
        return new IfoodEmptyChunk();
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

final class IfoodEmptyChunk implements ChunkInterface
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
