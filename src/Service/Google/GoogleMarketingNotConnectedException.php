<?php

declare(strict_types=1);

namespace ControleOnline\Service\Google;

/**
 * The People has no stored refresh token for the requested scope set.
 * Thrown by the client factory so downstream services can return a structured
 * 401 asking the UI to send the user back through the connect flow.
 */
final class GoogleMarketingNotConnectedException extends \RuntimeException
{
    public function __construct(public readonly string $scopeSet)
    {
        parent::__construct(sprintf('Google Marketing not connected for scope set "%s".', $scopeSet));
    }
}
