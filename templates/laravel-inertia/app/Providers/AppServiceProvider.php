<?php

namespace App\Providers;

use App\Support\DummyAppKey;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production never boots on the tests' throwaway key.
        DummyAppKey::refuseIn($this->app->environment(), config('app.key'));

        // Citadel's shared MySQL 5.6 caps index keys at 767 bytes, so string columns stay at 191 characters.
        Schema::defaultStringLength(191);
    }
}
