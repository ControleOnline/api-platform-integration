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

    public const DEFAULT_SCOPES = 'openid profile email w_member_social r_organization_social w_organization_social rw_organization_admin';

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


    /**
     * OpenID userinfo + lite person fields when available.
     *
     * @return array<string, mixed>
     */
    public function getProfile(string $accessToken): array
    {
        $userInfo = $this->getUserInfo($accessToken) ?? [];
        $profile = [
            'sub' => (string) ($userInfo['sub'] ?? ''),
            'name' => (string) ($userInfo['name'] ?? ''),
            'given_name' => (string) ($userInfo['given_name'] ?? ''),
            'family_name' => (string) ($userInfo['family_name'] ?? ''),
            'email' => (string) ($userInfo['email'] ?? ''),
            'picture' => (string) ($userInfo['picture'] ?? ''),
            'locale' => $userInfo['locale'] ?? null,
            'email_verified' => $userInfo['email_verified'] ?? null,
        ];

        // Best-effort /v2/me (may 403 depending on product access)
        try {
            $response = $this->httpClient->request('GET', 'https://api.linkedin.com/v2/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => [
                    'projection' => '(id,localizedFirstName,localizedLastName,localizedHeadline,vanityName,profilePicture(displayImage~:playableStreams))',
                ],
            ]);
            if ($response->getStatusCode() < 300) {
                $profile['me'] = $this->decodeJson($response->getContent(false));
            } else {
                $profile['me_error'] = 'HTTP ' . $response->getStatusCode();
            }
        } catch (\Throwable $e) {
            $profile['me_error'] = $e->getMessage();
        }

        return $profile;
    }

    /**
     * Organizations the member can administer (requires rw_organization_admin).
     *
     * @return array{organizations: list<array<string, mixed>>, error?: string}
     */
    public function listAdministeredOrganizations(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.linkedin.com/v2/organizationalEntityAcls', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => [
                    'q' => 'roleAssignee',
                    'role' => 'ADMINISTRATOR',
                    'state' => 'APPROVED',
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $this->decodeJson($response->getContent(false));
            if ($status >= 300) {
                return [
                    'organizations' => [],
                    'error' => (string) ($body['message'] ?? ('HTTP ' . $status)),
                    'hint' => 'Requires Community Management API product and rw_organization_admin scope. Re-authorize after enabling the product on LinkedIn Developers.',
                ];
            }

            $elements = is_array($body['elements'] ?? null) ? $body['elements'] : [];
            $orgs = [];
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $orgs[] = [
                    'organization' => (string) ($el['organizationalTarget'] ?? $el['organization'] ?? ''),
                    'role' => (string) ($el['role'] ?? ''),
                    'state' => (string) ($el['state'] ?? ''),
                ];
            }

            return ['organizations' => $orgs];
        } catch (\Throwable $e) {
            return [
                'organizations' => [],
                'error' => $e->getMessage(),
                'hint' => 'Requires Community Management API + rw_organization_admin.',
            ];
        }
    }

    /**
     * Modern Posts API (member or organization author URN).
     *
     * @return array{id?: string, error?: string, status?: int}
     */
    public function createRestPost(string $accessToken, string $authorUrn, string $text): array
    {
        $payload = [
            'author' => $authorUrn,
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $response = $this->httpClient->request('POST', 'https://api.linkedin.com/rest/posts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => '202503',
            ],
            'json' => $payload,
        ]);

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $body = $this->decodeJson($raw);
        if ($status >= 200 && $status < 300) {
            $id = (string) ($body['id'] ?? $response->getHeaders(false)['x-restli-id'][0] ?? $response->getHeaders(false)['x-linkedin-id'][0] ?? '');
            return ['id' => $id !== '' ? $id : 'created', 'status' => $status];
        }

        return [
            'error' => (string) ($body['message'] ?? $body['error'] ?? ('LinkedIn HTTP ' . $status)),
            'status' => $status,
            'raw' => is_array($body) ? $body : ['body' => substr($raw, 0, 500)],
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
