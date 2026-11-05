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
     * Boot the app without reading a .env file: tests take their environment from phpunit.xml. Pest reports
     * phpdotenv's suppressed read of a missing .env as a warning (PHPUnit does not), so this skips it. The framework's
     * cached-config and cached-routes branches are left out because the suite does not use them.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->bind(LoadEnvironmentVariables::class, SkipEnvFile::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // composer ci runs in a bare container without Node, so Vite assets are
        // never built. Write a minimal manifest so view-rendering tests can boot.
        $manifest = public_path('build/manifest.json');
        if (! is_dir(dirname($manifest))) {
            mkdir(dirname($manifest), 0755, true);
        }
        if (! file_exists($manifest)) {
            file_put_contents($manifest, json_encode([
                'resources/js/app.ts' => [
                    'file' => 'assets/app.js',
                    'src' => 'resources/js/app.ts',
                    'isDynamicEntry' => true,
                ],
            ]));
        }
    }
}
