<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\SkipEnvFile;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the app with its real environment: tests read .env when present, otherwise .env.example, and never a
     * missing file (Pest reports phpdotenv's suppressed read of a missing .env as a warning, PHPUnit does not).
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->bind(LoadEnvironmentVariables::class, SkipEnvFile::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
