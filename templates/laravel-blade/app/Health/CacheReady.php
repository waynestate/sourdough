<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * HELMET readiness check: can the app write to its cache and read it back?
 *
 * A round trip on the default store, under a key of its own so two probes at
 * once never read each other's value. A store that throws (a lost Redis, a
 * database cache with no database) fails the check, and so does one that
 * forgets what it was just told, because sessions, locks and the scheduler
 * heartbeat all lean on it (WELD W11). The key lives ten seconds at most and
 * is removed straight away; like a good starter, it gets fed and then left
 * alone.
 */
final class CacheReady
{
    public function __invoke(): bool
    {
        $store = Cache::store();
        $key = 'ready:probe:'.Str::random(16);

        $store->put($key, true, 10);
        $readBack = $store->get($key) === true;
        $store->forget($key);

        return $readBack;
    }
}
