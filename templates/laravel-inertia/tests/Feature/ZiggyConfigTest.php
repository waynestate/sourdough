<?php

/*
|--------------------------------------------------------------------------
| Ziggy leaves Boost's routes out of the route list
|--------------------------------------------------------------------------
|
| Boost registers its routes (boost.*) only where the dev package is
| installed. config/ziggy.php excepts them so the committed ziggy.ts is
| the same on a laptop with Boost and in CI without it.
*/

it('keeps Boost routes out of the Ziggy route list', function () {
    expect(config('ziggy.except'))->toBeArray()
        ->and(config('ziggy.except'))->toContain('boost.*');
});
