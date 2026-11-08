<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Refuses APP_DEBUG in production.
 *
 * The debug page shows stack traces, config and environment values to
 * anyone who can reach the app, and nothing else stops an app from
 * booting in production with APP_DEBUG=true. When APP_ENV is production
 * and debug is on, booting fails loudly instead of serving an app that
 * leaks its insides.
 */
final class ProductionDebug
{
    /**
     * Throw when running in production with the debug page on.
     *
     * @param  mixed  $debug  The configured app.debug (config('app.debug')).
     */
    public static function refuseIn(string $environment, mixed $debug): void
    {
        if ($environment === 'production' && filter_var($debug, FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException(
                'APP_DEBUG is on in production. Set APP_DEBUG=false: the debug page shows stack traces and environment values to anyone.'
            );
        }
    }
}
