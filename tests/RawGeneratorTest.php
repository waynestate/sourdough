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

    public function testNameWithNoValidSlugThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not produce a valid slug');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, '!!!');
    }

    public function testGeneratedIndexUsesNewSesameConstructor(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The config is built from an array with the fake driver's keys.
        self::assertStringContainsString("'driver' => 'fake'", $index);
        self::assertStringContainsString("'environment' => \$appEnv", $index);
        self::assertStringContainsString("'fake.username' => 'jdoe'", $index);
        self::assertStringContainsString("'fake.attributes' => ['cn' => ['John Doe']]", $index);
        self::assertStringContainsString('Config::fromArray(', $index);

        // The driver comes from the factory, not a direct FakeDriver.
        self::assertStringContainsString('DriverFactory::make($config)', $index);
        self::assertStringNotContainsString('new FakeDriver(', $index);

        // new Sesame(driver, resolver, session, config) — four arguments.
        self::assertMatchesRegularExpression(
            '/new Sesame\(\s*DriverFactory::make\(\$config\),\s*new class implements UserResolver \{[\s\S]*?\},\s*new NativeSession\(\),\s*\$config,\s*\);/',
            $index
        );

        // The optional session limits come from the environment, cast to int.
        self::assertStringContainsString("getenv('SESAME_IDLE_MINUTES')", $index);
        self::assertStringContainsString("getenv('SESAME_ABSOLUTE_MINUTES')", $index);
        self::assertStringContainsString("'session.idle_minutes'] = (int)", $index);
        self::assertStringContainsString("'session.absolute_minutes'] = (int)", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESAME_IDLE_MINUTES=', $env);
        self::assertStringContainsString('# SESAME_ABSOLUTE_MINUTES=', $env);
    }

    public function testGeneratedIndexReadsTrustedProxiesFromEnv(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The hard-coded empty list and its Citadel comment are gone.
        self::assertStringNotContainsString("'trusted_proxies' => []", $index);
        self::assertStringNotContainsString('HA-Proxy address goes here', $index);

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
