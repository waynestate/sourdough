<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\GeneratorSupport;

final class GeneratorSupportTest extends TestCase
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

    public function testInstallsTheLaravelFlavourFilesFromTheSquireCopy(): void
    {
        $target = $this->makeTempDir();

        (new GeneratorSupport())->installSquireFiles($target, 'laravel');

        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../templates/squire/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            self::fail('templates/squire/manifest.json must list its files');
        }

        $installed = 0;
        foreach ($decoded['files'] as $key => $entry) {
            if (!is_string($key) || !str_starts_with($key, 'laravel/')) {
                continue;
            }
            $installed++;

            $path = substr($key, strlen('laravel/'));
            $file = $target . '/' . $path;

            // The file is installed at its flavour path, as-is.
            self::assertFileExists($file, $path);
            self::assertSame(
                (string) file_get_contents(__DIR__ . '/../templates/squire/laravel/' . $path),
                (string) file_get_contents($file),
                $path,
            );

            // The executable bit comes from the manifest's mode. This needs
            // a Unix filesystem; the test suite runs in Docker.
            $mode = 0644;
            if (is_array($entry) && isset($entry['mode'])) {
                $mode = intval((string) $entry['mode'], 8);
            }
            self::assertSame(0 !== ($mode & 0100), 0 !== (fileperms($file) & 0100), $path);
        }

        self::assertGreaterThan(0, $installed, 'the laravel flavour must install at least one file');
    }

    public function testLaravelFlavourExecutableBitsMatchTheManifest(): void
    {
        $target = $this->makeTempDir();

        (new GeneratorSupport())->installSquireFiles($target, 'laravel');

        // Spot checks straight from the manifest: the 755 files are
        // executable, the 644 files are not.
        foreach ([
            'build.sh',
            'deployments/001-first-release.sh',
            'scripts/release.sh',
            'scripts/parity.sh',
            '.squire/fallback/citadel-apache-entrypoint',
            '.squire/fallback/citadel-deploy',
        ] as $path) {
            self::assertNotSame(0, fileperms($target . '/' . $path) & 0111, $path . ' should be executable');
        }

        foreach ([
            'health.php',
            'docker-compose.squire.yml',
            '.env.squire.example',
            'scripts/parity.ps1',
            '.squire/Dockerfile',
        ] as $path) {
            self::assertSame(0, fileperms($target . '/' . $path) & 0111, $path . ' should not be executable');
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
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
}
