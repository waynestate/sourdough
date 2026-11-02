<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\PlaybookMode;

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
            'app/Models/User.php',
            'app/Sesame/UserResolver.php',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            'config/helmet.php',
            'config/sesame.php',
            'database/migrations/2026_10_28_000001_add_username_to_users_table.php',
            'phpstan.neon',
            'pint.json',
            'resources/views/dashboard.blade.php',
            'resources/views/home.blade.php',
            'resources/views/layouts/app.blade.php',
            'routes/web.php',
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
        self::assertSame('^0.2', $composer['require']['waynestate/sesame']);
        self::assertSame('^0.2', $composer['require']['waynestate/helmet']);
        // ...the dev tools in require-dev...
        self::assertSame('^3.13', $composer['require-dev']['larastan/larastan']);
        self::assertSame('^0.1', $composer['require-dev']['waynestate/advisor']);
        self::assertSame('^0.1', $composer['require-dev']['waynestate/creed']);
        self::assertSame('^0.1', $composer['require-dev']['waynestate/pinstripe']);
        self::assertSame('^0.2', $composer['require-dev']['waynestate/proctor']);
        // ...and the scripts join the skeleton's own.
        self::assertIsArray($composer['scripts']['ci']);
        self::assertContains('phpstan analyse --memory-limit=1G', $composer['scripts']['ci']);
        self::assertSame(
            'proctor check --policy=vendor/waynestate/creed/policy/policy.json',
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

    public function testAppendsTheEnvAdditions(): void
    {
        $target = $this->generate();
        $env = (string) file_get_contents($target . '/.env.example');

        // The skeleton's own lines survive...
        self::assertStringContainsString('APP_NAME=Laravel', $env);
        // ...and the stack's keys are appended, with the fake driver for
        // local work.
        self::assertStringContainsString('SESAME_DRIVER=fake', $env);
        self::assertStringContainsString('SESAME_CAS_BASE_URL=', $env);
        self::assertStringContainsString('HELMET_PRESET=', $env);
        self::assertStringContainsString('HELMET_PROXIES=', $env);
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

    private function generator(): LaravelBladeGenerator
    {
        return new LaravelBladeGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
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
