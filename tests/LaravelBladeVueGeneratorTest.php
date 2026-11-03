<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\PlaybookMode;

final class LaravelBladeVueGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testCopiesTheBladeOverlayThenTheIslandsOverlay(): void
    {
        $target = $this->generate();

        // The laravel-blade overlay still lands under the islands layer...
        foreach ([
            'app/Models/User.php',
            'bootstrap/app.php',
            'config/helmet.php',
            'config/sesame.php',
            'resources/views/home.blade.php',
            'routes/web.php',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // ...and the islands front end layers over it.
        foreach ([
            'package.json',
            'vite.config.js',
            'resources/js/app.js',
            'resources/js/islands/Counter.vue',
            'resources/views/dashboard.blade.php',
            'resources/views/layouts/app.blade.php',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // The blade overlay's routes survive: the welcome route is gone, the
        // dashboard route is there.
        $web = (string) file_get_contents($target . '/routes/web.php');
        self::assertStringNotContainsString("view('welcome')", $web);
        self::assertStringContainsString("->name('dashboard')", $web);
        self::assertStringContainsString('auth.sesame', $web);
    }

    public function testShipsTheIslandsFrontEndWithoutInertiaOrZiggy(): void
    {
        $target = $this->generate();
        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        // The pins match the Inertia template's front end...
        self::assertSame('^3.5.13', $package['dependencies']['vue']);
        self::assertSame('^5.2.1', $package['dependencies']['@vitejs/plugin-vue']);
        self::assertSame('^6.0.3', $package['dependencies']['vite']);
        self::assertSame('^1.0', $package['dependencies']['laravel-vite-plugin']);

        // ...but no Inertia and no Ziggy: Blade still renders the page.
        $raw = (string) file_get_contents($target . '/package.json');
        self::assertStringNotContainsString('inertia', $raw);
        self::assertStringNotContainsString('ziggy', $raw);
    }

    public function testTheLayoutAndDashboardMountTheIsland(): void
    {
        $target = $this->generate();

        $layout = (string) file_get_contents($target . '/resources/views/layouts/app.blade.php');
        self::assertStringContainsString("@vite('resources/js/app.js')", $layout);

        $dashboard = (string) file_get_contents($target . '/resources/views/dashboard.blade.php');
        self::assertStringContainsString('data-vue-island="counter"', $dashboard);

        $entry = (string) file_get_contents($target . '/resources/js/app.js');
        self::assertStringContainsString("[data-vue-island]", $entry);
        self::assertStringContainsString('counter: Counter', $entry);

        $viteConfig = (string) file_get_contents($target . '/vite.config.js');
        self::assertStringContainsString("'resources/js/app.js'", $viteConfig);
    }

    public function testMergesOnlyTheBladeComposerOverlay(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The blade overlay's stack packages land...
        self::assertSame('^0.2', $composer['require']['waynestate/sesame']);
        self::assertSame('^0.2', $composer['require']['waynestate/helmet']);
        // ...while the skeleton's own packages survive the merge.
        self::assertSame('^13.17', $composer['require']['laravel/framework']);
        self::assertSame('^8.3', $composer['require']['php']);
    }

    public function testWritesTheManifestWithTheIslandsTemplateName(): void
    {
        $target = $this->generate();

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame('laravel-blade-vue', $manifest['template']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertSame('managed', $manifest['files']['resources/js/app.js']['class']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
    }

    public function testReplacesEveryPlaceholder(): void
    {
        $target = $this->generate();

        foreach ($this->allFiles($target) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('{{app_name}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', $content, $file);
            self::assertStringNotContainsString('{{app_name}}', basename($file), $file);
            self::assertStringNotContainsString('{{app_slug}}', basename($file), $file);
        }

        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('my-cool-app', $package['name']);
    }

    public function testRefusesNonEmptyTarget(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        $this->generator()->generate($target, 'My Cool App');
    }

    public function testDoesNotCopyTheControlFiles(): void
    {
        $target = $this->generate();

        self::assertFileDoesNotExist($target . '/composer.overlay.json');
        self::assertFileDoesNotExist($target . '/env-additions.txt');
    }

    private function generator(): LaravelBladeVueGenerator
    {
        return new LaravelBladeVueGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
    }

    private function generate(bool $advisor = true): string
    {
        $target = $this->makeTempDir();
        $this->generator()->generate($target, 'My Cool App', [], PlaybookMode::Suggest, $advisor);

        return $target;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function allFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || '.sourdough' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->allFiles($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
