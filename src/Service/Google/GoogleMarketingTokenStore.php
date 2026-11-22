<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;

/**
 * Persists Google Marketing refresh/access tokens per People + scope set.
 * Values are stored as a JSON payload under GOOGLE_SERVICE_<SCOPE_SET>.
 */
final class GoogleMarketingTokenStore
{
    public function __construct(private readonly ConfigService $configService)
    {
    }

    public function save(People $people, string $scopeSet, array $token): void
    {
        $key = GoogleMarketingScopes::configKeyFor($scopeSet);
        $existing = $this->readRaw($people, $key);

        $payload = [
            'refresh_token' => $token['refresh_token']
                ?? ($existing['refresh_token'] ?? ''),
            'access_token' => (string) ($token['access_token'] ?? ''),
            'expires_at' => (int) ($token['expires_at'] ?? 0),
            'scopes' => $token['scopes']
                ?? GoogleMarketingScopes::scopesFor($scopeSet),
            'connected_at' => $existing['connected_at'] ?? gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $this->configService->addConfig(
            $people,
            $key,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->module(),
            'private'
        );
    }

    /**
     * @return array{refresh_token:string,access_token:string,expires_at:int,scopes:list<string>,connected_at:string,updated_at:string}|null
     */
    public function read(People $people, string $scopeSet): ?array
    {
        $raw = $this->readRaw($people, GoogleMarketingScopes::configKeyFor($scopeSet));
        if ($raw === null) {
            return null;
        }

        return [
            'refresh_token' => (string) ($raw['refresh_token'] ?? ''),
            'access_token' => (string) ($raw['access_token'] ?? ''),
            'expires_at' => (int) ($raw['expires_at'] ?? 0),
            'scopes' => is_array($raw['scopes'] ?? null) ? array_values($raw['scopes']) : [],
            'connected_at' => (string) ($raw['connected_at'] ?? ''),
            'updated_at' => (string) ($raw['updated_at'] ?? ''),
        ];
    }

    public function delete(People $people, string $scopeSet): bool
    {
        $existing = $this->read($people, $scopeSet);
        if ($existing === null) {
            return false;
        }

        // Overwrite with an empty JSON object so downstream reads return "not connected"
        // without requiring a separate repository remove; keeps the audit row.
        $this->configService->addConfig(
            $people,
            GoogleMarketingScopes::configKeyFor($scopeSet),
            '{}',
            $this->module(),
            'private'
        );

        return true;
    }

    private function module(): \ControleOnline\Entity\Module
    {
        return $this->configService->discoveryModule('marketing-google', '$primary', 'campaign');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRaw(People $people, string $key): ?array
    {
        $value = $this->configService->getConfig($people, $key);
        if ($value === null || $value === '' || $value === '{}') {
            return null;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
