<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HELMET readiness check: is the scheduler alive?
 *
 * routes/console.php stamps the cache every minute under
 * SchedulerHeartbeat::KEY. When the stamp is missing, or older than
 * worker.heartbeat_max_age seconds, the scheduler is presumed stopped and
 * /ready answers 503, so the balancer skips this instance instead of
 * feeding it jobs nobody will run (WELD W23). A sourdough starter that
 * stops bubbling is dead; same idea.
 */
final class SchedulerHeartbeat
{
    /**
     * The cache key the scheduler stamps every minute.
     */
    public const string KEY = 'scheduler:heartbeat';

    public function __invoke(): bool
    {
        $timestamp = Cache::get(self::KEY);

        if ($timestamp === null) {
            Log::warning('Scheduler heartbeat is missing: the scheduler has not written scheduler:heartbeat.');

            return false;
        }

        $maxAge = (int) config('worker.heartbeat_max_age', 180);
        $age = now()->getTimestamp() - (int) $timestamp;

        if ($age > $maxAge) {
            Log::warning("Scheduler heartbeat is {$age} seconds old; the maximum is {$maxAge}.");

            return false;
        }

        return true;
    }
}
