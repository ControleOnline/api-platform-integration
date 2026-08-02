<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MercadoLivreClient
{
    private const API_BASE_URL = 'https://api.mercadolibre.com';
    private const DEFAULT_AUTHORIZATION_URL = 'https://auth.mercadolivre.com.br/authorization';
    private const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';

    private ?LoggerInterface $logger = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigService $configService,
        private readonly ?LoggerService $loggerService = null,
    ) {
        $this->logger = $this->loggerService?->getLogger('MercadoLivre');
    }

    public function getCurrentUser(?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/users/me', [], $provider);
    }

    public function getUser(string $userId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/users/' . rawurlencode($userId), [], $provider);
    }

    public function searchUserItems(string $userId, ?People $provider = null, int $offset = 0, int $limit = 50): ?array
    {
        return $this->requestApi('GET', '/users/' . rawurlencode($userId) . '/items/search', [
            'query' => [
                'offset' => max(0, $offset),
                'limit' => min(100, max(1, $limit)),
            ],
        ], $provider);
    }

    public function getItem(string $itemId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/items/' . rawurlencode($itemId), [], $provider);
    }

    public function getItemDescription(string $itemId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/items/' . rawurlencode($itemId) . '/description', [], $provider);
    }

    public function getCategory(string $categoryId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/categories/' . rawurlencode($categoryId), [], $provider);
    }

    public function downloadPublicFile(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'image/*,*/*;q=0.8',
                ],
                'timeout' => 20,
                'max_duration' => 35,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('Mercado Livre file download failed', [
                    'url' => $url,
                    'status' => $status,
                ]);

                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = strtolower(trim((string) ($headers['content-type'][0] ?? 'application/octet-stream')));
            $content = $response->getContent(false);
            if ($content === '') {
                return null;
            }

            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        } catch (\Throwable $exception) {
            $this->logger?->error('Mercado Livre file download exception', [
                'url' => $url,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    public function getOrder(string $orderId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/orders/' . rawurlencode($orderId), [], $provider);
    }

    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state, array $extraParams = []): array
    {
        $authorizationBaseUrl = trim((string) ($_ENV['OAUTH_MERCADO_LIVRE_AUTHORIZATION_URL'] ?? $_SERVER['OAUTH_MERCADO_LIVRE_AUTHORIZATION_URL'] ?? ''))
            ?: self::DEFAULT_AUTHORIZATION_URL;

        $authorizationUrl = $authorizationBaseUrl . '?' . http_build_query(array_filter(array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], $extraParams), static fn($value): bool => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => $authorizationUrl,
            'url' => $authorizationUrl,
            'auth_url' => $authorizationUrl,
            'redirect_uri' => $redirectUri,
        ];
    }

    public function exchangeAuthorizationCode(string $clientId, string $clientSecret, string $code, string $redirectUri, ?string $codeVerifier = null): ?array
    {
        try {
            $body = [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ];

            if (trim((string) $codeVerifier) !== '') {
                $body['code_verifier'] = trim((string) $codeVerifier);
            }

            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            $status = $response->getStatusCode();
            $body = $this->decodeResponseBody((string) $response->getContent(false));
            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('Mercado Livre OAuth token request failed', [
                    'status' => $status,
                    'body' => $body,
                ]);

                return array_merge($body, [
                    'status' => $status,
                    '_request_failed' => true,
                ]);
            }

            return $body;
        } catch (\Throwable $exception) {
            $this->logger?->error('Mercado Livre OAuth token request exception', [
                'exception' => $exception,
            ]);

            return [
                'error' => 'request_exception',
                'message' => $exception->getMessage(),
                '_request_failed' => true,
            ];
        }
    }

    public function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ], '', '&', PHP_QUERY_RFC3986),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            $status = $response->getStatusCode();
            $body = $this->decodeResponseBody((string) $response->getContent(false));
            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('Mercado Livre OAuth refresh request failed', [
                    'status' => $status,
                    'body' => $body,
                ]);

                return array_merge($body, [
                    'status' => $status,
                    '_request_failed' => true,
                ]);
            }

            return $body;
        } catch (\Throwable $exception) {
            $this->logger?->error('Mercado Livre OAuth refresh request exception', [
                'exception' => $exception,
            ]);

            return [
                'error' => 'request_exception',
                'message' => $exception->getMessage(),
                '_request_failed' => true,
            ];
        }
    }

    public function requestApi(string $method, string $path, array $options = [], ?People $provider = null): ?array
    {
        $headers = array_merge([
            'Accept' => 'application/json',
        ], is_array($options['headers'] ?? null) ? $options['headers'] : []);

        $token = $this->resolveAccessToken($provider);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $url = str_starts_with($path, 'http') ? $path : self::API_BASE_URL . $path;

        try {
            $response = $this->httpClient->request($method, $url, array_merge($options, [
                'headers' => $headers,
                'timeout' => 20,
                'max_duration' => 35,
            ]));

            $status = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('Mercado Livre API request failed', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'body' => $body,
                ]);

                return null;
            }

            return is_array($body) ? $body : null;
        } catch (\Throwable $exception) {
            $this->logger?->error('Mercado Livre API request exception', [
                'method' => $method,
                'path' => $path,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function resolveAccessToken(?People $provider): string
    {
        $token = $provider instanceof People
            ? trim((string) ($this->configService->getConfig($provider, 'mercado-livre-access-token') ?? ''))
            : '';

        if ($token !== '') {
            return $token;
        }

        return trim((string) ($_ENV['OAUTH_MERCADO_LIVRE_ACCESS_TOKEN'] ?? $_SERVER['OAUTH_MERCADO_LIVRE_ACCESS_TOKEN'] ?? ''));
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
}
