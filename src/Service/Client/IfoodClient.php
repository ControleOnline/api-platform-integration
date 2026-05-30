<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class IfoodClient
{
    private const API_BASE_URL = 'https://merchant-api.ifood.com.br';

    private static array $authTokenCache = [];

    private ?LoggerInterface $logger = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?LoggerService $loggerService = null,
    ) {}

    public function getAuthorizationUrl(): string
    {
        return $this->buildApiUrl('/authentication/v1.0/oauth/authorize');
    }

    public function getAccessTokenUrl(): string
    {
        return $this->buildApiUrl('/authentication/v1.0/oauth/token');
    }

    public function requestMerchantEndpoint(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->requestApi($method, '/merchant/v1.0' . $this->normalizePath($path), $options);
    }

    public function requestOrderEndpoint(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->requestApi($method, '/order/v1.0' . $this->normalizePath($path), $options);
    }

    public function requestShippingEndpoint(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->requestApi($method, '/shipping/v1.0' . $this->normalizePath($path), $options);
    }

    public function requestCatalogEndpoint(string $method, string $merchantId, string $path, array $options = []): ResponseInterface
    {
        $normalizedMerchantId = rawurlencode(trim($merchantId));

        return $this->requestApi(
            $method,
            '/catalog/v2.0/merchants/' . $normalizedMerchantId . $this->normalizePath($path),
            $options
        );
    }

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

    private function getAccessToken(): ?string
    {
        $cachedToken = self::$authTokenCache['token'] ?? null;
        $cachedExpiresAt = self::$authTokenCache['expires_at'] ?? 0;
        if (is_string($cachedToken) && $cachedToken !== '' && (int) $cachedExpiresAt > (time() + 30)) {
            return $cachedToken;
        }

        try {
            $response = $this->request('POST', $this->getAccessTokenUrl(), [
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

    private function requestApi(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->request($method, $this->buildApiUrl($path), $options);
    }

    private function buildApiUrl(string $path): string
    {
        return self::API_BASE_URL . $this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return '';
        }

        return '/' . ltrim($normalized, '/');
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
