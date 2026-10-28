<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

final class SquireCopyCheckTest extends TestCase
{
    public function testSkipsWithExitZeroWhenNoSquireCheckout(): void
    {
        $script = __DIR__ . '/../scripts/squire-copy-check.php';
        self::assertFileExists($script);

        // Point SQUIRE_PATH at a path with no export, so the check skips
        // instead of failing: a build without a SQUIRE checkout is not an error.
        putenv('SQUIRE_PATH=/nonexistent-squire-checkout');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        putenv('SQUIRE_PATH');

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('skipping', $stdout);
    }
}
