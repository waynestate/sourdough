<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;

/*
|--------------------------------------------------------------------------
| Queue worker from the scheduler
|--------------------------------------------------------------------------
|
| Citadel runs no queue worker: nothing supervises a long-lived
| queue:work process. With QUEUE_WORKER_MODE=scheduler (config/worker.php),
| routes/console.php schedules a short queue:work every minute instead
| (Squire's queue-workers guide, WELD W19); with QUEUE_WORKER_MODE=none
| the queue is left to a supervisor or a separate container.
*/

function sourdoughQueueWorkerEvents(Schedule $schedule): array
{
    return array_values(array_filter(
        $schedule->events(),
        fn (Event $event): bool => str_contains($event->command ?? '', 'queue:work'),
    ));
}

function sourdoughConsoleSchedule(string $mode): Schedule
{
    // The console kernel bootstraps once per test app (tests/TestCase.php),
    // and routes/console.php is read when it boots. Hand the container a
    // fresh Schedule, point the Schedule facade at it, set the mode, then
    // read the file again so its events land on our instance.
    app()->make(Kernel::class)->bootstrap();

    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);
    Facade::clearResolvedInstances();

    config(['worker.mode' => $mode]);
    require base_path('routes/console.php');

    return $schedule;
}

it('schedules a short queue worker every minute in scheduler mode', function () {
    $events = sourdoughConsoleSchedule('scheduler')->events();

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->command)->toContain('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});

it('schedules no queue worker in none mode', function () {
    expect(sourdoughQueueWorkerEvents(sourdoughConsoleSchedule('none')))->toBe([]);
});
