<?php

/*
|--------------------------------------------------------------------------
| Environment isolation
|--------------------------------------------------------------------------
|
| The skeleton suite must pass on every laptop and in CI, so TestCase pins
| the values it leans on right after the kernel boots. These tests keep
| that pin honest: a developer's .env may say APP_URL or
| SESAME_DRIVER=cas, but the suite still sees localhost and the fake
| driver.
*/

it('pins app.url to localhost', function () {
    expect(config('app.url'))->toBe('http://localhost');
});

it('pins the SESAME driver to fake', function () {
    expect(config('sesame.driver'))->toBe('fake');
});
