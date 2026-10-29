<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\LinkedInClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Multi-tenant LinkedIn OAuth connect / status / disconnect.
 * Tokens stored per provider (People) via ConfigService — same pattern as Mercado Livre.
 */
class LinkedInService
{
    private const APP_CONTEXT = 'LinkedIn';

    public const CONFIG_ACCESS_TOKEN = 'linkedin-access-token';
    public const CONFIG_REFRESH_TOKEN = 'linkedin-refresh-token';
    public const CONFIG_MEMBER_ID = 'linkedin-member-id';
    public const CONFIG_MEMBER_NAME = 'linkedin-member-name';
    public const CONFIG_EXPIRES_AT = 'linkedin-expires-at';
    public const CONFIG_SCOPES = 'linkedin-scopes';

    /** App-level credentials (already provisioned for people/1 as linkedin_client_id/secret). */
    private const CONFIG_CLIENT_ID_KEYS = ['linkedin_client_id', 'linkedin-client-id', 'OAUTH_LINKEDIN_CLIENT_ID'];
    private const CONFIG_CLIENT_SECRET_KEYS = ['linkedin_client_secret', 'linkedin-client-secret', 'OAUTH_LINKEDIN_CLIENT_SECRET'];

    private const OAUTH_CALLBACK_PATH = '/oauth/linkedin/return';
    private const STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LinkedInClient $linkedInClient,
        private readonly ConfigService $configService,
        private readonly LoggerService $loggerService,
    ) {
    }

    public function buildAuthorizationPage(
        People $provider,
        string $apiBaseUrl,
        ?string $returnUrl = null,
        ?string $appDomain = null,
    ): array {
        $clientId = $this->resolveClientId($provider);
        if ($clientId === '') {
            return [
                'success' => false,
                'error' => 'missing_client_id',
                'message' => 'Client ID do LinkedIn nao configurado (linkedin_client_id).',
            ];
        }

        $appDomain = $this->normalizeOAuthDomain($appDomain);
        if ($appDomain === '') {
            return [
                'success' => false,
                'error' => 'missing_app_domain',
                'message' => 'Dominio da aplicacao nao informado para autorizacao LinkedIn.',
            ];
        }

        $redirectUri = $this->buildOAuthCallbackUrl($apiBaseUrl);
        $state = $this->encodeOAuthState([
            'provider_id' => $provider->getId(),
            'app_domain' => $appDomain,
            'redirect_uri' => $redirectUri,
            'return_url' => $this->normalizeReturnUrl($returnUrl),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'issued_at' => time(),
        ]);

        $auth = $this->linkedInClient->buildAuthorizationUrl(
            $clientId,
            $redirectUri,
            $state,
            LinkedInClient::DEFAULT_SCOPES
        );

        return [
            'success' => true,
            'state' => $state,
            'authorization_url' => $auth['authorization_url'],
            'scopes' => $auth['scopes'],
            'redirect_uri' => $redirectUri,
        ];
    }

    public function resolveOAuthAppDomain(string $state, ?string $callbackAppDomain = null): string
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            throw new \InvalidArgumentException('Estado OAuth invalido.');
        }

        $stateAppDomain = $this->normalizeOAuthDomain($payload['app_domain'] ?? null);
        $callbackAppDomain = $this->normalizeOAuthDomain($callbackAppDomain);
        if ($stateAppDomain === '') {
            throw new \InvalidArgumentException('Dominio da aplicacao ausente no estado OAuth.');
        }

        if ($callbackAppDomain !== '' && $callbackAppDomain !== $stateAppDomain) {
            throw new \InvalidArgumentException('Dominio do callback nao confere com o estado OAuth.');
        }

        return $stateAppDomain;
    }

    public function resolveOAuthReturnUrl(string $state): string
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            throw new \InvalidArgumentException('Estado OAuth invalido.');
        }

        return $this->normalizeReturnUrl($payload['return_url'] ?? null);
    }

    public function connectViaOAuthCode(
        string $code,
        string $state,
        string $redirectUri,
        ?string $callbackAppDomain = null,
    ): array {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            return [
                'success' => false,
                'error' => 'invalid_state',
                'message' => 'Estado OAuth invalido ou expirado.',
            ];
        }

        try {
            $this->resolveOAuthAppDomain($state, $callbackAppDomain);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'error' => $this->normalizeOAuthDomain($payload['app_domain'] ?? null) === ''
                    ? 'missing_app_domain'
                    : 'app_domain_mismatch',
                'message' => $exception->getMessage(),
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $provider = $this->entityManager->getRepository(People::class)->find((int) ($payload['provider_id'] ?? 0));
        if (!$provider instanceof People) {
            return [
                'success' => false,
                'error' => 'invalid_provider',
                'message' => 'Empresa da autorizacao nao encontrada.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $expectedRedirect = (string) ($payload['redirect_uri'] ?? '');
        if ($expectedRedirect !== '' && rtrim($redirectUri, '/') !== rtrim($expectedRedirect, '/')) {
            // Prefer the redirect URI bound in state (exact match required by LinkedIn).
            $redirectUri = $expectedRedirect;
        }

        $clientId = $this->resolveClientId($provider);
        $clientSecret = $this->resolveClientSecret($provider);
        if ($clientId === '' || $clientSecret === '') {
            return [
                'success' => false,
                'error' => 'missing_client_config',
                'message' => 'Client ID/secret do LinkedIn nao configurado.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $tokenResponse = $this->linkedInClient->exchangeAuthorizationCode(
            $clientId,
            $clientSecret,
            $code,
            $redirectUri
        );

        if (!empty($tokenResponse['_request_failed']) || empty($tokenResponse['access_token'])) {
            return [
                'success' => false,
                'error' => (string) ($tokenResponse['error'] ?? 'token_exchange_failed'),
                'message' => (string) ($tokenResponse['error_description'] ?? $tokenResponse['message'] ?? 'Falha ao trocar codigo OAuth.'),
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $accessToken = (string) $tokenResponse['access_token'];
        $refreshToken = isset($tokenResponse['refresh_token']) ? (string) $tokenResponse['refresh_token'] : '';
        $expiresIn = (int) ($tokenResponse['expires_in'] ?? 0);
        $scope = (string) ($tokenResponse['scope'] ?? LinkedInClient::DEFAULT_SCOPES);

        $userInfo = $this->linkedInClient->getUserInfo($accessToken);
        $memberId = is_array($userInfo) ? (string) ($userInfo['sub'] ?? '') : '';
        $memberName = is_array($userInfo)
            ? trim((string) ($userInfo['name'] ?? ($userInfo['given_name'] ?? '') . ' ' . ($userInfo['family_name'] ?? '')))
            : '';

        $this->writeConfigValue($provider, self::CONFIG_ACCESS_TOKEN, $accessToken);
        if ($refreshToken !== '') {
            $this->writeConfigValue($provider, self::CONFIG_REFRESH_TOKEN, $refreshToken);
        }
        if ($memberId !== '') {
            $this->writeConfigValue($provider, self::CONFIG_MEMBER_ID, $memberId);
        }
        if ($memberName !== '') {
            $this->writeConfigValue($provider, self::CONFIG_MEMBER_NAME, $memberName);
        }
        if ($expiresIn > 0) {
            $this->writeConfigValue($provider, self::CONFIG_EXPIRES_AT, (string) (time() + $expiresIn));
        }
        $this->writeConfigValue($provider, self::CONFIG_SCOPES, $scope);

        $this->loggerService->getLogger(self::APP_CONTEXT)->info('LinkedIn OAuth connected', [
            'provider_id' => $provider->getId(),
            'member_id' => $memberId,
            'scopes' => $scope,
        ]);

        return [
            'success' => true,
            'provider_id' => $provider->getId(),
            'member_id' => $memberId,
            'member_name' => $memberName,
            'scopes' => $scope,
            'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
        ];
    }

    /**
     * Connection status for a provider (no secret leakage).
     *
     * @return array<string, mixed>
     */
    public function getConnectionStatus(People $provider): array
    {
        $accessToken = $this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN);
        $memberId = $this->readConfigValue($provider, self::CONFIG_MEMBER_ID);
        $memberName = $this->readConfigValue($provider, self::CONFIG_MEMBER_NAME);
        $expiresAt = $this->readConfigValue($provider, self::CONFIG_EXPIRES_AT);
        $scopes = $this->readConfigValue($provider, self::CONFIG_SCOPES);
        $hasRefresh = $this->readConfigValue($provider, self::CONFIG_REFRESH_TOKEN) !== '';

        $connected = $accessToken !== '';
        $expired = false;
        if ($connected && $expiresAt !== '' && ctype_digit($expiresAt)) {
            $expired = (int) $expiresAt < time();
        }

        return [
            'connected' => $connected && !$expired,
            'has_token' => $connected,
            'expired' => $expired,
            'has_refresh_token' => $hasRefresh,
            'member_id' => $memberId !== '' ? $memberId : null,
            'member_name' => $memberName !== '' ? $memberName : null,
            'scopes' => $scopes !== '' ? $scopes : null,
            'expires_at' => $expiresAt !== '' && ctype_digit($expiresAt)
                ? (int) $expiresAt
                : null,
            'client_configured' => $this->resolveClientId($provider) !== ''
                && $this->resolveClientSecret($provider) !== '',
        ];
    }

    /**
     * Remove stored tokens for the provider (local disconnect; does not call LinkedIn revoke API).
     *
     * @return array{success: bool}
     */
    public function disconnect(People $provider): array
    {
        foreach ([
            self::CONFIG_ACCESS_TOKEN,
            self::CONFIG_REFRESH_TOKEN,
            self::CONFIG_MEMBER_ID,
            self::CONFIG_MEMBER_NAME,
            self::CONFIG_EXPIRES_AT,
            self::CONFIG_SCOPES,
        ] as $key) {
            $this->writeConfigValue($provider, $key, '');
        }

        $this->loggerService->getLogger(self::APP_CONTEXT)->info('LinkedIn OAuth disconnected', [
            'provider_id' => $provider->getId(),
        ]);

        return ['success' => true];
    }

    /**
     * Returns a usable access token, refreshing when possible.
     */
    public function getValidAccessToken(People $provider): ?string
    {
        $accessToken = $this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN);
        if ($accessToken === '') {
            return null;
        }

        $expiresAt = $this->readConfigValue($provider, self::CONFIG_EXPIRES_AT);
        $stillValid = $expiresAt === ''
            || !ctype_digit($expiresAt)
            || ((int) $expiresAt > time() + 60);

        if ($stillValid) {
            return $accessToken;
        }

        $refreshToken = $this->readConfigValue($provider, self::CONFIG_REFRESH_TOKEN);
        if ($refreshToken === '') {
            return null;
        }

        $clientId = $this->resolveClientId($provider);
        $clientSecret = $this->resolveClientSecret($provider);
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $tokenResponse = $this->linkedInClient->refreshAccessToken($clientId, $clientSecret, $refreshToken);
        if (!empty($tokenResponse['_request_failed']) || empty($tokenResponse['access_token'])) {
            return null;
        }

        $newAccess = (string) $tokenResponse['access_token'];
        $this->writeConfigValue($provider, self::CONFIG_ACCESS_TOKEN, $newAccess);
        if (!empty($tokenResponse['refresh_token'])) {
            $this->writeConfigValue($provider, self::CONFIG_REFRESH_TOKEN, (string) $tokenResponse['refresh_token']);
        }
        $expiresIn = (int) ($tokenResponse['expires_in'] ?? 0);
        if ($expiresIn > 0) {
            $this->writeConfigValue($provider, self::CONFIG_EXPIRES_AT, (string) (time() + $expiresIn));
        }

        return $newAccess;
    }

    public function buildOAuthCallbackUrl(string $apiBaseUrl): string
    {
        return rtrim($apiBaseUrl, '/') . self::OAUTH_CALLBACK_PATH;
    }

    private function resolveClientId(People $provider): string
    {
        foreach (self::CONFIG_CLIENT_ID_KEYS as $key) {
            $value = $this->readConfigValue($provider, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveClientSecret(People $provider): string
    {
        foreach (self::CONFIG_CLIENT_SECRET_KEYS as $key) {
            $value = $this->readConfigValue($provider, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function readConfigValue(People $provider, string $key): string
    {
        try {
            $value = $this->configService->getConfig($provider, $key);

            return trim((string) ($value ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function writeConfigValue(People $provider, string $key, string $value): void
    {
        try {
            $config = $this->configService->discoveryConfig($provider, $key);
            $config->setConfigValue($value);
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->loggerService->getLogger(self::APP_CONTEXT)->error('Failed to write LinkedIn config', [
                'key' => $key,
                'provider_id' => $provider->getId(),
                'exception' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeOAuthState(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, $this->stateSigningKey());

        return $encoded . '.' . $sig;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeOAuthState(string $state): ?array
    {
        $state = trim($state);
        if ($state === '' || !str_contains($state, '.')) {
            return null;
        }

        [$encoded, $sig] = explode('.', $state, 2);
        $expected = hash_hmac('sha256', $encoded, $this->stateSigningKey());
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $pad = 4 - (strlen($encoded) % 4);
        if ($pad < 4) {
            $encoded .= str_repeat('=', $pad);
        }

        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            if ($json === false) {
                return null;
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $issuedAt = (int) ($payload['issued_at'] ?? 0);
        if ($issuedAt > 0 && (time() - $issuedAt) > self::STATE_TTL_SECONDS) {
            return null;
        }

        return $payload;
    }

    private function stateSigningKey(): string
    {
        // Prefer APP_SECRET-like config; fall back to a deterministic module key (still HMAC-bound).
        $secret = trim((string) ($this->configService->getConfig('APP_SECRET') ?? ''));
        if ($secret === '') {
            $secret = 'linkedin-oauth-state-v1';
        }

        return $secret;
    }

    private function normalizeOAuthDomain(mixed $candidate): string
    {
        if (!is_string($candidate)) {
            return '';
        }

        $candidate = strtolower(trim($candidate));
        if ($candidate === '' || in_array($candidate, ['undefined', 'null', 'false'], true)) {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $candidate)) {
            $host = parse_url($candidate, PHP_URL_HOST);
            $candidate = is_string($host) ? $host : '';
        }

        $candidate = preg_replace('/[\/?#].*$/', '', $candidate) ?? '';
        $candidate = preg_replace('/[^a-z0-9.:-]/', '', $candidate) ?? '';

        return $candidate;
    }

    private function normalizeReturnUrl(mixed $candidate): string
    {
        if (!is_string($candidate)) {
            return '/integrations-page';
        }

        $candidate = trim($candidate);
        if ($candidate === '' || in_array(strtolower($candidate), ['undefined', 'null', 'false'], true)) {
            return '/integrations-page';
        }

        // Allow relative paths and https URLs only.
        if (str_starts_with($candidate, '/')) {
            return $candidate;
        }

        if (preg_match('#^https://#i', $candidate)) {
            return $candidate;
        }

        return '/integrations-page';
    }
}
