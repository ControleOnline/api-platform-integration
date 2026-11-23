<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Thrown when a Google Ads REST call fails (transport error, API error,
 * missing credentials, invalid GAQL).
 */
final class GoogleAdsRequestException extends \RuntimeException
{
}
