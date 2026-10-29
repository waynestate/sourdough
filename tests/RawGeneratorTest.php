<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;

final class RawGeneratorTest extends TestCase
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

    public function testGeneratesAppWithReplacedNamesAndSlugs(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        self::assertFileExists($target . '/README.md');
        self::assertFileExists($target . '/my-cool-app.txt');
        self::assertFileExists($target . '/config/my-cool-app.ini');

        self::assertSame("Welcome to My Cool App (my-cool-app).\n", file_get_contents($target . '/README.md'));
        self::assertSame("Slug file for My Cool App.\n", file_get_contents($target . '/my-cool-app.txt'));
        self::assertSame("app=My Cool App\n", file_get_contents($target . '/config/my-cool-app.ini'));

        // No placeholder survives anywhere in the generated tree.
        foreach ($this->allFiles($target) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('{{app_name}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', basename($file), $file);
        }
    }

    public function testWritesTheManifestAfterGeneration(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame('raw', $manifest['template']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertSame('managed', $manifest['files']['my-cool-app.txt']['class']);
        self::assertSame('managed', $manifest['files']['config/my-cool-app.ini']['class']);
    }

    public function testRefusesNonEmptyTarget(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testRefusesTargetThatIsAFile(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/a-file';
        file_put_contents($target, 'i am a file');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a file, not a directory');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testWritesNothingOutsideTheTarget(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/app';

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        // The parent should contain only the target dir, nothing else.
        $entries = array_values(array_diff(scandir($parent) ?: [], ['.', '..']));
        self::assertSame(['app'], $entries);
    }

    public function testMissingTemplateDirThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new RawGenerator($target . '/no-such-template'))->generate($target, 'My Cool App');
    }

    public function testNameWithOnlySymbolsThrows(): void
    {
        $target = $this->makeTempDir();

        // The name allow-list rejects this before the slug check runs.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with a letter or number');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, '!!!');
    }

    #[DataProvider('hostileNames')]
    public function testHostileNamesThrowAndWriteNothing(string $name): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/app';

        try {
            (new RawGenerator($this->fixtureTemplateDir()))->generate($target, $name);
            self::fail('Expected \InvalidArgumentException for ' . var_export($name, true));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must start with a letter or number', $e->getMessage());
        }

        // The name check runs before anything touches the disk, so the target
        // is absent, or empty if something created it anyway.
        self::assertFalse(is_file($target), var_export($name, true));
        if (is_dir($target)) {
            self::assertSame(['.', '..'], scandir($target) ?: [], var_export($name, true));
        }
    }

    public function testRealTemplateAcceptsANameWithSpacesDotsAndUnderscores(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My App v2.1_beta');

        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('My App v2.1_beta', $composer['description']);
    }

    public function testGeneratedIndexUsesNewSesameConstructor(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The config is built from an array with the fake driver's keys,
        // nested the way Config::fromArray reads them.
        self::assertStringContainsString("'driver' => 'fake'", $index);
        self::assertStringContainsString("'environment' => \$appEnv", $index);
        self::assertStringContainsString("'fake' => [", $index);
        self::assertStringContainsString("'username' => 'jdoe'", $index);
        self::assertStringContainsString("'attributes' => ['cn' => ['John Doe']]", $index);
        self::assertStringContainsString('Config::fromArray(', $index);

        // The driver comes from the factory, not a direct FakeDriver.
        self::assertStringContainsString('DriverFactory::make($config)', $index);
        self::assertStringNotContainsString('new FakeDriver(', $index);

        // new Sesame(driver, resolver, session, config): four arguments.
        self::assertMatchesRegularExpression(
            '/new Sesame\(\s*DriverFactory::make\(\$config\),\s*new class implements UserResolver \{[\s\S]*?\},\s*new NativeSession\(\),\s*\$config,\s*\);/',
            $index,
        );

        // The optional session limits come from the environment, cast to int,
        // and are nested under 'session'.
        self::assertStringContainsString("getenv('SESAME_SESSION_IDLE_MINUTES')", $index);
        self::assertStringContainsString("getenv('SESAME_SESSION_ABSOLUTE_MINUTES')", $index);
        self::assertStringContainsString("'idle_minutes'] = (int)", $index);
        self::assertStringContainsString("'absolute_minutes'] = (int)", $index);
        self::assertStringContainsString("\$sesameConfig['session'] = \$sessionLimits", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESAME_SESSION_IDLE_MINUTES=', $env);
        self::assertStringContainsString('# SESAME_SESSION_ABSOLUTE_MINUTES=', $env);
    }

    public function testGeneratedIndexWiresSesameInEveryEnvironment(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // Log in only when needed: use the signed-in user, or log in.
        self::assertStringContainsString('$user = sesame_user() ?? sesame_login();', $index);

        // The whole SESAME block is no longer gated on a non-production
        // environment; the driver is chosen per environment instead.
        self::assertStringNotContainsString("!== 'production'", $index);

        // The cas driver is wired for production, with both URLs from the
        // environment, nested the way Config::fromArray reads them.
        self::assertStringContainsString("getenv('SESAME_CAS_URL')", $index);
        self::assertStringContainsString("getenv('SESAME_SERVICE_URL')", $index);
        self::assertStringContainsString("'driver' => 'cas'", $index);
        self::assertStringContainsString("'cas' => [", $index);
        self::assertStringContainsString("'base_url' => \$casUrl", $index);
        self::assertStringContainsString("'service_url' => \$serviceUrl", $index);

        // The fake driver is still wired for non-production.
        self::assertStringContainsString("'driver' => 'fake'", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESAME_CAS_URL=', $env);
        self::assertStringContainsString('# SESAME_SERVICE_URL=', $env);
    }

    public function testGeneratedIndexHandlesUnsetEnvVars(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // getenv() returns false, not '', when a variable is unset, so the
        // generated code must never compare its result to '' directly.
        self::assertStringNotContainsString("'' !== getenv(", $index);

        // Config::fromArray reads dotted keys as nested arrays, so the fake
        // driver's keys must be nested, not dotted.
        self::assertStringNotContainsString("'fake.username' =>", $index);

        // The session limits use the SESAME_SESSION_* names.
        self::assertStringContainsString('SESAME_SESSION_IDLE_MINUTES', $index);
    }

    public function testGeneratedIndexReadsTrustedProxiesFromEnv(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The hard-coded empty list and its Citadel comment are gone.
        self::assertStringNotContainsString("'trusted_proxies' => []", $index);
        self::assertStringNotContainsString('HA-Proxy', $index);

        // HELMET_PROXIES is comma-separated, trimmed, empty entries dropped.
        self::assertStringContainsString("getenv('HELMET_PROXIES')", $index);
        self::assertStringContainsString("explode(',',", $index);
        self::assertStringContainsString('trim($proxy)', $index);
        self::assertStringContainsString("'trusted_proxies' => \$trustedProxies", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# HELMET_PROXIES=', $env);
        self::assertStringContainsString('172.18.0.1', $env);
    }

    public function testGeneratedIndexLogsToStderrByDefault(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        self::assertStringContainsString("ini_set('log_errors', '1')", $index);
        self::assertStringContainsString("ini_set('error_log', getenv('LOG_FILE') ?: 'php://stderr')", $index);
        self::assertStringContainsString("ini_set('display_errors', \$appEnv === 'production' ? '0' : '1')", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# LOG_FILE=', $env);
    }

    public function testGeneratedIndexAppliesSessionStoreFromEnv(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        self::assertStringContainsString("getenv('SESSION_SAVE_HANDLER')", $index);
        self::assertStringContainsString("getenv('SESSION_SAVE_PATH')", $index);
        self::assertStringContainsString("ini_set('session.save_handler', \$sessionSaveHandler)", $index);
        self::assertStringContainsString("ini_set('session.save_path', \$sessionSavePath)", $index);

        // The ini_set calls must come before session_start().
        $iniPos = strpos($index, "ini_set('session.save_handler'");
        $startPos = strpos($index, 'session_start()');
        self::assertIsInt($iniPos);
        self::assertIsInt($startPos);
        self::assertLessThan($startPos, $iniPos);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESSION_SAVE_HANDLER=', $env);
        self::assertStringContainsString('# SESSION_SAVE_PATH=', $env);
        self::assertStringContainsString('shared store', $env);
    }

    public function testGeneratedReadmeHasOffCitadelSection(): void
    {
        $target = $this->generateFromRealTemplate();
        $readme = (string) file_get_contents($target . '/README.md');

        self::assertStringContainsString('## Running off Citadel', $readme);
        foreach (['APP_ENV', 'HELMET_PROXIES', 'LOG_FILE', 'SESSION_SAVE_HANDLER', 'SESSION_SAVE_PATH', 'SESAME_SESSION_IDLE_MINUTES', 'SESAME_SESSION_ABSOLUTE_MINUTES'] as $var) {
            self::assertStringContainsString('`' . $var . '`', $readme);
        }
        self::assertStringContainsString('scripts/release.sh', $readme);
        self::assertStringContainsString('Dockerfile comes later', $readme);
    }

    public function testInstallsSquireDeployFilesAtCatalogPaths(): void
    {
        $target = $this->generateFromRealTemplate();

        // The raw deploy files from SQUIRE's checked copy are installed at
        // their catalog paths, as-is.
        foreach ([
            'build.sh',
            'deployments/001-first-release.sh',
            'scripts/release.sh',
            'scripts/parity.sh',
            'scripts/parity.ps1',
            'health.php',
            'public/up.php',
            'docker-compose.squire.yml',
            '.env.squire.example',
            '.squire/Dockerfile',
            '.squire/Dockerfile.dockerignore',
            '.squire/base-fallback.Dockerfile',
            '.squire/haproxy.cfg',
            '.squire/php.ini',
            '.squire/vhost.conf',
            '.squire/fallback/citadel-apache-entrypoint',
            '.squire/fallback/citadel-deploy',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }
    }

    public function testSquireReleaseScriptIsExecutable(): void
    {
        $target = $this->generateFromRealTemplate();
        $path = $target . '/scripts/release.sh';

        self::assertFileExists($path);
        // The mode comes from SQUIRE's manifest (755). This needs a Unix
        // filesystem; the test suite runs in Docker.
        self::assertNotSame(0, fileperms($path) & 0111, 'scripts/release.sh should be executable');
    }

    public function testGeneratedAppHasNoSourdoughReleaseScripts(): void
    {
        $target = $this->generateFromRealTemplate();

        // The old Sourdough release scripts are gone; SQUIRE's are the only ones.
        self::assertFileDoesNotExist($target . '/deployments/release.sh');
        self::assertFileDoesNotExist($target . '/deployments/release.ps1');
        self::assertFileExists($target . '/scripts/release.sh');
        self::assertFileExists($target . '/scripts/parity.ps1');
    }

    public function testManifestListsSquireFilesAsManaged(): void
    {
        $target = $this->generateFromRealTemplate();
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['build.sh', 'scripts/release.sh', 'deployments/001-first-release.sh', '.squire/Dockerfile'] as $path) {
            self::assertArrayHasKey($path, $manifest['files'], $path);
            self::assertSame('managed', $manifest['files'][$path]['class'], $path);
        }
    }

    public function testGeneratedComposerHasNoRepositories(): void
    {
        $target = $this->generateFromRealTemplate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('repositories', $composer);
    }

    /**
     * Names that used to be pasted into file names and contents unescaped.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'double quote' => ['a"b'];
        yield 'single quote' => ["a'b"];
        yield 'backslash' => ['a\\b'];
        yield 'newline' => ["a\nb"];
        yield 'NUL byte' => ["a\x00b"];
        yield 'placeholder' => ['{{app_slug}}'];
        yield 'path traversal' => ['../x'];
        yield 'too long' => [str_repeat('a', 64)];
        yield 'empty' => [''];
        yield 'only dashes' => ['---'];
        yield 'leading dash' => ['-my-app'];
        yield 'leading dot' => ['.my-app'];
        yield 'unicode look-alike' => ["\u{430}pp"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield 'plain' => ['My App', 'my-app'];
        yield 'upper case' => ['MY APP', 'my-app'];
        yield 'spaces' => ['My  App', 'my-app'];
        yield 'underscores' => ['my_app', 'my-app'];
        yield 'dots' => ['my.app', 'my-app'];
        yield 'keeps dashes' => ['my-app', 'my-app'];
        yield 'keeps numbers' => ['app2', 'app2'];
        yield 'trims leading dash' => ['-my-app', 'my-app'];
        yield 'trims trailing dash' => ['my-app-', 'my-app'];
        yield 'slashes' => ['my/app', 'my-app'];
    }

    #[DataProvider('slugs')]
    public function testSlugify(string $name, string $expected): void
    {
        self::assertSame($expected, RawGenerator::slugify($name));
    }

    private function fixtureTemplateDir(): string
    {
        return __DIR__ . '/fixtures/template';
    }

    /**
     * Generate an app from the real templates/raw dir and return the target.
     */
    private function generateFromRealTemplate(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App');

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
            if ('.' === $entry || '..' === $entry) {
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
