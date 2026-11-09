<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The raw shape ships Pinstripe's wiring, and the code it bakes passes
 * both of Pinstripe's tools: Pint on the per preset, PHPStan at level 8.
 *
 * A real app gets the toolchain from composer install. This suite runs
 * on a bare php image with no oci8 and without the HELMET or SESAME
 * packages, so each functional test bakes an app and lays down only what
 * the two tools need: Pinstripe's package (so the neon include resolves)
 * and stubs for the symbols the template calls. PHPStan runs from the
 * repository root so its phar loads this repository's vendor/autoload.php,
 * which autoloads extension-installer's GeneratedConfig and registers the
 * deprecation and strict rules, exactly like a real install does.
 */
final class PinstripeRawTest extends TestCase
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

    public function testGeneratedComposerWiresPinstripe(): void
    {
        $target = $this->bakeApp();
        $composer = $this->readJson($target . '/composer.json');

        self::assertSame('^0.3', $composer['require-dev']['waynestate/pinstripe']);
        self::assertTrue($composer['config']['allow-plugins']['phpstan/extension-installer']);
        self::assertSame('pint --test', $composer['scripts']['lint']);
        self::assertSame('pint', $composer['scripts']['fix']);
        self::assertSame('phpstan analyse', $composer['scripts']['analyse']);
        self::assertSame(['@test', '@lint', '@analyse', '@proctor', 'composer audit'], $composer['scripts']['ci']);
        self::assertArrayHasKey('lint', $composer['scripts-descriptions']);
        self::assertArrayHasKey('analyse', $composer['scripts-descriptions']);
    }

    public function testGeneratedAppShipsThePinstripeConfigs(): void
    {
        $target = $this->bakeApp();

        // Pint: the per preset, the fetched WayneUI file kept out, and
        // vendor excluded because Pint's built-in excludes do not cover it.
        $pint = $this->readJson($target . '/pint.json');
        self::assertSame('per', $pint['preset']);
        self::assertContains('vendor', $pint['exclude']);
        self::assertContains('public/wayne-ui.php', $pint['notPath']);

        // PHPStan: Pinstripe's raw preset owns the level; the app names
        // the paths.
        $neon = (string) file_get_contents($target . '/phpstan.neon');
        self::assertStringContainsString('vendor/waynestate/pinstripe/phpstan/raw.neon', $neon);
        self::assertStringContainsString('- src', $neon);
        self::assertStringContainsString('- public', $neon);

        // Both configs are managed, so a re-run of sourdough may update them.
        $manifest = $this->readJson($target . '/.sourdough/manifest.json');
        self::assertSame('managed', $manifest['files']['pint.json']['class']);
        self::assertSame('managed', $manifest['files']['phpstan.neon']['class']);
    }

    public function testBakedAppPassesPint(): void
    {
        $app = $this->bakeWithToolchain();
        [$exit, $output] = $this->runPint($app, ['--test']);

        self::assertSame(0, $exit, $output);
    }

    public function testBakedAppFailsPintWhenTheStyleIsBroken(): void
    {
        $app = $this->bakeWithToolchain();
        file_put_contents(
            $app . '/src/StyleBreak.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

class StyleBreak
{
}
PHP,
        );

        [$exit, $output] = $this->runPint($app, ['--test']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('StyleBreak', $output);
    }

    public function testBakedAppPassesPhpStan(): void
    {
        $app = $this->bakeWithToolchain();
        [$exit, $output] = $this->runPhpStan($app);

        self::assertSame(0, $exit, $output);
    }

    public function testBakedAppFailsPhpStanOnATypeError(): void
    {
        $app = $this->bakeWithToolchain();
        file_put_contents(
            $app . '/src/TypeBreak.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

function type_break(int $count): string
{
    return $count;
}
PHP,
        );

        [$exit, $output] = $this->runPhpStan($app);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('TypeBreak', $output);
    }

    public function testPhpStanReportsDeprecationsThroughTheInstaller(): void
    {
        // deprecation-rules arrives through extension-installer, which a
        // real install registers via GeneratedConfig. If that wiring is
        // broken, this probe stops failing and the gate goes quiet.
        $app = $this->bakeWithToolchain();
        file_put_contents($app . '/src/DeprecationProbe.php', "<?php\n\nutf8_encode('x');\n");

        [$exit, $output] = $this->runPhpStan($app, [$app . '/src/DeprecationProbe.php']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('deprecated', $output);
    }

    /**
     * Bake an app from the real template.
     */
    private function bakeApp(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        return $target;
    }

    /**
     * Bake an app and lay down the toolchain pieces a composer install
     * would provide: Pinstripe's package and the test-only stubs.
     */
    private function bakeWithToolchain(): string
    {
        $app = $this->bakeApp();

        $pinstripe = $this->repoVendor() . '/waynestate/pinstripe';
        if (!is_dir($pinstripe)) {
            $this->markTestSkipped('waynestate/pinstripe is not installed; run composer install.');
        }
        mkdir($app . '/vendor/waynestate', 0777, true);
        $this->copyDir($pinstripe, $app . '/vendor/waynestate/pinstripe');

        $stubsDir = $app . '/vendor/sourdough-test-stubs';
        mkdir($stubsDir, 0777, true);
        copy(__DIR__ . '/fixtures/pinstripe/stubs.php', $stubsDir . '/stubs.php');

        return $app;
    }

    private function repoVendor(): string
    {
        return dirname(__DIR__) . '/vendor';
    }

    /**
     * Run Pint in the app dir, where it finds the app's pint.json.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function runPint(string $app, array $args): array
    {
        $pint = $this->repoVendor() . '/laravel/pint/builds/pint';
        if (!is_file($pint)) {
            $this->markTestSkipped('laravel/pint is not installed; run composer install.');
        }

        return $this->runProcess([PHP_BINARY, $pint, ...$args], $app);
    }

    /**
     * Run PHPStan against the app's phpstan.neon.
     *
     * The working directory is the repository root, not the app: the phar
     * loads the first vendor/autoload.php it finds from the working
     * directory, and this repository's is what autoloads
     * extension-installer's GeneratedConfig, the same way an app's own
     * autoloader would after a composer install.
     *
     * @param list<string> $paths optional paths that override the config's
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function runPhpStan(string $app, array $paths = []): array
    {
        $phar = $this->repoVendor() . '/phpstan/phpstan/phpstan.phar';
        if (!is_file($phar)) {
            $this->markTestSkipped('phpstan/phpstan is not installed; run composer install.');
        }

        $cmd = [
            PHP_BINARY,
            $phar,
            'analyse',
            '-c',
            $app . '/phpstan.neon',
            '--autoload-file',
            $app . '/vendor/sourdough-test-stubs/stubs.php',
            '--no-progress',
        ];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        return $this->runProcess($cmd, dirname(__DIR__));
    }

    /**
     * @param list<string> $cmd
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function runProcess(array $cmd, string $cwd): array
    {
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            self::fail('Could not start: ' . implode(' ', $cmd));
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-pinstripe-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
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

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . '/' . $entry;
            $dest = $to . '/' . $entry;
            if (is_dir($source)) {
                $this->copyDir($source, $dest);
            } else {
                copy($source, $dest);
            }
        }
    }
}
