<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\LoggerService;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Multi-tenant Google Marketing OAuth: connect / status / disconnect.
 * Separate from the login flow in api-platform-users: this flow asks for
 * offline access and persists refresh tokens per People + scope set.
 */
final class GoogleMarketingOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingOAuthState $state,
        private readonly GoogleMarketingTokenStore $tokenStore,
        private readonly LoggerService $logger,
    ) {
    }

    public function buildAuthorizationPage(
        People $provider,
        string $scopeSet,
        string $apiBaseUrl,
        ?string $returnUrl = null,
        ?string $appDomain = null,
    ): array {
        if (!GoogleMarketingScopes::isValidSet($scopeSet)) {
            return ['success' => false, 'error' => 'invalid_scope_set'];
        }

        $clientId = (string) ($_ENV['OAUTH_GOOGLE_CLIENT_ID'] ?? '');
        if ($clientId === '') {
            return [
                'success' => false,
                'error' => 'missing_client_id',
                'message' => 'OAUTH_GOOGLE_CLIENT_ID nao configurado.',
            ];
        }

        $redirectUri = rtrim($apiBaseUrl, '/') . '/integrations/google-marketing/callback';
        $state = $this->state->encode([
            'provider_id' => $provider->getId(),
            'scope_set' => $scopeSet,
            'redirect_uri' => $redirectUri,
            'app_domain' => $appDomain ?? '',
            'return_url' => $returnUrl ?: '/integrations-page',
            'nonce' => bin2hex(random_bytes(16)),
        ]);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'scope' => implode(' ', GoogleMarketingScopes::scopesFor($scopeSet)),
            'state' => $state,
        ]);

        return [
            'success' => true,
            'state' => $state,
            'authorization_url' => self::AUTH_URL . '?' . $query,
            'scopes' => GoogleMarketingScopes::scopesFor($scopeSet),
            'redirect_uri' => $redirectUri,
        ];
    }

    /**
     * Exchange the authorization code for tokens and persist them.
     *
     * @return array{success:bool, provider_id?:int, scope_set?:string, return_url?:string, error?:string}
     */
    public function handleCallback(string $code, string $state, callable $peopleResolver): array
    {
        $payload = $this->state->decode($state);
        if ($payload === null) {
            return ['success' => false, 'error' => 'invalid_state'];
        }

        $scopeSet = (string) ($payload['scope_set'] ?? '');
        if (!GoogleMarketingScopes::isValidSet($scopeSet)) {
            return ['success' => false, 'error' => 'invalid_scope_set'];
        }

        $provider = $peopleResolver((int) ($payload['provider_id'] ?? 0));
        if (!$provider instanceof People) {
            return ['success' => false, 'error' => 'provider_not_found'];
        }

        try {
            $token = $this->exchangeCode($code, (string) ($payload['redirect_uri'] ?? ''));
        } catch (HttpClientException $e) {
            $this->logger->error('google-marketing.exchange_code_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'token_exchange_failed'];
        }

        $this->tokenStore->save($provider, $scopeSet, [
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'access_token' => (string) ($token['access_token'] ?? ''),
            'expires_at' => time() + (int) ($token['expires_in'] ?? 0),
            'scopes' => explode(' ', (string) ($token['scope'] ?? '')),
        ]);

        return [
            'success' => true,
            'provider_id' => $provider->getId(),
            'scope_set' => $scopeSet,
            'return_url' => (string) ($payload['return_url'] ?? '/integrations-page'),
        ];
    }

    public function getConnectionStatus(People $provider, string $scopeSet): array
    {
        $token = $this->tokenStore->read($provider, $scopeSet);
        if ($token === null || $token['refresh_token'] === '') {
            return ['connected' => false, 'scope_set' => $scopeSet];
        }

        return [
            'connected' => true,
            'scope_set' => $scopeSet,
            'scopes' => $token['scopes'],
            'connected_at' => $token['connected_at'],
            'updated_at' => $token['updated_at'],
        ];
    }

    public function disconnect(People $provider, string $scopeSet): array
    {
        $token = $this->tokenStore->read($provider, $scopeSet);
        if ($token === null) {
            return ['success' => true, 'already_disconnected' => true];
        }

        if ($token['refresh_token'] !== '') {
            try {
                $this->httpClient->request(
                    'POST',
                    self::REVOKE_URL . '?token=' . rawurlencode($token['refresh_token'])
                )->getStatusCode();
            } catch (HttpClientException $e) {
                $this->logger->info('google-marketing.revoke_failed', ['error' => $e->getMessage()]);
            }
        }

        $this->tokenStore->delete($provider, $scopeSet);

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'code' => $code,
                'client_id' => $_ENV['OAUTH_GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] ?? '',
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
        ]);

        $decoded = json_decode($response->getContent(false), true);

        return is_array($decoded) ? $decoded : [];
    }
}
