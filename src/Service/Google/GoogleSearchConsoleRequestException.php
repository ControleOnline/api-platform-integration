<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Thrown when the Search Console API refuses the request (missing site,
 * revoked consent, HTTP transport error).
 */
final class GoogleSearchConsoleRequestException extends \RuntimeException
{
}
