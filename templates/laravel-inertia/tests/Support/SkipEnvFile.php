<?php

namespace Tests\Support;

/**
 * Tests take their environment from phpunit.xml, never from a .env file.
 */
final class SkipEnvFile
{
    public function bootstrap(\Illuminate\Contracts\Foundation\Application $app): void
    {
    }
}
