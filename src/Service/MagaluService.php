<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\MagaluClient;
use Doctrine\ORM\EntityManagerInterface;

/** Magalu multi-tenant foundation. MCP reads local catalog/orders only after sync. */
class MagaluService
{
    public const APP_CONTEXT = 'Magalu';
    private const CONFIG_ACCESS_TOKEN = 'magalu-access-token';
    private const CONFIG_REFRESH_TOKEN = 'magalu-refresh-token';
    private const CONFIG_SELLER_ID = 'magalu-seller-id';
    private const CONFIG_CLIENT_ID = 'OAUTH_MAGALU_CLIENT_ID';
    private const OAUTH_CALLBACK_PATH = '/oauth/magalu/return';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagaluClient $magaluClient,
    ) {
    }

    public function buildAuthorizationPage(People $provider, string $apiBaseUrl, ?string $returnUrl = null): array
    {
        $clientId = $this->readConfigValue($provider, self::CONFIG_CLIENT_ID)
            ?: trim((string) ($_ENV['OAUTH_MAGALU_CLIENT_ID'] ?? $_SERVER['OAUTH_MAGALU_CLIENT_ID'] ?? ''));
        if ($clientId === '') {
            return ['success' => false, 'error' => 'missing_client_id', 'message' => 'Configure OAUTH_MAGALU_CLIENT_ID.'];
        }
        $state = rtrim(strtr(base64_encode(json_encode([
            'provider_id' => (int) $provider->getId(),
            'return_url' => $returnUrl,
            'exp' => time() + 900,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $redirectUri = rtrim($apiBaseUrl, '/') . self::OAUTH_CALLBACK_PATH;
        $built = $this->magaluClient->buildAuthorizationUrl($clientId, $redirectUri, $state);

        return ['success' => true, 'authorization_url' => $built['authorization_url'], 'state' => $state, 'redirect_uri' => $redirectUri];
    }

    public function getConnectionStatus(People $provider): array
    {
        $access = $this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN);

        return [
            'connected' => $access !== '',
            'seller_id' => $this->readConfigValue($provider, self::CONFIG_SELLER_ID) ?: null,
            'has_refresh_token' => $this->readConfigValue($provider, self::CONFIG_REFRESH_TOKEN) !== '',
            'data_source' => 'local_config',
            'key' => 'magalu',
            'label' => 'Magazine Luiza',
        ];
    }

    public function disconnect(People $provider): array
    {
        foreach ([self::CONFIG_ACCESS_TOKEN, self::CONFIG_REFRESH_TOKEN, self::CONFIG_SELLER_ID] as $key) {
            $this->writeConfigValue($provider, $key, '');
        }

        return ['success' => true, 'connected' => false];
    }

    private function readConfigValue(People $provider, string $key): string
    {
        $rows = $this->entityManager->getRepository(Config::class)->findBy(['people' => $provider, 'configKey' => $key]);
        if ($rows === []) {
            return '';
        }
        $value = current($rows)->getConfigValue();

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function writeConfigValue(People $provider, string $key, string $value): void
    {
        $rows = $this->entityManager->getRepository(Config::class)->findBy(['people' => $provider, 'configKey' => $key]);
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
}
