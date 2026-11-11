<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The baked suite must pass on every laptop and in CI, so TestCase pins
 * the values it leans on right after the kernel boots: the developer's
 * .env may carry an APP_URL, trusted proxies or SESAME_DRIVER=cas, but
 * the verdict stays the same. A loaf does not rise differently because
 * the baker's kettle whistles.
 */
final class InertiaTestEnvIsolationTemplateTest extends TestCase
{
    public function testLaravelTestCasePinsTheConfigTheSkeletonTestsDependOn(): void
    {
        $path = dirname(__DIR__) . '/templates/laravel-inertia/tests/TestCase.php';
        $this->assertFileExists($path, 'templates/laravel-inertia/tests/TestCase.php is missing');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        // The pin lands after the kernel has booted, so it overrides what
        // the .env loaded during bootstrap instead of being stomped by it.
        $bootstrap = strpos($source, '$app->make(Kernel::class)->bootstrap();');
        $this->assertNotFalse($bootstrap, $path . ' does not bootstrap the kernel');

        foreach ([
            "'app.url' => 'http://localhost'",
            "'sesame.driver' => 'fake'",
            "'helmet.proxies' => null",
        ] as $pin) {
            $position = strpos($source, $pin, $bootstrap);
            $this->assertNotFalse(
                $position,
                $path . ' does not pin ' . $pin . ' after the bootstrap() call',
            );
        }
    }

    public function testLaravelTemplateCarriesTheEnvIsolationPestTest(): void
    {
        $path = dirname(__DIR__) . '/templates/laravel-inertia/tests/Feature/EnvIsolationTest.php';
        $this->assertFileExists($path, 'templates/laravel-inertia/tests/Feature/EnvIsolationTest.php is missing');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        $this->assertStringContainsString("config('app.url')", $source);
        $this->assertStringContainsString("config('sesame.driver')", $source);
    }
}
