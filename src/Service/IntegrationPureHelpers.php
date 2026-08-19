<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\Integration;

/**
 * Pure helpers extracted from IntegrationService (no EM dependency).
 */
class IntegrationPureHelpers
{
    public const EPHEMERAL_QUEUE_NAMES = ['Websocket', 'PushNotification'];

    public static function isEphemeralQueue(Integration $integration): bool
    {
        foreach (self::EPHEMERAL_QUEUE_NAMES as $queueName) {
            if (strcasecmp((string) $integration->getQueueName(), $queueName) === 0) {
                return true;
            }
        }

        return false;
    }


    public static function extractManagerAndroidPushToken(Device $device): string
    {
        $metadata = $device->getMetadata();

        return trim((string) (
            $metadata['pushTokens']['manager']['android']['deviceToken'] ?? ''
        ));
    }

}
