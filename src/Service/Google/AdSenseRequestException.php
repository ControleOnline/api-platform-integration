<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Thrown when the AdSense Management API refuses the request (missing account,
 * revoked consent, HTTP transport error).
 */
final class AdSenseRequestException extends \RuntimeException
{
}
