<?php

declare(strict_types=1);

namespace ControleOnline\Service\Client;

use Psr\Log\LoggerInterface;

/** Amazon Selling Partner API client foundation. @see https://developer-docs.amazon.com/sp-api/ */
class AmazonSpClient
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function buildAuthorizationUrl(string $applicationId, string $redirectUri, string $state): array
    {
        $host = trim((string) ($_ENV['OAUTH_AMAZON_AUTH_HOST'] ?? $_SERVER['OAUTH_AMAZON_AUTH_HOST'] ?? ''))
            ?: 'https://sellercentral.amazon.com.br';

        return [
            'authorization_url' => rtrim($host, '/') . '/apps/authorize/consent?' . http_build_query([
                'application_id' => $applicationId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ]),
        ];
    }
}
