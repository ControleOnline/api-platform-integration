<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class UberClient
{
    private const API_BASE_URL = 'https://api.uber.com';
    private const AUTHORIZATION_URL = 'https://auth.uber.com/oauth/v2/authorize';
    private const AUTH_URL = 'https://auth.uber.com/oauth/v2/token';
    private const STORE_LIST_URL = 'https://api.uber.com/v1/eats/stores';
    private const POS_PROVISIONING_SCOPE = 'eats.pos_provisioning';

    private static ?array $authTokenCache = null;

    private ?LoggerInterface $logger = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerService $loggerService = null,
    ) {}

    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state): array
    {
        $authorizationUrl = self::AUTHORIZATION_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::POS_PROVISIONING_SCOPE,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => $authorizationUrl,
            'url' => $authorizationUrl,
            'auth_url' => $authorizationUrl,
            'redirect_uri' => $redirectUri,
        ];
    }

    public function getAccessToken(array $formFields, ?string $cacheKey = null): ?string
    {
        if ($cacheKey !== null) {
            $cached = self::$authTokenCache[$cacheKey] ?? null;
            $expiresAt = is_array($cached) ? (int) ($cached['expires_at'] ?? 0) : 0;
            if (is_array($cached) && !empty($cached['access_token']) && $expiresAt > (time() + 60)) {
                return (string) $cached['access_token'];
            }
        }

        $tokenResponse = $this->requestOAuthToken($formFields);
        if ($tokenResponse === null) {
            return null;
        }

        $token = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($token === '') {
            return null;
        }

        if ($cacheKey !== null) {
            $expiresIn = max(60, (int) ($tokenResponse['expires_in'] ?? 0));
            self::$authTokenCache[$cacheKey] = [
                'access_token' => $token,
                'expires_at' => time() + $expiresIn,
            ];
        }

        return $token;
    }

    public function requestDeliverableStores(string $token, array $query): array
    {
        return $this->requestApi('GET', '/v1/eats/deliveries/stores', ['query' => $query], $token);
    }

    public function requestDeliveryEstimate(string $token, array $payload): array
    {
        return $this->requestApi('POST', '/v1/eats/deliveries/estimates', ['json' => $payload], $token);
    }

    public function requestDeliveryOrder(string $token, array $payload): array
    {
        return $this->requestApi('POST', '/v1/eats/deliveries/orders', ['json' => $payload], $token);
    }

    public function listAuthorizedStores(string $token): array
    {
        $stores = [];
        $startKey = null;
        $pageCount = 0;

        do {
            $query = ['limit' => 100];
            if (is_string($startKey) && trim($startKey) !== '') {
                $query['start_key'] = $startKey;
            }

            $response = $this->requestApi('GET', '/v1/eats/stores', ['query' => $query], $token);
            if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
                return $response;
            }

            $body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $pageStores = is_array($body['stores'] ?? null) ? $body['stores'] : [];
            $stores = array_merge($stores, $pageStores);
            $startKey = trim((string) ($body['next_key'] ?? ''));
            $pageCount++;
        } while ($startKey !== '' && $pageCount < 10 && count($stores) < 1000);

        return [
            'status' => 200,
            'body' => [
                'stores' => $stores,
                'next_key' => $startKey,
            ],
        ];
    }

    public function activateStore(string $token, string $storeId, array $payload = []): array
    {
        return $this->requestApi(
            'POST',
            self::STORE_LIST_URL . '/' . rawurlencode($storeId) . '/pos_data',
            ['json' => $payload],
            $token
        );
    }

    public static function resetAccessTokenCache(): void
    {
        self::$authTokenCache = null;
    }

    private function requestOAuthToken(array $formFields): ?array
    {
        try {
            $response = $this->httpClient->request('POST', self::AUTH_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query($formFields, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            return $this->decodeResponseBody((string) $response->getContent(false));
        } catch (\Throwable $exception) {
            $this->logger()?->error('Uber access token request failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function requestApi(string $method, string $path, array $options = [], ?string $token = null): array
    {
        $headers = array_merge([
            'Accept' => 'application/json',
        ], is_array($options['headers'] ?? null) ? $options['headers'] : []);

        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if (isset($options['json']) && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $requestOptions = $options;
        $requestOptions['headers'] = $headers;

        try {
            $response = $this->httpClient->request($method, self::API_BASE_URL . $path, $requestOptions);

            return [
                'status' => $response->getStatusCode(),
                'body' => $this->decodeResponseBody((string) $response->getContent(false)),
            ];
        } catch (\Throwable $exception) {
            $this->logger()?->error('Uber request failed', [
                'method' => strtoupper($method),
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => [
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function decodeResponseBody(string $rawBody): array
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['message' => $rawBody];
    }

    private function logger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        if (!$this->loggerService instanceof LoggerService) {
            return null;
        }

        $this->logger = $this->loggerService->getLogger('Uber');

        return $this->logger;
    }
}
