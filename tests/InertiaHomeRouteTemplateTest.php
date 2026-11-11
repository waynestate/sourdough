<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The signed-in landing page used to be hard-coded as /dashboard in the
 * inertia template's routes and pinned in its skeleton tests, so an app
 * that renamed the starter Dashboard broke both. The path and the route
 * name now live in config/home.php; these tests pin the template to the
 * config, so the loaf keeps its shape when the app moves the page.
 */
final class InertiaHomeRouteTemplateTest extends TestCase
{
    private const TEMPLATE = 'laravel-inertia';

    public function testTheHomeConfigExistsWithItsDefaults(): void
    {
        $path = dirname(__DIR__) . '/templates/' . self::TEMPLATE . '/config/home.php';
        $this->assertFileExists($path, 'the inertia template is missing config/home.php');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        $this->assertStringContainsString(
            "'route' => env('APP_HOME_ROUTE', 'dashboard')",
            $source,
            $path . ' does not default the home route name to dashboard',
        );
        $this->assertStringContainsString(
            "'path' => env('APP_HOME_PATH', '/dashboard')",
            $source,
            $path . ' does not default the home path to /dashboard',
        );
    }

    public function testTheRoutesReadTheHomeConfig(): void
    {
        $path = dirname(__DIR__) . '/templates/' . self::TEMPLATE . '/routes/web.php';
        $this->assertFileExists($path, 'the inertia template is missing routes/web.php');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        $this->assertStringContainsString(
            "config('home.path')",
            $source,
            $path . ' does not read the landing path from config/home.php',
        );
        $this->assertStringContainsString(
            "config('home.route')",
            $source,
            $path . ' does not read the landing route name from config/home.php',
        );
    }

    public function testNoBakedTestHardCodesTheDashboardPath(): void
    {
        $dir = dirname(__DIR__) . '/templates/' . self::TEMPLATE . '/tests';
        $this->assertDirectoryExists($dir, 'the inertia template is missing its tests/ dir');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertNotFalse($source, $file->getPathname() . ' could not be read');

            $this->assertStringNotContainsString(
                '/dashboard',
                $source,
                $file->getPathname() . ' hard-codes the landing path; read config/home.php instead',
            );
        }
    }

    public function testTheHomeRouteIsSharedWithInertia(): void
    {
        $path = dirname(__DIR__) . '/templates/' . self::TEMPLATE . '/app/Http/Middleware/HandleInertiaRequests.php';
        $this->assertFileExists($path, 'the inertia template is missing app/Http/Middleware/HandleInertiaRequests.php');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        $this->assertStringContainsString(
            "'homeRoute' => config('home.route')",
            $source,
            $path . ' does not share the home route name with Inertia',
        );
    }

    public function testTheGitignoreAdditionsCarryTheParityFolder(): void
    {
        $path = dirname(__DIR__) . '/templates/' . self::TEMPLATE . '/gitignore-additions.txt';
        $this->assertFileExists($path, 'the inertia template is missing gitignore-additions.txt');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, $path . ' could not be read');

        $lines = array_map('trim', preg_split('/\R/', $source) ?: []);
        $this->assertContains(
            '/.parity/',
            $lines,
            $path . ' does not ignore Parity\'s per-app folder',
        );
    }
}
