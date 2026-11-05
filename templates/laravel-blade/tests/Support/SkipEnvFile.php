<?php

namespace Tests\Support;

/**
 * Tests read .env when present, otherwise .env.example, and never a missing
 * file (Pest reports phpdotenv's suppressed read of a missing .env as a
 * warning, PHPUnit does not). Tests get a fixed throwaway APP_KEY unless the
 * environment sets one.
 */
final class SkipEnvFile
{
    public function bootstrap(\Illuminate\Contracts\Foundation\Application $app): void
    {
        if (! file_exists($app->basePath('.env')) && file_exists($app->basePath('.env.example'))) {
            $app->loadEnvironmentFrom('.env.example');
        }

        (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);

        if ('' === (string) env('APP_KEY')) {
            $key = 'base64:' . base64_encode(str_repeat('a', 32));
            $_ENV['APP_KEY'] = $key;
            $_SERVER['APP_KEY'] = $key;
            putenv('APP_KEY=' . $key);
        }
    }
}
