<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Thrown when the GA4 Data API refuses the request (quota, missing property,
 * revoked consent, HTTP transport error).
 */
final class GoogleAnalyticsRequestException extends \RuntimeException
{
}
