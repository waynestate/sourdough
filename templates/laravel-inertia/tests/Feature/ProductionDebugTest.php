<?php

use App\Support\ProductionDebug;

/*
|--------------------------------------------------------------------------
| Production debug guard
|--------------------------------------------------------------------------
|
| Production never boots with the debug page on: it shows stack traces
| and environment values to anyone. The guard throws at boot, so these
| tests call it directly instead of re-booting the app per case.
*/

it('refuses to boot production with the debug page on', function () {
    expect(fn () => ProductionDebug::refuseIn('production', true))
        ->toThrow(\RuntimeException::class);
});

it('boots production with the debug page off', function () {
    ProductionDebug::refuseIn('production', false);
});

it('boots local with the debug page on', function () {
    ProductionDebug::refuseIn('local', true);
});
