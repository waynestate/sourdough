<?php

namespace Tests\Support;

/**
 * Tests take their environment from phpunit.xml, never from a .env file.
 * Tests get a fixed throwaway APP_KEY unless the environment sets one.
 */
final class SkipEnvFile
{
    public function bootstrap(\Illuminate\Contracts\Foundation\Application $app): void
    {
        if ('' === (string) env('APP_KEY')) {
            $key = 'base64:' . base64_encode(str_repeat('a', 32));
            $_ENV['APP_KEY'] = $key;
            $_SERVER['APP_KEY'] = $key;
            putenv('APP_KEY=' . $key);
        }
    }
}
