<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\ShopeeClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shopee multi-tenant integration foundation.
 *
 * Tokens and shop id live in Config per company. Marketplace API calls happen only here
 * (and queues/webhooks). MCP must read local Product/Inventory/Order after sync.
 */
class ShopeeService
{
    public const APP_CONTEXT = 'Shopee';
    private const CONFIG_ACCESS_TOKEN = 'shopee-access-token';
    private const CONFIG_REFRESH_TOKEN = 'shopee-refresh-token';
    private const CONFIG_SHOP_ID = 'shopee-shop-id';
    private const CONFIG_PARTNER_ID = 'OAUTH_SHOPEE_PARTNER_ID';
    private const CONFIG_PARTNER_KEY = 'OAUTH_SHOPEE_PARTNER_KEY';
    private const OAUTH_CALLBACK_PATH = '/oauth/shopee/return';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShopeeClient $shopeeClient,
        private readonly ConfigService $configService,
    ) {
    }

    public function getMarketplaceKey(): string
    {
        return self::APP_CONTEXT;
    }

    /**
     * @return array{success:bool,authorization_url?:string,error?:string,message?:string}
     */
    public function buildAuthorizationPage(People $provider, string $apiBaseUrl, ?string $returnUrl = null): array
    {
        $partnerId = $this->resolvePartnerId($provider);
        if ($partnerId === '') {
            return [
                'success' => false,
                'error' => 'missing_partner_id',
                'message' => 'Configure OAUTH_SHOPEE_PARTNER_ID (env or company config).',
            ];
        }

        $state = $this->encodeOAuthState([
            'provider_id' => (int) $provider->getId(),
            'return_url' => $this->normalizeReturnUrl($returnUrl),
            'exp' => time() + 900,
        ]);
        $redirectUri = rtrim($apiBaseUrl, '/') . self::OAUTH_CALLBACK_PATH;
        $built = $this->shopeeClient->buildAuthorizationUrl($partnerId, $redirectUri, $state);

        return [
            'success' => true,
            'authorization_url' => $built['authorization_url'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
    }

    /**
     * @return array{connected:bool,shop_id:?string,has_refresh_token:bool,data_source:string}
     */
    public function getConnectionStatus(People $provider): array
    {
        $access = $this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN);
        $shopId = $this->readConfigValue($provider, self::CONFIG_SHOP_ID);

        return [
            'connected' => $access !== '',
            'shop_id' => $shopId !== '' ? $shopId : null,
            'has_refresh_token' => $this->readConfigValue($provider, self::CONFIG_REFRESH_TOKEN) !== '',
            'data_source' => 'local_config',
            'key' => 'shopee',
            'label' => 'Shopee',
        ];
    }

    /**
     * @return array{success:bool}
     */
    public function disconnect(People $provider): array
    {
        foreach ([self::CONFIG_ACCESS_TOKEN, self::CONFIG_REFRESH_TOKEN, self::CONFIG_SHOP_ID] as $key) {
            $this->writeConfigValue($provider, $key, '');
        }

        return ['success' => true, 'connected' => false];
    }

    /**
     * Sync entrypoint placeholder — when API is configured, pulls remote catalog into local Product.
     *
     * @return array{success:bool,error?:string,message?:string}
     */
    public function importProducts(People $provider, int $limit = 50): array
    {
        $status = $this->getConnectionStatus($provider);
        if (empty($status['connected'])) {
            return [
                'success' => false,
                'error' => 'not_connected',
                'message' => 'Connect Shopee OAuth before importing products into local catalog.',
            ];
        }

        $remote = $this->shopeeClient->listItems($provider, 0, max(1, min(100, $limit)));
        if (!is_array($remote) || !empty($remote['_request_failed'])) {
            return [
                'success' => false,
                'error' => $remote['error'] ?? 'shopee_api_not_configured',
                'message' => $remote['message'] ?? 'Complete Shopee API client (signed requests) before product sync.',
            ];
        }

        return [
            'success' => false,
            'error' => 'not_implemented',
            'message' => 'Product mapping to local Product/ExtraData pending after API client is fully configured.',
        ];
    }

    public function resolveOAuthReturnUrl(string $state): string
    {
        $payload = $this->decodeOAuthState($state);

        return $this->normalizeReturnUrl($payload['return_url'] ?? null) ?: '/integrations-page';
    }

    public function resolveProviderIdFromState(string $state): ?int
    {
        $payload = $this->decodeOAuthState($state);
        $id = (int) ($payload['provider_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function resolvePartnerId(People $provider): string
    {
        $fromConfig = $this->readConfigValue($provider, self::CONFIG_PARTNER_ID);
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return trim((string) ($_ENV['OAUTH_SHOPEE_PARTNER_ID'] ?? $_SERVER['OAUTH_SHOPEE_PARTNER_ID'] ?? ''));
    }

    private function readConfigValue(People $provider, string $key): string
    {
        $rows = $this->entityManager->getRepository(Config::class)->findBy([
            'people' => $provider,
            'configKey' => $key,
        ]);
        if ($rows === []) {
            return '';
        }
        $value = current($rows)->getConfigValue();
        if (is_string($value)) {
            return trim($value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function writeConfigValue(People $provider, string $key, string $value): void
    {
        $repo = $this->entityManager->getRepository(Config::class);
        $rows = $repo->findBy(['people' => $provider, 'configKey' => $key]);
        $config = $rows[0] ?? null;
        if (!$config instanceof Config) {
            $config = new Config();
            $config->setPeople($provider);
            $config->setConfigKey($key);
            $this->entityManager->persist($config);
        }
        if (method_exists($config, 'setConfigValue')) {
            $config->setConfigValue($value);
        }
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeOAuthState(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOAuthState(string $state): array
    {
        $raw = base64_decode(strtr($state, '-_', '+/'), true);
        if ($raw === false) {
            throw new \InvalidArgumentException('invalid_state');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('invalid_state');
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            throw new \InvalidArgumentException('state_expired');
        }

        return $data;
    }

    private function normalizeReturnUrl(?string $returnUrl): string
    {
        $returnUrl = trim((string) $returnUrl);
        if ($returnUrl === '') {
            return '';
        }
        if (str_starts_with($returnUrl, '/')) {
            return $returnUrl;
        }
        if (preg_match('#^https?://#i', $returnUrl)) {
            return $returnUrl;
        }

        return '';
    }
}
