<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * HMAC-signed state parameter for the Google Marketing OAuth round-trip.
 * Mirrors the shape used by LinkedInService so the callback can validate the
 * tenant and scope set it was issued for.
 */
final class GoogleMarketingOAuthState
{
    private const TTL_SECONDS = 600;
    private const FALLBACK_KEY = 'google-marketing-oauth-state-v1';

    public function encode(array $payload): string
    {
        $payload['issued_at'] = time();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, $this->signingKey());

        return $encoded . '.' . $sig;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $state): ?array
    {
        $state = trim($state);
        if ($state === '' || !str_contains($state, '.')) {
            return null;
        }

        [$encoded, $sig] = explode('.', $state, 2);
        $expected = hash_hmac('sha256', $encoded, $this->signingKey());
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
        if ($issuedAt > 0 && (time() - $issuedAt) > self::TTL_SECONDS) {
            return null;
        }

        return $payload;
    }

    private function signingKey(): string
    {
        $secret = '';
        try {
            $secret = trim((string) ($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET') ?: ''));
        } catch (\Throwable) {
            $secret = '';
        }

        return $secret !== '' ? $secret : self::FALLBACK_KEY;
    }
}
