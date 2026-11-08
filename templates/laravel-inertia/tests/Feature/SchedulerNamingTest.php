<?php

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

/*
|--------------------------------------------------------------------------
| Named scheduled closures
|--------------------------------------------------------------------------
|
| With more than one scheduler (several containers, or a CronJob plus a
| worker), Laravel's onOneServer() and withoutOverlapping() lock on the
| task's name. A closure scheduled with Schedule::call() has no name
| unless it is given one with ->name('...'): Laravel then refuses
| onOneServer() with a LogicException at run time, or two servers run
| the task twice (WELD W21).
*/

function sourdoughUnnamedScheduledClosures(Schedule $schedule): array
{
    $unnamed = array_filter(
        $schedule->events(),
        fn (Event $event): bool => $event instanceof CallbackEvent && blank($event->description),
    );

    return array_values(array_map(fn (Event $event) => $event->expression, $unnamed));
}

it('names every scheduled closure', function () {
    // Bootstrap the console kernel so routes/console.php and any
    // withSchedule() entries are registered before we read the events.
    $this->app->make(Kernel::class)->bootstrap();

    $unnamed = sourdoughUnnamedScheduledClosures($this->app->make(Schedule::class));

    expect($unnamed)->toBe([], 'Add ->name(...) to each unnamed scheduled closure: '.implode(', ', $unnamed));
});

it('catches an unnamed closure', function () {
    $schedule = new Schedule;

    $schedule->call(fn () => null);

    expect(sourdoughUnnamedScheduledClosures($schedule))->toHaveCount(1);

    $schedule->call(fn () => null)->name('named');

    expect(sourdoughUnnamedScheduledClosures($schedule))->toHaveCount(1);
});
