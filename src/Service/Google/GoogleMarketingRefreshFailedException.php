<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * Refresh exchange against Google returned no access_token or a network error.
 * Usually means the refresh token was revoked and the user has to reconnect.
 */
final class GoogleMarketingRefreshFailedException extends \RuntimeException
{
}
