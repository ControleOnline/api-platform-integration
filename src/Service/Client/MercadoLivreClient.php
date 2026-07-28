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

    public function getOrder(string $orderId, ?People $provider = null): ?array
    {
        return $this->requestApi('GET', '/orders/' . rawurlencode($orderId), [], $provider);
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
}
