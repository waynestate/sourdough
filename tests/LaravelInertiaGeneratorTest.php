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
            'app/Health/SchedulerHeartbeat.php',
            'app/Http/Middleware/HandleInertiaRequests.php',
            'app/Models/User.php',
            'app/Sesame/UserResolver.php',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            'config/helmet.php',
            'config/sesame.php',
            'config/worker.php',
            'database/migrations/2026_10_28_000001_add_username_to_users_table.php',
            'package.json',
            'phpstan.neon',
            'pint.json',
            'resources/js/app.ts',
            'resources/js/pages/Dashboard.vue',
            'resources/js/pages/Home.vue',
            'resources/js/ziggy.js',
            'resources/views/app.blade.php',
            'routes/console.php',
            'routes/web.php',
            'tests/Feature/QueueWorkerScheduleTest.php',
            'tests/Feature/SchedulerHeartbeatTest.php',
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

    public function testFloorsViteAtTheCveFixLine(): void
    {
        $target = $this->generate();
        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        // The floor is the CVE-2025-62522 fix line (BEST_PRACTICES 4.12).
        self::assertSame('^6.4.1', $package['dependencies']['vite']);
    }

    public function testIgnoresLocalEnvFiles(): void
    {
        $target = $this->generate();

        // Vite keeps local secrets in .env.*.local files, so the overlay's
        // gitignore additions keep *.local out of git.
        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertContains('*.local', array_map('trim', explode("\n", $gitignore)));
    }

    public function testMergesTheComposerOverlay(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The Inertia packages land in require alongside the stack's...
        self::assertSame('^3.0', $composer['require']['inertiajs/inertia-laravel']);
        self::assertSame('^2.4', $composer['require']['tightenco/ziggy']);
        self::assertSame('^0.6', $composer['require']['waynestate/sesame']);
        self::assertSame('^1.0', $composer['require']['waynestate/helmet']);
        // ...the dev tools in require-dev...
        self::assertSame('^3.13', $composer['require-dev']['larastan/larastan']);
        self::assertSame('^0.1', $composer['require-dev']['waynestate/advisor']);
        // ...and Pest is the test runner for new tests...
        self::assertSame('^4.0', $composer['require-dev']['pestphp/pest']);
        self::assertSame('^4.0', $composer['require-dev']['pestphp/pest-plugin-laravel']);
        // ...and the scripts join the skeleton's own.
        self::assertIsArray($composer['scripts']['ci']);
        self::assertContains('pest', $composer['scripts']['ci']);
        self::assertNotContains('phpunit', $composer['scripts']['ci']);
        self::assertSame('pest', $composer['scripts']['test']);
        self::assertContains('phpstan analyse --memory-limit=1G', $composer['scripts']['ci']);
        self::assertSame(
            'proctor check',
            $composer['scripts']['proctor'],
        );
        self::assertSame('php artisan ziggy:generate --types', $composer['scripts']['ziggy']);
        self::assertArrayHasKey('setup', $composer['scripts']);

        // The skeleton's own packages survive the merge.
        self::assertSame('^13.17', $composer['require']['laravel/framework']);
        self::assertSame('^8.3', $composer['require']['php']);
    }

    public function testShipsPestForNewTests(): void
    {
        $target = $this->generate();

        // Feature tests get the app and a fresh database through Pest...
        self::assertFileExists($target . '/tests/Pest.php');
        self::assertStringContainsString(
            "pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');",
            (string) file_get_contents($target . '/tests/Pest.php'),
        );

        // ...and the TestCase boots the app without reading a .env file:
        // tests take their environment from phpunit.xml, and Pest reports
        // phpdotenv's suppressed read of a missing .env as a warning.
        self::assertFileExists($target . '/tests/Support/SkipEnvFile.php');
        $testCase = (string) file_get_contents($target . '/tests/TestCase.php');
        self::assertStringContainsString('SkipEnvFile', $testCase);
        self::assertStringContainsString('createApplication', $testCase);
        // The Vite manifest stub for the bare CI container stays.
        self::assertStringContainsString('build/manifest.json', $testCase);

        // The skeleton's PHPUnit feature example is replaced by a Pest one.
        self::assertFileExists($target . '/tests/Feature/ExampleTest.php');
        self::assertStringContainsString(
            "it('returns a successful response'",
            (string) file_get_contents($target . '/tests/Feature/ExampleTest.php'),
        );
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
            ['$schema', 'name', 'type', 'description', 'keywords', 'license', 'require', 'require-dev', 'autoload', 'autoload-dev', 'scripts', 'scripts-descriptions', 'extra', 'config', 'minimum-stability', 'prefer-stable', 'suggest'],
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

    public function testGeneratedCiHasTheZiggyJob(): void
    {
        $target = $this->generate();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        // The job regenerates the committed route list and fails on drift.
        self::assertStringContainsString('ziggy:', $ci);
        self::assertStringContainsString('git diff --exit-code resources/js/ziggy.*', $ci);
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

    public function testDropsSkeletonLeftovers(): void
    {
        // The fixture skeleton is trimmed; copy it and give it the three
        // leftovers a fresh Laravel skeleton carries (welcome.blade.php is
        // already in the fixture).
        $skeleton = $this->makeTempDir();
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        file_put_contents($skeleton . '/vite.config.js', "export default {};\n");
        mkdir($skeleton . '/resources/js', 0777, true);
        file_put_contents($skeleton . '/resources/js/app.js', "// the skeleton's stub entry point\n");

        $target = $this->makeTempDir();
        (new LaravelInertiaGenerator(new FixtureSkeletonSource($skeleton)))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        // None of the three leftovers survives...
        self::assertFileDoesNotExist($target . '/vite.config.js');
        self::assertFileDoesNotExist($target . '/resources/js/app.js');
        self::assertFileDoesNotExist($target . '/resources/views/welcome.blade.php');
        // ...and the overlay's TypeScript front end stands.
        self::assertFileExists($target . '/vite.config.ts');
        self::assertFileExists($target . '/resources/js/app.ts');

        // The manifest was written after the drop, so every path it lists
        // exists on disk.
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($manifest['files']) as $path) {
            self::assertFileExists($target . '/' . $path, (string) $path);
        }
    }

    public function testOracleCheckFindsTheKitAtTheRootOrInDeploy(): void
    {
        $target = $this->generate();
        $command = (string) file_get_contents($target . '/app/Console/Commands/OracleCheck.php');

        // It names both homes, the app root first, and keeps the message
        // for when neither is there.
        self::assertStringContainsString("base_path('oracle-check.php')", $command);
        self::assertStringContainsString("base_path('deploy/oracle-check.php')", $command);
        self::assertLessThan(
            strpos($command, "base_path('deploy/oracle-check.php')"),
            strpos($command, "base_path('oracle-check.php')"),
        );
        self::assertStringContainsString(
            'The Oracle check file is not here yet. Run "vendor/bin/squire update" to get it.',
            $command,
        );
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
            '.squire/portable.Dockerfile',
            '.squire/portable.Dockerfile.dockerignore',
            '.squire/portable/ports.conf',
            '.squire/portable/squire-entrypoint.sh',
            '.squire/portable/vhost.conf',
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
        self::assertFileDoesNotExist($target . '/gitignore-additions.txt');
    }

    public function testDefaultGenerationWiresAdvisor(): void
    {
        $target = $this->generate();

        // The skeleton's .gitignore does not ship .mcp.json. Boost's block adds
        // the anchored /.mcp.json, which already covers ADVISOR's client config,
        // so the ADVISOR wiring adds no second line.
        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertContains('/.mcp.json', array_map('trim', explode("\n", $gitignore)));
        self::assertSame(1, substr_count($gitignore, '.mcp.json'));

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

        // ADVISOR's own .mcp.json line is gone; Boost's anchored one stays,
        // because Boost writes a .mcp.json of its own.
        $lines = array_map('trim', explode("\n", (string) file_get_contents($target . '/.gitignore')));
        self::assertNotContains('.mcp.json', $lines);
        self::assertContains('/.mcp.json', $lines);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('ADVISOR', $readme);
    }

    private function generator(): LaravelInertiaGenerator
    {
        return new LaravelInertiaGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
    }

    /**
     * The template's example tests are Pest files, and PHPUnit refuses to
     * load them ("Please run ./vendor/bin/pest instead"), so the php-test
     * matrix must run Pest. It runs the PHPUnit class tests too.
     */
    public function testGeneratedPhpTestMatrixRunsPest(): void
    {
        $target = $this->generate();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        self::assertStringContainsString('test_command: vendor/bin/pest', $ci);
        self::assertStringNotContainsString('vendor/bin/phpunit', $ci);
    }

    /**
     * Every app refuses to boot production on the tests' dummy APP_KEY.
     */
    public function testShipsTheDummyAppKeyGuard(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/app/Support/DummyAppKey.php');
        self::assertStringContainsString(
            'DummyAppKey::refuseIn(',
            (string) file_get_contents($target . '/app/Providers/AppServiceProvider.php'),
        );
    }

    /**
     * Every app refuses to boot production with APP_DEBUG on: the debug
     * page shows stack traces and environment values to anyone.
     */
    public function testShipsTheProductionDebugGuard(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/app/Support/ProductionDebug.php');
        self::assertFileExists($target . '/tests/Feature/ProductionDebugTest.php');
        self::assertStringContainsString(
            'ProductionDebug::refuseIn(',
            (string) file_get_contents($target . '/app/Providers/AppServiceProvider.php'),
        );
    }

    /**
     * WayneUI is the only component source (WELD's BEST_PRACTICES W2): the
     * Inertia shape draws with WayneUI, pinned exactly, and carries none of
     * the Tailwind kit it started from.
     */
    public function testDrawsWithWayneUiNotTailwind(): void
    {
        $target = $this->generate();
        $package = json_decode((string) file_get_contents($target . '/package.json'), true);
        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        self::assertSame('2.0.8', $deps['@waynestate/wayne-ui-css'] ?? null);
        self::assertSame('2.0.8', $deps['@waynestate/wayne-ui-vue'] ?? null);
        foreach (['tailwindcss', 'tailwind-merge', 'clsx', 'lucide-vue-next', 'prettier-plugin-tailwindcss', 'autoprefixer'] as $gone) {
            self::assertArrayNotHasKey($gone, $deps, $gone);
        }

        self::assertFileDoesNotExist($target . '/tailwind.config.js');
        self::assertFileDoesNotExist($target . '/resources/js/lib/utils.ts');
        self::assertStringContainsString(
            "@import '@waynestate/wayne-ui-css/dist/wayne-ui.css';",
            (string) file_get_contents($target . '/resources/css/app.css'),
        );
        self::assertStringNotContainsString('tailwind', (string) file_get_contents($target . '/vite.config.ts'));
        self::assertStringContainsString("'@/components/ui'", (string) file_get_contents($target . '/eslint.config.js'));
        self::assertStringContainsString('frontend:', (string) file_get_contents($target . '/.gitlab-ci.yml'));

        // No page or component reaches for a removed kit file.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target . '/resources/js', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $source = (string) file_get_contents((string) $file);
            self::assertStringNotContainsString('lucide-vue-next', $source, (string) $file);
            self::assertStringNotContainsString('@/lib/utils', $source, (string) $file);
            self::assertStringNotContainsString('PlaceholderPattern', $source, (string) $file);
        }
    }

    public function testShipsTheNpmCooldownAndTheNodeFloor(): void
    {
        $target = $this->generate();

        // The one-day npm release cooldown comes from Squire's vue layer...
        self::assertFileExists($target . '/.npmrc');
        self::assertStringContainsString('min-release-age=1', (string) file_get_contents($target . '/.npmrc'));

        // ...and the Node floor is pinned in both places, at the LTS the CI
        // images already run.
        self::assertFileExists($target . '/.nvmrc');
        self::assertSame('22', trim((string) file_get_contents($target . '/.nvmrc')));

        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('>=22', $package['engines']['node']);
    }

    /**
     * The baked pipeline carries the three CI gates: a bill of materials,
     * an accessibility gate over this shape's own rendered pages, and the
     * guards around deploy credentials (BEST_PRACTICES 4.15, 6.4 and 6.5).
     */
    public function testBakedPipelineCarriesTheCiGates(): void
    {
        $target = $this->generate();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        TemplateCiGatesTest::assertBakedPipelineGates($ci, 'laravel-inertia');
        foreach (['preflight/sbom@', 'preflight/secret-detection@', 'preflight/a11y@', "\na11y-pages:\n", "\n.deploy:\n"] as $gate) {
            self::assertStringContainsString($gate, $ci);
        }
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
