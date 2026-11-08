<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue worker mode
    |--------------------------------------------------------------------------
    |
    | How queued jobs get a worker. "scheduler": the scheduler starts a short
    | queue:work every minute (Citadel, where nothing supervises a long-lived
    | worker). "none": a supervisor or a separate container runs queue:work.
    |
    */

    'mode' => env('QUEUE_WORKER_MODE', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Scheduler heartbeat
    |--------------------------------------------------------------------------
    |
    | How old the scheduler's cache stamp may be before /ready (HELMET's
    | readiness route) answers 503. routes/console.php writes it every
    | minute; a stamp older than this means the scheduler is not running,
    | so the instance should not get traffic (WELD W23).
    | 0 turns the check off, for an app that runs no scheduler.
    |
    */

    'heartbeat_max_age' => (int) env('WORKER_HEARTBEAT_MAX_AGE', 180),

];
