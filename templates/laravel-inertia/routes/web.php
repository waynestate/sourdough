<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
|
| / and /dashboard live here. SESAME owns the rest of the auth surface:
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

Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
    ->middleware(['auth.sesame'])
    ->name('dashboard');
