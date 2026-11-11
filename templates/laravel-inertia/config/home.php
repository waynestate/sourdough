<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The signed-in landing page
    |--------------------------------------------------------------------------
    |
    | The route name and the path it lives at. Change both here when the app
    | renames or replaces the starter Dashboard; routes, shared props and the
    | skeleton tests read them, so one edit moves the whole loaf.
    |
    */

    'route' => env('APP_HOME_ROUTE', 'dashboard'),
    'path' => env('APP_HOME_PATH', '/dashboard'),

];
