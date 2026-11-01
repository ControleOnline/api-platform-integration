<?php

declare(strict_types=1);

namespace ControleOnline\Service\Client;

use ControleOnline\Entity\People;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shopee Open Platform HTTP client (foundation).
 * Signatures and host may vary by region; env overrides supported.
 *
 * @see https://open.shopee.com/
 */
class ShopeeClient
{
    private const DEFAULT_AUTH_HOST = 'https://partner.shopeemobile.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function buildAuthorizationUrl(string $partnerId, string $redirectUri, string $state): array
    {
        $host = rtrim(
            trim((string) ($_ENV['OAUTH_SHOPEE_AUTH_HOST'] ?? $_SERVER['OAUTH_SHOPEE_AUTH_HOST'] ?? ''))
                ?: self::DEFAULT_AUTH_HOST,
            '/'
        );
        $path = '/api/v2/shop/auth_partner';
        $query = http_build_query(array_filter([
            'partner_id' => $partnerId,
            'redirect' => $redirectUri,
            'state' => $state,
        ]));

        return [
            'authorization_url' => $host . $path . '?' . $query,
            'host' => $host,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exchangeToken(string $partnerId, string $partnerKey, string $code, int $shopId): ?array
    {
        // Full signed request is region-specific; foundation returns structured error until credentials + sign are configured.
        $this->logger?->info('Shopee token exchange requested', [
            'partner_id' => $partnerId,
            'shop_id' => $shopId,
            'has_code' => $code !== '',
        ]);

        return [
            '_request_failed' => true,
            'error' => 'shopee_token_exchange_not_configured',
            'message' => 'Configure OAUTH_SHOPEE_* and complete signed token exchange for the target region.',
        ];
    }

    /**
     * Placeholder for product list — used only by integration sync, never by MCP.
     *
     * @return array<string, mixed>|null
     */
    public function listItems(?People $provider, int $offset = 0, int $limit = 50): ?array
    {
        return [
            '_request_failed' => true,
            'error' => 'shopee_api_not_configured',
            'offset' => $offset,
            'limit' => $limit,
        ];
    }
}
