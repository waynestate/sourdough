<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SquireCopyCheckTest extends TestCase
{
    private ?string $driftTarget = null;

    protected function tearDown(): void
    {
        if ($this->driftTarget !== null) {
            self::removeDirectory($this->driftTarget);
            $this->driftTarget = null;
        }

        parent::tearDown();
    }

    public function testCheckPassesOnTheCommittedCopy(): void
    {
        [$exit, $stdout, $stderr] = $this->runSyncSquire(['--check']);

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('OK:', $stdout);
    }

    public function testCheckExitsOneWhenACopyFileDrifts(): void
    {
        $source = __DIR__ . '/../templates/squire';
        $target = sys_get_temp_dir() . '/squire-drift-' . uniqid('', true);
        $this->driftTarget = $target;
        $this->copyDirectory($source, $target);

        file_put_contents($target . '/raw/scripts/release.sh', "# drifted for the test\n");

        [$exit, $stdout, $stderr] = $this->runSyncSquire(['--check', '--to=' . $target]);

        self::assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('raw/scripts/release.sh', $stdout);
    }

    /**
     * Run bin/sync-squire with the given arguments.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runSyncSquire(array $args): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/sync-squire', ...$args], $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
            self::fail("Cannot create {$target}.");
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source));
            $destination = $target . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0o755, true) && !is_dir($destination)) {
                    self::fail("Cannot create {$destination}.");
                }
            } else {
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
                    self::fail("Cannot create {$parent}.");
                }
                if (copy($item->getPathname(), $destination) === false) {
                    self::fail("Cannot copy {$item->getPathname()} to {$destination}.");
                }
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
