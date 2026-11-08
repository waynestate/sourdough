<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Citadel has no queue worker, so the scheduler starts a short one each
// minute (QUEUE_WORKER_MODE=scheduler). See Squire's queue-workers guide.
if (config('worker.mode') === 'scheduler') {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(10);
}
