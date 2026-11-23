<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;

/**
 * Pulls Google Ads customer ids per People and the shared developer_token
 * from the main company. Isolates the config reads so each Ads service stays
 * focused on the API it talks to.
 */
final class GoogleAdsCustomerResolver
{
    public const CONFIG_CUSTOMER = 'GOOGLE_ADS_CUSTOMER';
    public const CONFIG_DEVELOPER_TOKEN = 'GOOGLE_ADS_DEVELOPER_TOKEN';

    public function __construct(private readonly ConfigService $configService)
    {
    }

    /**
     * @return array{customer_id:string, login_customer_id:string}
     */
    public function customerFor(People $people): array
    {
        $raw = $this->configService->getConfig($people, self::CONFIG_CUSTOMER);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $customerId = '';
        $loginCustomerId = '';

        if (is_array($decoded)) {
            $customerId = trim((string) ($decoded['customer_id'] ?? ''));
            $loginCustomerId = trim((string) ($decoded['login_customer_id'] ?? ''));
        } elseif (is_string($raw)) {
            $customerId = trim($raw);
        }

        if ($customerId === '') {
            throw new \RuntimeException('GOOGLE_ADS_CUSTOMER not configured for this company.');
        }

        return [
            'customer_id' => self::normalizeId($customerId),
            'login_customer_id' => $loginCustomerId !== '' ? self::normalizeId($loginCustomerId) : self::normalizeId($customerId),
        ];
    }

    public function developerToken(People $mainCompany): string
    {
        $raw = $this->configService->getConfig($mainCompany, self::CONFIG_DEVELOPER_TOKEN);
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            throw new \RuntimeException('GOOGLE_ADS_DEVELOPER_TOKEN not configured on the main company.');
        }

        return $value;
    }

    private static function normalizeId(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }
}
