<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Routes Ziggy leaves out of the route list
    |--------------------------------------------------------------------------
    |
    | Boost registers its routes (boost.*) only where the dev package is
    | installed. Left in, the committed route list differs between a laptop
    | and CI. Keep them out so `ziggy:generate` gives one answer everywhere.
    |
    */

    'except' => ['boost.*'],

];
