<?php

use App\Health\SchedulerHeartbeat;
use Illuminate\Support\Facades\Route;
use Waynestate\Sesame\Sesame;

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

// HELMET's readiness probe: /ready answers 503 while the scheduler's
// heartbeat is stale, so a stopped scheduler shows up (WELD W23). It sits
// outside the auth middleware, like Citadel's own probes, and /up stays
// the liveness answer.
Route::helmetReady('/ready', ['scheduler' => SchedulerHeartbeat::class]);

Route::get('/', fn () => view('home'))->name('home');

Route::get('/dashboard', fn () => view('dashboard', ['user' => app(Sesame::class)->user()]))
    ->middleware(['auth.sesame'])
    ->name('dashboard');
