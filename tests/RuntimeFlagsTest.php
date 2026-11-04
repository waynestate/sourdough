<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * --sail and --docker are accepted but not built yet. The command prints the
 * plan (with the requested step) and then refuses with "not available in this
 * release" on stderr, exit 1, writing nothing. A dry run refuses the same way.
 *
 * These run the real binary so stdout and stderr stay separate: the plan must
 * land on stdout and the refusal on stderr, where scripts can read it.
 */
final class RuntimeFlagsTest extends TestCase
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

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function runtimeFlagCombos(): iterable
    {
        yield 'laravel + sail' => [['--laravel', '--sail'], 'Use Sail for the local runtime'];
        yield 'laravel + docker' => [['--laravel', '--docker'], 'Use Docker for the local runtime'];
        yield 'inertia + sail' => [['--inertia', '--sail'], 'Use Sail for the local runtime'];
    }

    #[DataProvider('runtimeFlagCombos')]
    public function testRuntimeFlagsRefuseAfterPrintingThePlan(array $flags, string $planStep): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        [$exit, $stdout, $stderr] = $this->runNew($target, $flags);

        self::assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        // The plan is printed first, on stdout, including the requested step.
        self::assertStringContainsString('Permutation:', $stdout);
        self::assertStringContainsString($planStep, $stdout);
        // The refusal goes to stderr.
        self::assertStringContainsString('not available in this release', $stderr);
        // Nothing is written.
        self::assertDirectoryDoesNotExist($target);
    }

    public function testDryRunWithARuntimeFlagPrintsThePlanAndRefuses(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        [$exit, $stdout, $stderr] = $this->runNew($target, ['--laravel', '--sail', '--dry-run']);

        self::assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('Permutation:', $stdout);
        self::assertStringContainsString('Use Sail for the local runtime', $stdout);
        self::assertStringContainsString('not available in this release', $stderr);
        self::assertDirectoryDoesNotExist($target);
    }

    /**
     * Run `sourdough new` with the given flags against the real binary and
     * capture stdout and stderr separately.
     *
     * @param list<string> $flags
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runNew(string $target, array $flags): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $argv = array_merge([PHP_BINARY, __DIR__ . '/../bin/sourdough', 'new', $target], $flags);
        $process = proc_open($argv, $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-runtime-' . bin2hex(random_bytes(8));
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
