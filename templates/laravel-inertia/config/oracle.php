<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Oracle connection
    |--------------------------------------------------------------------------
    |
    | Sourdough wires Oracle into every app but leaves it dormant: while the
    | ORACLE_* settings are empty, nothing connects and nothing errors. yajra
    | laravel-oci8 merges this file into the database connections on boot, so
    | the skeleton's config/database.php stays untouched and its default
    | connection is never Oracle.
    |
    | Connect with either ORACLE_TNS (Easy Connect host:port/service or a
    | full descriptor) or ORACLE_HOST, ORACLE_PORT and ORACLE_SERVICE_NAME.
    | On Citadel, set them in the app's environment settings, never in a file
    | in git.
    |
    */

    'oracle' => [
        'driver' => 'oracle',
        'tns' => env('ORACLE_TNS', ''),
        'host' => env('ORACLE_HOST', ''),
        'port' => env('ORACLE_PORT', ''),
        'service_name' => env('ORACLE_SERVICE_NAME', ''),
        'username' => env('ORACLE_USERNAME', ''),
        'password' => env('ORACLE_PASSWORD', ''),
        'charset' => 'AL32UTF8',
        'prefix' => '',
        'prefix_schema' => '',
    ],
];
