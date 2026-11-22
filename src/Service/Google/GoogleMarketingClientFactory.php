<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Returns a valid access token for a People + scope set, refreshing it from the
 * stored refresh_token when near expiry. Downstream marketing services (GA4,
 * Ads, AdSense) use this factory to authenticate their API calls without each
 * having to replicate the refresh dance.
 */
final class GoogleMarketingClientFactory
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const EXPIRY_SKEW_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleMarketingTokenStore $tokenStore,
    ) {
    }

    /**
     * @throws GoogleMarketingNotConnectedException
     * @throws GoogleMarketingRefreshFailedException
     */
    public function accessTokenFor(People $provider, string $scopeSet): string
    {
        $token = $this->tokenStore->read($provider, $scopeSet);
        if ($token === null || $token['refresh_token'] === '') {
            throw new GoogleMarketingNotConnectedException($scopeSet);
        }

        if ($token['access_token'] !== '' && $token['expires_at'] > time() + self::EXPIRY_SKEW_SECONDS) {
            return $token['access_token'];
        }

        $refreshed = $this->refresh($token['refresh_token']);
        $this->tokenStore->save($provider, $scopeSet, [
            'refresh_token' => $token['refresh_token'],
            'access_token' => (string) ($refreshed['access_token'] ?? ''),
            'expires_at' => time() + (int) ($refreshed['expires_in'] ?? 0),
            'scopes' => $token['scopes'],
        ]);

        return (string) ($refreshed['access_token'] ?? '');
    }

    public function isConnected(People $provider, string $scopeSet): bool
    {
        $token = $this->tokenStore->read($provider, $scopeSet);
        return $token !== null && $token['refresh_token'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(string $refreshToken): array
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'client_id' => $_ENV['OAUTH_GOOGLE_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'] ?? '',
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]),
            ]);
            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpClientException $e) {
            throw new GoogleMarketingRefreshFailedException($e->getMessage(), 0, $e);
        }

        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new GoogleMarketingRefreshFailedException('No access_token in Google response');
        }

        return $decoded;
    }
}
