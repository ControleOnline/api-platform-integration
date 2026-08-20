<?php

namespace ControleOnline\Service\Marketplace;

/**
 * Canonical commercial-core contract for external marketplace orders (T13).
 *
 * - channel is always external for platform integrations (iFood, Food99, …).
 * - Platform identity stays on app / integrationKey / showcase config.
 * - Fulfillment maps to the shared Order FULFILLMENT_* vocabulary.
 * - Sale remains orderType=sale; logistics run uses orderType=delivery separately.
 */
final class ExternalOrderContract
{
    public const CHANNEL_EXTERNAL = 'external';

    public const FULFILLMENT_DINE_IN = 'dine_in';
    public const FULFILLMENT_PICKUP = 'pickup';
    public const FULFILLMENT_COUNTER = 'counter';
    public const FULFILLMENT_DELIVERY = 'delivery';
    public const FULFILLMENT_SHIPPING = 'shipping';

    public const FULFILLMENT_TYPES = [
        self::FULFILLMENT_DINE_IN,
        self::FULFILLMENT_PICKUP,
        self::FULFILLMENT_COUNTER,
        self::FULFILLMENT_DELIVERY,
        self::FULFILLMENT_SHIPPING,
    ];

    /**
     * Map provider-specific delivery / order type tokens to canonical fulfillment.
     *
     * @param array<string, mixed> $context optional keys: order_type, delivery_type,
     *        fulfillment_mode, delivered_by, delivery_mode, takeout_mode, has_delivery_address
     */
    public static function resolveFulfillmentType(array $context = []): string
    {
        $candidates = [
            $context['fulfillment_type'] ?? null,
            $context['fulfillment_mode'] ?? null,
            $context['delivery_type'] ?? null,
            $context['order_type'] ?? null,
            $context['takeout_mode'] ?? null,
            $context['delivery_mode'] ?? null,
        ];

        foreach ($candidates as $raw) {
            $mapped = self::mapTokenToFulfillment($raw);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $hasAddressHint = !empty($context['has_delivery_address'])
            || !empty($context['delivery_address'])
            || self::normalizeToken($context['delivered_by'] ?? null) !== '';

        return $hasAddressHint ? self::FULFILLMENT_DELIVERY : self::FULFILLMENT_PICKUP;
    }

    /**
     * Build idempotent webhook event key: provider:externalEventId.
     */
    public static function buildWebhookEventId(string $provider, string $externalEventId): string
    {
        $providerKey = strtolower(trim($provider));
        $eventId = trim($externalEventId);
        if ($providerKey === '' || $eventId === '') {
            throw new \InvalidArgumentException('provider and externalEventId are required for webhook idempotency');
        }

        return $providerKey . ':' . $eventId;
    }

    private static function mapTokenToFulfillment(mixed $value): ?string
    {
        $normalized = self::normalizeToken($value);
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, self::FULFILLMENT_TYPES, true)) {
            return $normalized;
        }

        return match ($normalized) {
            'takeout', 'take_out', 'pickup_instore', 'pickup_in_store',
            'merchant_takeout', 'retirada', 'to_go' => self::FULFILLMENT_PICKUP,
            'dinein', 'dine_in', 'indoor', 'on_premise', 'mesa' => self::FULFILLMENT_DINE_IN,
            'counter', 'balcao', 'balcony' => self::FULFILLMENT_COUNTER,
            'delivery', 'merchant', 'marketplace', 'ifood', 'food99',
            'platform', 'own_delivery', 'self_delivery' => self::FULFILLMENT_DELIVERY,
            'shipping', 'ship', 'postal' => self::FULFILLMENT_SHIPPING,
            default => null,
        };
    }

    private static function normalizeToken(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        $token = strtolower(trim((string) $value));
        $token = str_replace(['-', ' '], '_', $token);

        return $token;
    }
}
