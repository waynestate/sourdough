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

];
