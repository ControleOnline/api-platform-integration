<?php

declare(strict_types=1);

namespace ControleOnline\Service\Client;

/** Magazine Luiza / Magalu marketplace API client foundation. */
class MagaluClient
{
    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state): array
    {
        $host = trim((string) ($_ENV['OAUTH_MAGALU_AUTH_HOST'] ?? $_SERVER['OAUTH_MAGALU_AUTH_HOST'] ?? ''))
            ?: 'https://id.magalu.com';

        return [
            'authorization_url' => rtrim($host, '/') . '/oauth/authorize?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'state' => $state,
            ]),
        ];
    }
}
