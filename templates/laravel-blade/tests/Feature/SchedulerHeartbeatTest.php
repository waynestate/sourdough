<?php

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Scheduler heartbeat and /ready
|--------------------------------------------------------------------------
|
| A scheduler that stops is silent: jobs pile up and nothing complains.
| routes/console.php stamps the cache each minute, and /ready (HELMET's
| readiness route) answers 503 while the stamp is missing or older than
| worker.heartbeat_max_age seconds, so a stopped scheduler shows up
| instead of quietly dropping work (WELD W23). /up stays the liveness
| answer and never looks at the cache.
*/

function sourdoughHeartbeatEvent(Schedule $schedule): ?CallbackEvent
{
    foreach ($schedule->events() as $event) {
        if ($event instanceof CallbackEvent && $event->description === 'scheduler-heartbeat') {
            return $event;
        }
    }

    return null;
}

it('answers 503 on /ready when the scheduler has not written a heartbeat', function () {
    Cache::flush();

    $response = $this->get('/ready');

    $response->assertStatus(503);

    expect($response->json('status'))->toBe('not ready')
        ->and($response->json('checks.scheduler.status'))->toBe('fail');
});

it('answers 200 on /ready right after the heartbeat closure runs', function () {
    $event = sourdoughHeartbeatEvent($this->app->make(Schedule::class));

    if (! $event instanceof CallbackEvent) {
        $this->fail('routes/console.php must schedule a closure named scheduler-heartbeat');
    }

    $event->run($this->app);

    $this->get('/ready')->assertStatus(200);
});

it('answers 503 on /ready again once the clock moves past the max age', function () {
    $event = sourdoughHeartbeatEvent($this->app->make(Schedule::class));

    if (! $event instanceof CallbackEvent) {
        $this->fail('routes/console.php must schedule a closure named scheduler-heartbeat');
    }

    $event->run($this->app);

    $this->travel((int) config('worker.heartbeat_max_age', 180) + 60)->seconds();

    $this->get('/ready')->assertStatus(503);
});

it('answers 200 on /ready with no heartbeat when the check is turned off', function () {
    config(['worker.heartbeat_max_age' => 0]);

    Cache::flush();

    $this->get('/ready')->assertStatus(200);
});

it('schedules the heartbeat every minute under its own name', function () {
    $events = array_filter(
        $this->app->make(Schedule::class)->events(),
        fn (Event $event): bool => $event->description === 'scheduler-heartbeat',
    );

    expect($events)->toHaveCount(1);

    $event = array_values($events)[0];

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('* * * * *');
});
