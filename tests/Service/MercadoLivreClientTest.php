<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Service\Client\MercadoLivreClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class MercadoLivreClientTest extends TestCase
{
    public function testRefreshAccessTokenUsesRefreshGrant(): void
    {
        $httpClient = new MercadoLivreRecordingHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.mercadolibre.com/oauth/token', $url);
            self::assertSame('application/x-www-form-urlencoded', $options['headers']['Content-Type'] ?? null);
            self::assertSame(
                'grant_type=refresh_token&client_id=client-id&client_secret=client-secret&refresh_token=refresh-token',
                $options['body']
            );

            return MercadoLivreRecordedResponse::json([
                'access_token' => 'next-access-token',
                'refresh_token' => 'next-refresh-token',
                'user_id' => 123,
                'expires_in' => 21600,
            ]);
        });

        $client = new MercadoLivreClient($httpClient, $this->createStub(\ControleOnline\Service\ConfigService::class));

        self::assertSame([
            'access_token' => 'next-access-token',
            'refresh_token' => 'next-refresh-token',
            'user_id' => 123,
            'expires_in' => 21600,
        ], $client->refreshAccessToken('client-id', 'client-secret', 'refresh-token'));
    }
}

final class MercadoLivreRecordingHttpClient implements HttpClientInterface
{
    /**
     * @param callable(string, string, array):ResponseInterface $responseFactory
     */
    public function __construct(private readonly \Closure $responseFactory)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = ($this->responseFactory)($method, $url, $options);
        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('The response factory must return a ResponseInterface instance.');
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return new MercadoLivreEmptyResponseStream();
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class MercadoLivreRecordedResponse implements ResponseInterface
{
    public function __construct(
        private readonly string $content,
        private readonly int $statusCode = 200,
        private readonly array $headers = ['content-type' => ['application/json']],
    ) {
    }

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
        return $type === 'http_code' ? $this->statusCode : null;
    }
}

final class MercadoLivreEmptyResponseStream implements ResponseStreamInterface
{
    public function key(): ResponseInterface
    {
        return MercadoLivreRecordedResponse::json([]);
    }

    public function current(): ChunkInterface
    {
        return new MercadoLivreEmptyChunk();
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

final class MercadoLivreEmptyChunk implements ChunkInterface
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
