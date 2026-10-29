<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP client for LinkedIn OAuth 2.0 and userinfo.
 * Official endpoints only (OpenID + Share on LinkedIn product).
 */
class LinkedInClient
{
    private const AUTHORIZATION_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
    private const INTROSPECT_URL = 'https://www.linkedin.com/oauth/v2/introspectToken';

    public const DEFAULT_SCOPES = 'openid profile email w_member_social';

    private ?LoggerInterface $logger = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerService $loggerService = null,
    ) {
        $this->logger = $this->loggerService?->getLogger('LinkedIn');
    }

    /**
     * @return array{authorization_url: string, scopes: string}
     */
    public function buildAuthorizationUrl(
        string $clientId,
        string $redirectUri,
        string $state,
        string $scopes = self::DEFAULT_SCOPES,
    ): array {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scopes,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => self::AUTHORIZATION_URL . '?' . $query,
            'scopes' => $scopes,
        ];
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
    ): array {
        return $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
    }

    /**
     * Refresh access token.
     *
     * @return array<string, mixed>
     */
    public function refreshAccessToken(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
    ): array {
        return $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
    }

    /**
     * OpenID userinfo (sub, name, email, picture, …).
     *
     * @return array<string, mixed>|null
     */
    public function getUserInfo(string $accessToken): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::USERINFO_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            $status = $response->getStatusCode();
            $body = $this->decodeJson((string) $response->getContent(false));
            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('LinkedIn userinfo failed', [
                    'status' => $status,
                    'body' => $body,
                ]);

                return null;
            }

            return $body;
        } catch (\Throwable $exception) {
            $this->logger?->error('LinkedIn userinfo exception', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     */

    /**
     * Create a personal text share (UGC Posts API).
     * Requires scope w_member_social and member URN person:{id}.
     *
     * @return array{id?: string, error?: string, status?: int}
     */
    public function createTextPost(string $accessToken, string $authorUrn, string $text): array
    {
        $payload = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $text,
                    ],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = $this->httpClient->request('POST', 'https://api.linkedin.com/v2/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'json' => $payload,
        ]);

        $status = $response->getStatusCode();
        $body = $this->decodeJson($response->getContent(false));
        if ($status >= 200 && $status < 300) {
            return ['id' => (string) ($body['id'] ?? ''), 'status' => $status];
        }

        return [
            'error' => (string) ($body['message'] ?? $body['error'] ?? ('LinkedIn HTTP ' . $status)),
            'status' => $status,
        ];
    }

    private function postToken(array $body): array
    {
        try {
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
            $decoded = $this->decodeJson((string) $response->getContent(false));
            if ($status < 200 || $status >= 300) {
                $this->logger?->warning('LinkedIn token request failed', [
                    'status' => $status,
                    'body' => $decoded,
                ]);

                return array_merge($decoded, [
                    'status' => $status,
                    '_request_failed' => true,
                ]);
            }

            return $decoded;
        } catch (\Throwable $exception) {
            $this->logger?->error('LinkedIn token request exception', ['exception' => $exception]);

            return [
                'error' => 'request_exception',
                'message' => $exception->getMessage(),
                '_request_failed' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return ['raw' => $content];
        }
    }
}
