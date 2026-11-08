<?php

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
|
| Every web route sits behind Laravel's PreventRequestForgery middleware,
| so a POST without a CSRF token must earn a 419 from it. Under Pest the
| app reports runningUnitTests(), which skips that check, so each case
| first switches the environment away from testing: a custom middleware
| stack that dropped the class would fail the first case.
*/

it('refuses a POST without a CSRF token with 419', function () {
    $this->app['env'] = 'local';

    $this->post(route('sesame.logout'))->assertStatus(419);
});

it('lets a POST with the session token through', function () {
    $this->app['env'] = 'local';

    $this->withSession(['_token' => 'csrf-test-token']);

    $response = $this->post(route('sesame.logout'), ['_token' => 'csrf-test-token']);

    expect($response->status())->not->toBe(419);
});

it('lets a same-origin POST through without a token', function () {
    $this->app['env'] = 'local';

    $response = $this->post(route('sesame.logout'), [], ['Sec-Fetch-Site' => 'same-origin']);

    expect($response->status())->not->toBe(419);
});
