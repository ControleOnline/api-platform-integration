<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class IfoodClient
{
    public const API_BASE_URL = 'https://merchant-api.ifood.com.br';

    private static array $authTokenCache = [];

    private ?LoggerInterface $logger = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?LoggerService $loggerService = null,
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->shouldAttachAuthHeader($url)) {
            $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
            if (!array_key_exists('Authorization', $headers) && !array_key_exists('authorization', $headers)) {
                $token = $this->getAccessToken();
                if ($token !== null && $token !== '') {
                    $headers['Authorization'] = 'Bearer ' . $token;
                }
            }

            $options['headers'] = $headers;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    public function getAccessToken(): ?string
    {
        $cachedToken = self::$authTokenCache['token'] ?? null;
        $cachedExpiresAt = self::$authTokenCache['expires_at'] ?? 0;
        if (is_string($cachedToken) && $cachedToken !== '' && (int) $cachedExpiresAt > (time() + 30)) {
            return $cachedToken;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/authentication/v1.0/oauth/token', [
                'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'grantType' => 'client_credentials',
                    'clientId' => $this->resolveEnvironmentValue('OAUTH_IFOOD_CLIENT_ID'),
                    'clientSecret' => $this->resolveEnvironmentValue('OAUTH_IFOOD_CLIENT_SECRET'),
                ]),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if ($statusCode !== 200) {
                $this->logger()?->error('iFood access token request failed', [
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);

                return null;
            }

            $data = $response->toArray(false);
            $token = $this->normalizeString($data['accessToken'] ?? null);
            if ($token === '') {
                $this->logger()?->error('iFood access token is missing in response', [
                    'response' => $responseBody,
                ]);

                return null;
            }

            $expiresIn = isset($data['expiresIn']) && is_numeric($data['expiresIn'])
                ? max(0, (int) $data['expiresIn'])
                : 300;

            self::$authTokenCache = [
                'token' => $token,
                'expires_at' => time() + $expiresIn,
            ];

            return $token;
        } catch (\Throwable $e) {
            $this->logger()?->error('iFood access token request error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isAuthAvailable(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function resetAccessTokenCache(): void
    {
        self::$authTokenCache = [];
    }

    private function shouldAttachAuthHeader(string $url): bool
    {
        return str_starts_with($url, self::API_BASE_URL)
            && !str_contains($url, '/authentication/v1.0/oauth/token');
    }

    private function resolveEnvironmentValue(string $name): string
    {
        return trim((string) (
            $_ENV[$name]
            ?? $_SERVER[$name]
            ?? getenv($name)
            ?: ''
        ));
    }

    private function normalizeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function logger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        if (!$this->loggerService instanceof LoggerService) {
            return null;
        }

        $this->logger = $this->loggerService->getLogger('iFood');

        return $this->logger;
    }
}
