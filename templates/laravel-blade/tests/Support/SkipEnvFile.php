<?php

namespace Tests\Support;

use App\Support\DummyAppKey;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/**
 * Tests read .env when present, otherwise .env.example, and never a missing
 * file (Pest reports phpdotenv's suppressed read of a missing .env as a
 * warning, PHPUnit does not). Tests get the dummy APP_KEY unless the
 * environment sets one, and production refuses that key (DummyAppKey).
 */
final class SkipEnvFile
{
    public function bootstrap(Application $app): void
    {
        if (! file_exists($app->basePath('.env')) && file_exists($app->basePath('.env.example'))) {
            $app->loadEnvironmentFrom('.env.example');
        }

        (new LoadEnvironmentVariables)->bootstrap($app);

        if ((string) env('APP_KEY') === '') {
            $key = DummyAppKey::VALUE;
            $_ENV['APP_KEY'] = $key;
            $_SERVER['APP_KEY'] = $key;
            putenv('APP_KEY='.$key);
        }
    }
}
