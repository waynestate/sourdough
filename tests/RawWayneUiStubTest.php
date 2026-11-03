<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The raw template ships a static-analysis stub for the run-time WayneUI
 * functions, because the real file (public/wayne-ui.php) is fetched by the
 * developer and is not part of the template. PHPStan learns the functions
 * from the stub through phpstan.neon's scanFiles, with no ignoreErrors and
 * no baseline.
 */
final class RawWayneUiStubTest extends TestCase
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

    public function testBakedAppShipsTheWayneUiStub(): void
    {
        $target = $this->bakeFromRealTemplate();
        $stub = (string) file_get_contents($target . '/stubs/wayne-ui.php');

        self::assertStringContainsString('function wsu_shell_start(array $opts = []): string', $stub);
        self::assertStringContainsString('function wsu_shell_end(): string', $stub);
    }

    public function testPhpStanConfigScansTheStubAndIgnoresNothing(): void
    {
        $target = $this->bakeFromRealTemplate();
        $neon = (string) file_get_contents($target . '/phpstan.neon');

        self::assertStringContainsString('scanFiles:', $neon);
        self::assertStringContainsString('- stubs/wayne-ui.php', $neon);
        self::assertStringNotContainsString('ignoreErrors', $neon);
        self::assertStringNotContainsString('baseline', $neon);
    }

    public function testTheStubIsManagedByTheManifest(): void
    {
        $target = $this->bakeFromRealTemplate();
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('managed', $manifest['files']['stubs/wayne-ui.php']['class']);
    }

    /**
     * Bake an app from the real templates/raw dir and return the target.
     */
    private function bakeFromRealTemplate(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        return $target;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-stub-test-' . bin2hex(random_bytes(8));
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
