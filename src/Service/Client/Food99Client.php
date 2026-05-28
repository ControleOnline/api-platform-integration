<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Entity\People;
use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Food99Client
{
    private const API_BASE_URL = 'https://openapi.99food.com';
    private const BORDER_BASE_URL = 'https://b.99app.com';

    private static array $authTokenCache = [];

    private ?LoggerInterface $logger = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?LoggerService $loggerService = null,
    ) {}

    public function requestBorderWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::BORDER_BASE_URL, $method, $uri, $payload, $logContext, false);
    }

    public function requestBorderMultipartWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::BORDER_BASE_URL, $method, $uri, $payload, $logContext, true);
    }

    public function callAppEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
    {
        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();

        if (!$appId || !$appSecret) {
            return null;
        }

        $payload['app_id'] = $payload['app_id'] ?? $appId;
        $payload['app_secret'] = $payload['app_secret'] ?? $appSecret;

        return $this->requestBorderWithResponse($method, $uri, $payload);
    }

    public function callStoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::BORDER_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestBorderWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function callStoreMultipartEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::BORDER_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestBorderMultipartWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function callOrderEndpointWithResponse(string $uri, array $payload, ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::API_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestOpenApiWithResponse('POST', $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function getAccessToken(?People $provider = null): ?string
    {
        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();
        $appShopId = $this->resolveAppShopId($provider);

        if (!$appId || !$appSecret || !$appShopId) {
            return null;
        }

        $cachedToken = self::$authTokenCache[$appShopId] ?? null;
        $expirationTime = is_array($cachedToken) ? ($cachedToken['token_expiration_time'] ?? null) : null;
        $hasValidCachedToken = !empty($cachedToken['auth_token']) && (!is_numeric($expirationTime) || (int) $expirationTime > (time() + 60));

        if ($hasValidCachedToken) {
            return (string) $cachedToken['auth_token'];
        }

        if (is_numeric($expirationTime) && (int) $expirationTime <= (time() + 60)) {
            $this->refreshAuthToken($appId, $appSecret, $appShopId);
        }

        $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId);
        if (!$tokenData || empty($tokenData['auth_token'])) {
            return null;
        }

        return (string) $tokenData['auth_token'];
    }

    public function isAuthAvailable(?People $provider = null): bool
    {
        return $this->getAccessToken($provider) !== null;
    }

    public static function resetAccessTokenCache(): void
    {
        self::$authTokenCache = [];
    }

    public function resolveIntegrationAccessToken(People $provider): ?string
    {
        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();
        $appShopId = $this->resolveAppShopId($provider);

        if (!$appId || !$appSecret || !$appShopId) {
            return null;
        }

        $this->refreshAuthToken($appId, $appSecret, $appShopId);

        $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, false);
        if (!$tokenData || empty($tokenData['auth_token'])) {
            $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, true);
        }

        return !empty($tokenData['auth_token']) ? (string) $tokenData['auth_token'] : null;
    }

    private function requestOpenApiWithResponse(string $method, string $uri, array $payload, array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::API_BASE_URL, $method, $uri, $payload, $logContext, false);
    }

    private function requestWithResponse(
        string $baseUrl,
        string $method,
        string $uri,
        array $payload,
        array $logContext = [],
        bool $multipart = false
    ): ?array {
        $url = $baseUrl . $uri;
        $method = strtoupper($method);
        $startedAt = microtime(true);

        if ($multipart && $method !== 'POST') {
            $this->logger()?->warning('Food99 multipart request only supports POST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $baseUrl,
            ], $logContext));

            return null;
        }

        $requestOptions = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($multipart) {
            $formData = new FormDataPart($payload);
            $requestOptions['headers'] = $formData->getPreparedHeaders()->toArray();
            $requestOptions['body'] = $formData->bodyToIterable();
        } elseif ($method === 'GET') {
            $requestOptions['query'] = $payload;
        } else {
            $requestOptions['json'] = $payload;
        }

        try {
            $this->logger()?->info('Food99 ACTION REQUEST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $baseUrl,
                'content_type' => $multipart ? 'multipart/form-data' : 'application/json',
            ], $logContext));

            $response = $this->httpClient->request($method, $url, $requestOptions);
            $result = $response->toArray(false);

            $this->logger()?->info('Food99 ACTION RESPONSE', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
                'api_base_url' => $baseUrl,
            ], $logContext));

            return $result;
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 ACTION ERROR', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
                'api_base_url' => $baseUrl,
            ], $logContext));

            return null;
        }
    }

    private function requestAuthToken(string $appId, string $appSecret, string $appShopId, bool $allowRefreshFallback = true): ?array
    {
        try {
            $response = $this->requestWithResponse(self::API_BASE_URL, 'GET', '/v1/auth/authtoken/get', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'app_shop_id' => $appShopId,
            ]);

            $payload = is_array($response) ? $response : [];
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $authToken = $data['auth_token'] ?? null;
            $tokenExpirationTime = $data['token_expiration_time'] ?? null;
            $errno = (int) ($payload['errno'] ?? 1);
            $errmsg = (string) ($payload['errmsg'] ?? '');

            if ($errno !== 0 || !$authToken) {
                if (
                    $allowRefreshFallback
                    && in_array($errno, [10100, 10101, 10102], true)
                ) {
                    $this->logger()?->info('Food99 auth token request requires refresh fallback', [
                        'app_shop_id' => $appShopId,
                        'errno' => $errno,
                        'errmsg' => $errmsg,
                    ]);

                    $refreshSuccess = $this->refreshAuthToken($appId, $appSecret, $appShopId);
                    if ($refreshSuccess) {
                        return $this->requestAuthToken($appId, $appSecret, $appShopId, false);
                    }
                }

                $this->logger()?->error('Food99 auth token request failed', [
                    'app_shop_id' => $appShopId,
                    'errno' => $errno,
                    'errmsg' => $errmsg,
                ]);

                return null;
            }

            self::$authTokenCache[$appShopId] = [
                'auth_token' => (string) $authToken,
                'token_expiration_time' => is_numeric($tokenExpirationTime) ? (int) $tokenExpirationTime : null,
            ];

            $this->logger()?->info('Food99 auth token fetched', [
                'app_shop_id' => $appShopId,
                'token_expiration_time' => self::$authTokenCache[$appShopId]['token_expiration_time'],
            ]);

            return self::$authTokenCache[$appShopId];
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 auth token request error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function refreshAuthToken(string $appId, string $appSecret, string $appShopId): bool
    {
        try {
            $response = $this->requestWithResponse(self::API_BASE_URL, 'GET', '/v1/auth/authtoken/refresh', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'app_shop_id' => $appShopId,
            ]);

            $payload = is_array($response) ? $response : [];
            $success = $this->isSuccessfulErrno($payload['errno'] ?? null);

            $this->logger()?->info('Food99 auth token refresh response', [
                'app_shop_id' => $appShopId,
                'errno' => $payload['errno'] ?? null,
                'errmsg' => $payload['errmsg'] ?? null,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 auth token refresh error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveAccessToken(?People $provider = null): ?string
    {
        try {
            return $this->getAccessToken($provider);
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 access token resolution error', [
                'provider_id' => $provider?->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveAppId(): ?string
    {
        $appId = $this->resolveEnvironmentValue('OAUTH_99FOOD_CLIENT_ID');
        if ($appId === '') {
            $appId = $this->resolveEnvironmentValue('OAUTH_99FOOD_APP_ID');
        }

        if ($appId === '') {
            $this->logger()?->warning('Food99 app_id is not configured', [
                'expected_env' => ['OAUTH_99FOOD_CLIENT_ID', 'OAUTH_99FOOD_APP_ID'],
            ]);

            return null;
        }

        return $appId;
    }

    private function resolveAppSecret(): ?string
    {
        $appSecret = $this->resolveEnvironmentValue('OAUTH_99FOOD_CLIENT_SECRET');
        if ($appSecret === '') {
            $appSecret = $this->resolveEnvironmentValue('OAUTH_99FOOD_APP_SECRET');
        }

        if ($appSecret === '') {
            $this->logger()?->warning('Food99 app_secret is not configured', [
                'expected_env' => ['OAUTH_99FOOD_CLIENT_SECRET', 'OAUTH_99FOOD_APP_SECRET'],
            ]);

            return null;
        }

        return $appSecret;
    }

    private function resolveAppShopId(?People $provider = null): ?string
    {
        if ($provider?->getId()) {
            return (string) $provider->getId();
        }

        $this->logger()?->warning('Food99 app_shop_id could not be resolved', [
            'provider_id' => $provider?->getId(),
            'expected_provider_value' => 'People.id',
        ]);

        return null;
    }

    private function isSuccessfulErrno(mixed $errno): bool
    {
        if ($errno === null) {
            return false;
        }

        if (is_numeric($errno)) {
            return (int) $errno === 0;
        }

        return trim((string) $errno) === '0';
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        foreach (
            [
                'auth_token',
                'app_secret',
                'appSecret',
                'access_token',
                'finance_access_token',
                'deliveryCode',
                'delivery_code',
            ] as $secretKey
        ) {
            if (isset($payload[$secretKey])) {
                $payload[$secretKey] = '***';
            }
        }

        return $payload;
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

    private function logger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        if (!$this->loggerService instanceof LoggerService) {
            return null;
        }

        $this->logger = $this->loggerService->getLogger('99Food');

        return $this->logger;
    }
}
