<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The two small raw-shape fixes from the 1103 end-to-end proof:
 *
 *  - the raw template's pipeline gets one explicit composer ci job, so
 *    PROCTOR no longer warns that no job runs composer ci;
 *  - the raw front controller 404s unknown paths instead of rendering the
 *    home page for every URL.
 *
 * Both are checked on a raw app baked from the real templates/raw dir.
 */
final class RawSmallFixesTest extends TestCase
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

    public function testBakedRawPipelineRunsComposerCi(): void
    {
        $target = $this->generateRawApp();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        self::assertStringContainsString('composer-ci:', $ci);
        // The job's own block installs dependencies, then runs composer ci.
        $job = substr($ci, (int) strpos($ci, 'composer-ci:'));
        self::assertStringContainsString('script:', $job);
        self::assertStringContainsString('composer install', $job);
        self::assertStringContainsString('composer ci', $job);
    }

    public function testBakedRawFrontController404sUnknownPaths(): void
    {
        $target = $this->generateRawApp();
        $index = (string) file_get_contents($target . '/public/index.php');

        // Unknown paths get a 404, not the home page rendered again.
        self::assertStringContainsString("parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)", $index);
        self::assertStringContainsString('http_response_code(404)', $index);
        // The not-found page keeps WayneUI's layout and links back home.
        self::assertStringContainsString('href="/"', $index);
    }

    private function generateRawApp(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App', [], PlaybookMode::None, true);

        return $target;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-rawfixes-' . bin2hex(random_bytes(8));
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
