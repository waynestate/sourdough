<?php

use App\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler announces itself: each minute it stamps the cache, and
// /ready (HELMET's readiness route) answers 503 while the stamp is missing
// or older than worker.heartbeat_max_age seconds, so a stopped scheduler
// shows up instead of quietly dropping jobs (WELD W23).
Schedule::call(fn () => Cache::put(SchedulerHeartbeat::KEY, now()->getTimestamp()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Citadel has no queue worker, so the scheduler starts a short one each
// minute (QUEUE_WORKER_MODE=scheduler). See Squire's queue-workers guide.
// --timeout stays below the connection's retry_after (WELD W20), or a slow job runs twice.
if (config('worker.mode') === 'scheduler') {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --timeout=45 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(10);
}
