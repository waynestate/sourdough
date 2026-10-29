<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // HELMET's /up health route is Laravel's own; the adapter registers
        // no route of its own.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // SESAME owns sign-in at /login, so any guest Laravel's own guards
        // would bounce still lands on SESAME's door.
        $middleware->redirectGuestsTo('/login');

        // Trusted proxies come from HELMET, not from this file: the HELMET
        // provider copies helmet.proxies (HELMET_PROXIES, or the HELMET_PRESET
        // platform preset) into Laravel's trusted-proxy setting, so a
        // platform address never lives in the app's code.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
