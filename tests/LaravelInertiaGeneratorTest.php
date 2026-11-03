<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\PlaybookMode;

final class LaravelInertiaGeneratorTest extends TestCase
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

    public function testCopiesTheOverlayOverTheSkeleton(): void
    {
        $target = $this->generate();

        foreach ([
            '.gitlab-ci.yml',
            'app/Http/Middleware/HandleInertiaRequests.php',
            'app/Models/User.php',
            'app/Sesame/UserResolver.php',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            'config/helmet.php',
            'config/sesame.php',
            'database/migrations/2026_10_28_000001_add_username_to_users_table.php',
            'package.json',
            'phpstan.neon',
            'pint.json',
            'resources/js/app.ts',
            'resources/js/pages/Dashboard.vue',
            'resources/js/pages/Home.vue',
            'resources/js/ziggy.js',
            'resources/views/app.blade.php',
            'routes/web.php',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // The skeleton's routes/web.php is replaced by the overlay's: the
        // welcome route is gone, the Inertia pages are there.
        $web = (string) file_get_contents($target . '/routes/web.php');
        self::assertStringNotContainsString("view('welcome')", $web);
        self::assertStringContainsString("Inertia::render('Home')", $web);
        self::assertStringContainsString("Inertia::render('Dashboard')", $web);
        self::assertStringContainsString('auth.sesame', $web);

        // The Blade views of the other shape do not leak in.
        self::assertFileDoesNotExist($target . '/resources/views/home.blade.php');
        self::assertFileDoesNotExist($target . '/resources/views/dashboard.blade.php');
        self::assertFileDoesNotExist($target . '/resources/views/layouts/app.blade.php');
    }

    public function testMergesTheComposerOverlay(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The Inertia packages land in require alongside the stack's...
        self::assertSame('^2.0', $composer['require']['inertiajs/inertia-laravel']);
        self::assertSame('^2.4', $composer['require']['tightenco/ziggy']);
        self::assertSame('^0.2', $composer['require']['waynestate/sesame']);
        self::assertSame('^0.2', $composer['require']['waynestate/helmet']);
        // ...the dev tools in require-dev...
        self::assertSame('^3.13', $composer['require-dev']['larastan/larastan']);
        self::assertSame('^0.1', $composer['require-dev']['waynestate/advisor']);
        // ...and the scripts join the skeleton's own.
        self::assertIsArray($composer['scripts']['ci']);
        self::assertContains('phpstan analyse --memory-limit=1G', $composer['scripts']['ci']);
        self::assertSame(
            'proctor check --policy=vendor/waynestate/creed/policy/policy.json',
            $composer['scripts']['proctor'],
        );
        self::assertSame('php artisan ziggy:generate --types', $composer['scripts']['ziggy']);
        self::assertArrayHasKey('setup', $composer['scripts']);

        // The skeleton's own packages survive the merge.
        self::assertSame('^13.17', $composer['require']['laravel/framework']);
        self::assertSame('^8.3', $composer['require']['php']);
    }

    public function testAppendsTheEnvAdditions(): void
    {
        $target = $this->generate();
        $env = (string) file_get_contents($target . '/.env.example');

        // The skeleton's own lines survive (APP_NAME aside: it now carries
        // the app name)...
        self::assertStringContainsString('APP_ENV=local', $env);
        // ...and the stack's keys are appended, with the fake driver for
        // local work...
        self::assertStringContainsString('SESAME_DRIVER=fake', $env);
        self::assertStringContainsString('HELMET_PRESET=', $env);
        // ...and Vite gets the app name from the same variable.
        self::assertStringContainsString('VITE_APP_NAME="${APP_NAME}"', $env);
    }

    public function testNameReachesComposerJson(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // An application is not a published package: the skeleton's
        // laravel/laravel name becomes app/<slug>.
        self::assertSame('app/my-cool-app', $composer['name']);
        self::assertSame('My Cool App, a Wayne State app baked from Sourdough.', $composer['description']);

        // The merge's formatting rules hold: pretty printed, unescaped
        // slashes, trailing newline, key order kept.
        $raw = (string) file_get_contents($target . '/composer.json');
        self::assertStringEndsWith("\n", $raw);
        self::assertStringNotContainsString('\/', $raw);
        self::assertSame(
            array_keys(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)),
            ['$schema', 'name', 'type', 'description', 'keywords', 'license', 'require', 'require-dev', 'autoload', 'autoload-dev', 'scripts', 'extra', 'config', 'minimum-stability', 'prefer-stable', 'suggest'],
        );
    }

    public function testNameReachesEnvExample(): void
    {
        $target = $this->generate();
        $env = (string) file_get_contents($target . '/.env.example');

        // The name carries a space, so the value is quoted.
        self::assertStringContainsString("APP_NAME=\"My Cool App\"", $env);
        self::assertStringNotContainsString('APP_NAME=Laravel', $env);
    }

    public function testNameReachesAppConfig(): void
    {
        $target = $this->generate();
        $config = (string) file_get_contents($target . '/config/app.php');

        self::assertStringContainsString("'name' => env('APP_NAME', 'My Cool App')", $config);
        self::assertStringNotContainsString("'name' => env('APP_NAME', 'Laravel')", $config);
    }

    /**
     * Citadel's shared MySQL 5.6 caps index keys at 767 bytes, so the
     * template pins Laravel's default string column length at 191; a fresh
     * app must ship that provider or its migrations break on Citadel.
     */
    public function testShipsTheCitadelStringLength(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/app/Providers/AppServiceProvider.php');
        self::assertStringContainsString(
            'Schema::defaultStringLength(191)',
            (string) file_get_contents($target . '/app/Providers/AppServiceProvider.php'),
        );
    }

    /**
     * composer ci runs in a bare container without Node, so the template's
     * TestCase writes a stub Vite manifest before each test; view-rendering
     * tests cannot boot without it.
     */
    public function testShipsATestCaseThatWritesAViteManifest(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/tests/TestCase.php');
        self::assertStringContainsString(
            'build/manifest.json',
            (string) file_get_contents($target . '/tests/TestCase.php'),
        );
    }

    /**
     * SESAME requires a non-empty fake.username when the driver is fake, so
     * the config carries a default that matches the hardcoded display name;
     * otherwise a fresh app with nothing in its environment cannot boot.
     */
    public function testFakeDriverBootsWithNoEnvironment(): void
    {
        $target = $this->generate();
        $config = (string) file_get_contents($target . '/config/sesame.php');

        self::assertStringContainsString("env('SESAME_FAKE_USERNAME', 'jane.doe')", $config);
    }

    /**
     * Squire's health.php and oracle-check.php ship verbatim from the SQUIRE
     * package (squire-copy:check keeps them byte-identical), so the generated
     * app's Pint config must skip them; otherwise composer ci fails on their
     * foreign style, as the E2E smoke found for laravel-blade.
     */
    public function testPintSkipsTheVerbatimSquireFiles(): void
    {
        $target = $this->generate();
        $pint = json_decode((string) file_get_contents($target . '/pint.json'), true);

        self::assertContains('health.php', $pint['notPath']);
        self::assertContains('oracle-check.php', $pint['notPath']);
    }

    public function testRemovesTheSkeletonViteConfigJs(): void
    {
        // The fixture skeleton is trimmed; copy it and give it the
        // vite.config.js a fresh Laravel skeleton would carry.
        $skeleton = $this->makeTempDir();
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        file_put_contents($skeleton . '/vite.config.js', "export default {};\n");

        $target = $this->makeTempDir();
        (new LaravelInertiaGenerator(new FixtureSkeletonSource($skeleton)))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        // The skeleton's JS config is gone; the overlay's TS config stands.
        self::assertFileDoesNotExist($target . '/vite.config.js');
        self::assertFileExists($target . '/vite.config.ts');
    }

    public function testInstallsTheSquireLaravelFiles(): void
    {
        $target = $this->generate();

        foreach ([
            'build.sh',
            'deployments/001-first-release.sh',
            'scripts/release.sh',
            'scripts/parity.sh',
            'scripts/parity.ps1',
            'health.php',
            'docker-compose.squire.yml',
            '.env.squire.example',
            '.squire/Dockerfile',
            '.squire/php.ini',
            '.squire/vhost.conf',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }
    }

    public function testWritesTheManifest(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame('laravel-inertia', $manifest['template']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertSame('seeded', $manifest['files']['.env.example']['class']);
        self::assertSame('managed', $manifest['files']['routes/web.php']['class']);
        self::assertSame('managed', $manifest['files']['scripts/release.sh']['class']);
    }

    public function testTempEdgeCases(): void
    {
        // A name without a space stays unquoted in .env.example.
        $target = $this->makeTempDir();
        $this->generator()->generate($target, 'PlainApp', [], PlaybookMode::Suggest, true);
        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('APP_NAME=PlainApp', $env);
        self::assertStringNotContainsString('APP_NAME="PlainApp"', $env);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('app/plainapp', $composer['name']);
        self::assertSame('PlainApp, a Wayne State app baked from Sourdough.', $composer['description']);
        self::assertStringContainsString("'name' => env('APP_NAME', 'PlainApp')", (string) file_get_contents($target . '/config/app.php'));

        // A skeleton without config/app.php still bakes: the step skips it.
        $skeleton = $this->makeTempDir();
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        unlink($skeleton . '/config/app.php');
        $target = $this->makeTempDir();
        (new LaravelInertiaGenerator(new FixtureSkeletonSource($skeleton)))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);
        self::assertFileExists($target . '/composer.json');

        // A pattern that does not match leaves the file alone and does not fail.
        $skeleton = $this->makeTempDir();
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        file_put_contents($skeleton . '/.env.example', str_replace('APP_NAME=Laravel', 'APP_NAME=SomethingElse', (string) file_get_contents($skeleton . '/.env.example')));
        file_put_contents($skeleton . '/config/app.php', str_replace("'name' => env('APP_NAME', 'Laravel')", "'name' => env('APP_NAME', 'Old')", (string) file_get_contents($skeleton . '/config/app.php')));
        $target = $this->makeTempDir();
        (new LaravelInertiaGenerator(new FixtureSkeletonSource($skeleton)))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);
        self::assertStringContainsString('APP_NAME=SomethingElse', (string) file_get_contents($target . '/.env.example'));
        self::assertStringContainsString("'name' => env('APP_NAME', 'Old')", (string) file_get_contents($target . '/config/app.php'));
    }

    public function testReplacesEveryPlaceholder(): void
    {
        $target = $this->generate();

        // No placeholder survives anywhere in the generated tree.
        foreach ($this->allFiles($target) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('{{app_name}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', $content, $file);
            self::assertStringNotContainsString('{{app_name}}', basename($file), $file);
            self::assertStringNotContainsString('{{app_slug}}', basename($file), $file);
        }

        self::assertStringStartsWith('# My Cool App', (string) file_get_contents($target . '/README.md'));

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

    public function testDefaultGenerationWiresAdvisor(): void
    {
        $target = $this->generate();

        // The skeleton's .gitignore does not ship .mcp.json; the wiring adds it.
        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertStringContainsString('.mcp.json', $gitignore);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringContainsString('## ADVISOR', $readme);
        self::assertStringContainsString('php vendor/bin/advisor install --client=claude --write', $readme);

        // The section sits before the Handbook section, which stays last.
        $sectionPos = strpos($readme, '## ADVISOR');
        $handbookPos = strpos($readme, '## Handbook');
        self::assertIsInt($sectionPos);
        self::assertIsInt($handbookPos);
        self::assertLessThan($handbookPos, $sectionPos);
    }

    public function testNoAdvisorRemovesTheWiring(): void
    {
        $target = $this->generate(false);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The overlay merge adds the package; the opt-out removes it again.
        self::assertArrayNotHasKey('waynestate/advisor', $composer['require-dev']);

        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertStringNotContainsString('.mcp.json', $gitignore);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('ADVISOR', $readme);
    }

    private function generator(): LaravelInertiaGenerator
    {
        return new LaravelInertiaGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
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

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $from . '/' . $entry;
            if (is_dir($path)) {
                $this->copyDir($path, $to . '/' . $entry);
            } else {
                copy($path, $to . '/' . $entry);
            }
        }
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
