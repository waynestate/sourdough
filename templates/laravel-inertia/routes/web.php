<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
|
| / and the signed-in landing page live here; its path and route name come
| from config/home.php, so renaming the starter Dashboard is a one-file
| edit. SESAME owns the rest of the auth surface:
| with sesame.routes left at its default (true), the adapter registers
| GET /login (sesame.login) and POST /logout (sesame.logout). A CAS ticket
| comes back to the app's service URL, which defaults to that same /login
| route, so there is no separate callback route to define. Set
| SESAME_ROUTES=false only if you take those two routes over into this
| file, and keep their names, because auth.sesame points at sesame.login.
*/

// /ready, HELMET's readiness probe, is registered in bootstrap/app.php,
// outside the web group: a probe carries no session and no cookies.

Route::get('/', fn () => Inertia::render('Home'))->name('home');

// The signed-in landing page: path and route name from config/home.php, so
// an app that renames the starter Dashboard edits one file, not three.
Route::get(config('home.path'), fn () => Inertia::render('Dashboard'))
    ->middleware(['auth.sesame'])
    ->name(config('home.route'));
