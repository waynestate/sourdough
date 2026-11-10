<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\ComposerSkeletonSource;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

final class LaravelBladeGeneratorTest extends TestCase
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
            'app/Models/User.php',
            'app/Sesame/UserResolver.php',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            'config/helmet.php',
            'config/sesame.php',
            'config/worker.php',
            'database/migrations/2026_10_28_000001_add_username_to_users_table.php',
            'phpstan.neon',
            'pint.json',
            'resources/views/dashboard.blade.php',
            'resources/views/home.blade.php',
            'resources/views/layouts/app.blade.php',
            'routes/console.php',
            'routes/web.php',
            'tests/Feature/QueueWorkerScheduleTest.php',
            'tests/Feature/SchedulerHeartbeatTest.php',
            'tests/Unit/PinstripeWiringTest.php',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // The skeleton's routes/web.php is replaced by the overlay's: the
        // welcome route is gone, the dashboard route is there.
        $web = (string) file_get_contents($target . '/routes/web.php');
        self::assertStringNotContainsString("view('welcome')", $web);
        self::assertStringContainsString("->name('dashboard')", $web);
        self::assertStringContainsString('auth.sesame', $web);
    }

    public function testMergesTheComposerOverlay(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The stack's runtime packages land in require...
        self::assertSame('^0.7', $composer['require']['waynestate/sesame']);
        self::assertSame('^1.3', $composer['require']['waynestate/helmet']);
        // ...the dev tools in require-dev...
        self::assertSame('^3.13', $composer['require-dev']['larastan/larastan']);
        self::assertSame('^0.2', $composer['require-dev']['waynestate/advisor']);
        self::assertSame('^0.1.8', $composer['require-dev']['waynestate/creed']);
        self::assertSame('^0.3', $composer['require-dev']['waynestate/pinstripe']);
        self::assertSame('^0.11', $composer['require-dev']['waynestate/proctor']);
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
        self::assertArrayHasKey('setup', $composer['scripts']);

        // The Oracle driver lands in require with the platform pin that lets
        // composer install run on a PHP without oci8...
        self::assertSame('^13.14', $composer['require']['yajra/laravel-oci8']);
        self::assertSame('3.4.1', $composer['config']['platform']['ext-oci8']);
        // ...while the skeleton's own config survives the deep merge.
        self::assertTrue($composer['config']['optimize-autoloader']);
        self::assertSame('dist', $composer['config']['preferred-install']);

        // larastan pulls in phpstan/extension-installer; the overlay allows
        // that plugin so a fresh composer install does not stop on a blocked
        // plugin...
        self::assertTrue($composer['config']['allow-plugins']['phpstan/extension-installer']);
        // ...while the skeleton's own plugin allowances survive the deep merge.
        self::assertTrue($composer['config']['allow-plugins']['pestphp/pest-plugin']);
        self::assertTrue($composer['config']['allow-plugins']['php-http/discovery']);

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

        // The skeleton's PHPUnit feature example is replaced by a Pest one.
        self::assertFileExists($target . '/tests/Feature/ExampleTest.php');
        self::assertStringContainsString(
            "it('returns a successful response'",
            (string) file_get_contents($target . '/tests/Feature/ExampleTest.php'),
        );
    }

    public function testWiresTheOracleConnectionDormant(): void
    {
        $target = $this->generate();

        // The connection ships as its own config file; yajra laravel-oci8
        // merges it into the database connections on boot.
        self::assertFileExists($target . '/config/oracle.php');
        $oracle = (string) file_get_contents($target . '/config/oracle.php');
        self::assertStringContainsString("'driver' => 'oracle'", $oracle);
        foreach ([
            'ORACLE_TNS',
            'ORACLE_HOST',
            'ORACLE_PORT',
            'ORACLE_SERVICE_NAME',
            'ORACLE_USERNAME',
            'ORACLE_PASSWORD',
        ] as $setting) {
            self::assertStringContainsString("env('$setting', '')", $oracle, $setting);
        }

        // Dormant: the file only defines the connection, it never sets the
        // default, and nothing in the env additions points at it.
        self::assertStringNotContainsString("'default'", $oracle);
        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringNotContainsString('DB_CONNECTION=oracle', $env);

        // Every setting is present in .env.example, commented out.
        foreach ([
            '#ORACLE_USERNAME=',
            '#ORACLE_PASSWORD=',
            '#ORACLE_TNS=',
            '#ORACLE_HOST=',
            '#ORACLE_PORT=',
            '#ORACLE_SERVICE_NAME=',
        ] as $line) {
            self::assertStringContainsString($line, $env);
        }
    }

    public function testShipsTheOracleCheckCommandAndSentences(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/app/Console/Commands/OracleCheck.php');
        self::assertFileExists($target . '/app/Support/OracleErrors.php');

        // The command runs Squire's check file and knows its absence.
        $command = (string) file_get_contents($target . '/app/Console/Commands/OracleCheck.php');
        self::assertStringContainsString("'oracle:check'", $command);
        self::assertStringContainsString('Check the Oracle settings and connection, in plain language', $command);
        self::assertStringContainsString('deploy/oracle-check.php', $command);
        self::assertStringContainsString(
            'The Oracle check file is not here yet. Run "vendor/bin/squire update" to get it.',
            $command,
        );

        // Every plain sentence ships, word for word.
        $errors = (string) file_get_contents($target . '/app/Support/OracleErrors.php');
        foreach ([
            'Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.',
            'Oracle could not find the connect name in ORACLE_TNS. Use host:port/service or a full descriptor.',
            'The Oracle listener answered but that service is not available. Check ORACLE_SERVICE_NAME; the database may be in maintenance.',
            'There is no Oracle listener at that address. Check the host and port; the database may be down.',
            'Oracle refused the username or password. Check ORACLE_USERNAME and ORACLE_PASSWORD.',
            'The login worked, but this account lacks permission on that table or view (ORA-%d). Ask the data owner for the grant.',
        ] as $sentence) {
            self::assertStringContainsString($sentence, $errors);
        }
        foreach ([12154, 12514, 12541, 1017, 1031, 942] as $code) {
            self::assertStringContainsString((string) $code, $errors);
        }

        // The reportable callback wires the sentences into logging.
        $bootstrap = (string) file_get_contents($target . '/bootstrap/app.php');
        self::assertStringContainsString('->reportable(', $bootstrap);
        self::assertStringContainsString('OracleErrors::describe', $bootstrap);
        self::assertStringContainsString("'[oracle] '", $bootstrap);
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

    public function testAppendsTheEnvAdditions(): void
    {
        $target = $this->generate();
        $env = (string) file_get_contents($target . '/.env.example');

        // The skeleton's own lines survive (APP_NAME aside: it now carries
        // the app name)...
        self::assertStringContainsString('APP_ENV=local', $env);
        // ...and the stack's keys are appended, with the fake driver for
        // local work.
        self::assertStringContainsString('SESAME_DRIVER=fake', $env);
        self::assertStringContainsString('# SESAME_CAS_BASE_URL=', $env);
        self::assertStringContainsString('# HELMET_PRESET=', $env);
        self::assertStringContainsString('# HELMET_PROXIES=', $env);
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
     * An empty KEY= line overrides the package default with an empty string
     * (env() returns "", not null), which is how the smoke run lost its fake
     * user, session limits and login routes. So every SESAME_ and HELMET_
     * key ships commented out; only the fake driver is set.
     */
    public function testShipsNoUncommentedEmptySesameOrHelmetKeys(): void
    {
        $target = $this->generate();
        $env = (string) file_get_contents($target . '/.env.example');

        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            if (preg_match('/^(SESAME_|HELMET_)[A-Z0-9_]*=$/', $line) === 1) {
                self::fail('Uncommented empty key in .env.example: ' . $line);
            }
        }

        self::assertStringContainsString('SESAME_DRIVER=fake', $env);
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

    /**
     * Practice 4.14, release half: Squire's kit (0.9.0 and later) refuses a
     * Laravel release with no APP_KEY or with APP_DEBUG on in production,
     * and warns when APP_ENV is not production, before anything installs.
     */
    public function testReleaseScriptChecksTheAppKeyAndDebug(): void
    {
        $target = $this->generate();
        $release = (string) file_get_contents($target . '/scripts/release.sh');

        self::assertStringContainsString('fail "APP_KEY is not set;', $release);
        self::assertStringContainsString('fail "APP_DEBUG must be false in production', $release);
        self::assertStringContainsString('warn "APP_ENV is \'$app_env\', not production;', $release);
    }

    public function testWritesTheManifest(): void
    {
        $target = $this->generate();

        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame('laravel-blade', $manifest['template']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertSame('seeded', $manifest['files']['.env.example']['class']);
        self::assertSame('managed', $manifest['files']['routes/web.php']['class']);
        self::assertSame('managed', $manifest['files']['scripts/release.sh']['class']);
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

    public function testBladeRequireModeAddsComposerPlaybookScript(): void
    {
        $target = $this->generate(true, PlaybookMode::Require);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('@composer show waynestate/playbook --no-interaction', $composer['scripts']['playbook']);
        self::assertSame('Check that Playbook (WSU shared values) is installed', $composer['scripts-descriptions']['playbook']);
    }

    private function generator(): LaravelBladeGenerator
    {
        return new LaravelBladeGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
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

    public function testShipsNoNpmFilesWithoutAFrontEnd(): void
    {
        $target = $this->generate();

        // Plain blade ships no front-end overlay, so it gets neither the npm
        // release cooldown nor the Node floor, even though a real skeleton
        // carries its own package.json.
        self::assertFileDoesNotExist($target . '/.npmrc');
        self::assertFileDoesNotExist($target . '/.nvmrc');
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

        TemplateCiGatesTest::assertBakedPipelineGates($ci, 'laravel-blade');
        foreach (['preflight/sbom@', 'preflight/secret-detection@', 'preflight/a11y@', "\na11y-pages:\n", "\n.deploy:\n"] as $gate) {
            self::assertStringContainsString($gate, $ci);
        }
    }

    public function testGeneratedReadmeCarriesTheSupportLine(): void
    {
        $target = $this->generate();
        $readme = (string) file_get_contents($target . '/README.md');

        self::assertStringContainsString('## Support', $readme);
        self::assertStringContainsString(
            ShapeSupport::load()->line(Permutation::LaravelBlade, PhpFloor::resolve(ComposerSkeletonSource::laravelMajor())),
            $readme,
        );
        self::assertStringNotContainsString('{{support_line}}', $readme);
    }

    private function generate(bool $advisor = true, PlaybookMode $playbook = PlaybookMode::Suggest): string
    {
        $target = $this->makeTempDir();
        $this->generator()->generate($target, 'My Cool App', [], $playbook, $advisor);

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
