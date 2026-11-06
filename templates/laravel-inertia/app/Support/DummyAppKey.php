<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Refuses the dummy test-only APP_KEY in production.
 *
 * The suite boots on a fixed dummy key (tests/Support/SkipEnvFile.php hands it
 * out when nothing else sets one) so it runs without a .env in a bare
 * container. This guard makes sure that same value can never ship: when
 * APP_ENV is production and the key is still the dummy, booting fails loudly
 * instead of serving an app whose key anyone could guess.
 */
final class DummyAppKey
{
    /**
     * The dummy, test-only APP_KEY: base64 of 32 zero bytes.
     */
    public const VALUE = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /**
     * Throw when running in production on the dummy test key.
     *
     * @param  mixed  $key  The configured APP_KEY (config('app.key')).
     */
    public static function refuseIn(string $environment, mixed $key): void
    {
        if ($environment === 'production' && $key === self::VALUE) {
            throw new \RuntimeException(
                'APP_KEY is the dummy test key. Run php artisan key:generate and set a real APP_KEY.'
            );
        }
    }
}
