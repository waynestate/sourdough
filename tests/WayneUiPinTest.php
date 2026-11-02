<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

final class WayneUiPinTest extends TestCase
{
    public function testNoFileReferencesTheFloatingMainBranchCopy(): void
    {
        $dirs = [__DIR__ . '/../templates', __DIR__ . '/../site'];
        $offenders = [];

        foreach ($dirs as $dir) {
            foreach ($this->allFiles($dir) as $file) {
                $content = (string) file_get_contents($file);
                if (str_contains($content, 'raw/main/php/standalone')) {
                    $offenders[] = $file;
                }
            }
        }

        self::assertSame([], $offenders, 'Files still reference the floating main branch copy of WayneUI: ' . implode(', ', $offenders));
    }

    public function testRawReadmePinsWayneUiRelease208(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../templates/raw/README.md');

        self::assertStringContainsString('/v2/releases/2.0.8/wayne-ui.php', $readme);
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
}
