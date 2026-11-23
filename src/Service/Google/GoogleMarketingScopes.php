<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Scope sets for Google Marketing APIs (Analytics, Ads, AdSense).
 *
 * Each set is authorized independently so a client company can connect only the
 * products it uses. Token rows are keyed per scope set in the Config table.
 */
final class GoogleMarketingScopes
{
    public const SET_ANALYTICS = 'analytics';
    public const SET_ADS = 'ads';
    public const SET_ADSENSE = 'adsense';
    public const SET_WEBMASTERS = 'webmasters';

    public const CONFIG_KEY_PREFIX = 'GOOGLE_SERVICE_';

    /**
     * @var array<string, list<string>>
     */
    private const SCOPES = [
        self::SET_ANALYTICS => [
            'https://www.googleapis.com/auth/analytics.readonly',
        ],
        self::SET_ADS => [
            'https://www.googleapis.com/auth/adwords',
        ],
        self::SET_ADSENSE => [
            'https://www.googleapis.com/auth/adsense.readonly',
        ],
        self::SET_WEBMASTERS => [
            'https://www.googleapis.com/auth/webmasters.readonly',
        ],
    ];

    public static function isValidSet(string $scopeSet): bool
    {
        return array_key_exists($scopeSet, self::SCOPES);
    }

    /**
     * @return list<string>
     */
    public static function scopesFor(string $scopeSet): array
    {
        if (!self::isValidSet($scopeSet)) {
            throw new \InvalidArgumentException(sprintf('Unknown Google Marketing scope set: %s', $scopeSet));
        }

        return self::SCOPES[$scopeSet];
    }

    public static function configKeyFor(string $scopeSet): string
    {
        if (!self::isValidSet($scopeSet)) {
            throw new \InvalidArgumentException(sprintf('Unknown Google Marketing scope set: %s', $scopeSet));
        }

        return self::CONFIG_KEY_PREFIX . strtoupper($scopeSet);
    }

    /**
     * @return list<string>
     */
    public static function allSets(): array
    {
        return array_keys(self::SCOPES);
    }
}
