<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| /ready checks the database and the cache
|--------------------------------------------------------------------------
|
| /up is liveness: it answers 200 whenever the app boots and never looks
| at the database, so a database blip cannot restart every container.
| /ready is the other question, "send me traffic?": HELMET's readiness
| route with the app's checks, the database and the cache among them
| (WELD W11). It lives outside the web group, so a probe writes no
| session and sets no cookie.
*/

beforeEach(function () {
    // The scheduler heartbeat has its own test; keep it out of the way here.
    config(['worker.heartbeat_max_age' => 0]);
});

it('answers 200 on /ready when the database and the cache answer', function () {
    $response = $this->get('/ready');

    $response->assertStatus(200);

    expect($response->json('status'))->toBe('ready')
        ->and($response->json('checks.database.status'))->toBe('pass')
        ->and($response->json('checks.cache.status'))->toBe('pass');
});

it('answers 503 on /ready when the database cannot be reached', function () {
    $default = config('database.default');

    config([
        'database.connections.ready_broken' => [
            'driver' => 'sqlite',
            'database' => storage_path('framework/testing/missing-'.Str::random(12).'.sqlite'),
            'prefix' => '',
        ],
        'database.default' => 'ready_broken',
    ]);

    $response = $this->get('/ready');

    // Put the real connection back before asserting, so RefreshDatabase
    // rolls back the connection it opened, whatever happens below.
    config(['database.default' => $default]);

    $response->assertStatus(503);

    expect($response->json('status'))->toBe('not ready')
        ->and($response->json('checks.database.status'))->toBe('fail')
        ->and($response->getContent())->not->toContain('missing-');
});

it('answers 503 on /ready when the cache store fails', function () {
    $default = config('cache.default');

    config([
        'cache.stores.ready_broken' => ['driver' => 'ready-broken'],
        'cache.default' => 'ready_broken',
    ]);

    $response = $this->get('/ready');

    config(['cache.default' => $default]);

    $response->assertStatus(503);

    expect($response->json('checks.cache.status'))->toBe('fail')
        ->and($response->json('checks.database.status'))->toBe('pass');
});

it('serves /ready outside the web group, with no session cookie', function () {
    $route = Route::getRoutes()->getByName('helmet.ready');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('web');

    $this->get('/ready')->assertCookieMissing(config('session.cookie'));
});

it('keeps /up free of the readiness checks', function () {
    $default = config('database.default');

    config([
        'database.connections.ready_broken' => [
            'driver' => 'sqlite',
            'database' => storage_path('framework/testing/missing-'.Str::random(12).'.sqlite'),
            'prefix' => '',
        ],
        'database.default' => 'ready_broken',
    ]);

    $response = $this->get('/up');

    config(['database.default' => $default]);

    $response->assertStatus(200);
});
