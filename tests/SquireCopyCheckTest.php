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

    private ?string $squireFixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->driftTarget !== null) {
            self::removeDirectory($this->driftTarget);
            $this->driftTarget = null;
        }

        if ($this->squireFixtureRoot !== null) {
            self::removeDirectory($this->squireFixtureRoot);
            $this->squireFixtureRoot = null;
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

    public function testDistInstallDoesNotReadTheSurroundingRepositoryVersion(): void
    {
        $this->skipWithoutGit();

        $root = $this->buildSquireCopyFixture(false);
        $this->squireFixtureRoot = $root;

        [$exit, $stdout, $stderr] = $this->runSyncSquire(['--check'], $root . '/bin/sync-squire');

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('SQUIRE NONE', $stdout);
        self::assertStringNotContainsString('outer', $stdout);
    }

    public function testSourceCheckoutReportsThePackagesOwnVersion(): void
    {
        $this->skipWithoutGit();

        $root = $this->buildSquireCopyFixture(true);
        $this->squireFixtureRoot = $root;

        [$exit, $stdout, $stderr] = $this->runSyncSquire(['--check'], $root . '/bin/sync-squire');

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('SQUIRE 0.4.0', $stdout);
    }

    /**
     * Run bin/sync-squire (or a copy of it) with the given arguments.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runSyncSquire(array $args, ?string $script = null): array
    {
        $script ??= __DIR__ . '/../bin/sync-squire';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, $script, ...$args], $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Skip the test when git cannot be found on this machine.
     */
    private function skipWithoutGit(): void
    {
        $version = shell_exec('git --version');
        if (!is_string($version) || !str_contains($version, 'git version')) {
            self::markTestSkipped('git is not on PATH.');
        }
    }

    /**
     * Build a throwaway git repository that stands in for this project,
     * with a fake waynestate/squire package in its vendor/. The outer
     * repository is tagged v9.9.9-outer, so a git describe that walks up
     * out of the package would read that tag. When $packageIsOwnRepo is
     * true the package is its own repository tagged 0.4.0, like a source
     * checkout; otherwise it is a plain directory, like a dist install.
     */
    private function buildSquireCopyFixture(bool $packageIsOwnRepo): string
    {
        $root = sys_get_temp_dir() . '/squire-copy-fixture-' . uniqid('', true);
        if (!is_dir($root) && !mkdir($root, 0o755, true) && !is_dir($root)) {
            self::fail("Cannot create {$root}.");
        }

        $binDir = $root . '/bin';
        $exportDir = $root . '/vendor/waynestate/squire/bin';
        foreach ([$binDir, $exportDir, $root . '/templates/squire'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                self::fail("Cannot create {$dir}.");
            }
        }

        if (copy(__DIR__ . '/../bin/sync-squire', $binDir . '/sync-squire') === false) {
            self::fail('Cannot copy bin/sync-squire into the fixture.');
        }
        if (file_put_contents($exportDir . '/export-templates.php', self::exporterStub()) === false) {
            self::fail('Cannot write the fake Squire exporter into the fixture.');
        }

        $this->gitCommand($root, ['init', '-q']);
        $this->gitCommand($root, ['config', 'user.name', 'Sourdough Test']);
        $this->gitCommand($root, ['config', 'user.email', 'sourdough@example.com']);
        $this->gitCommand($root, ['add', 'bin', 'vendor']);
        $this->gitCommand($root, ['commit', '-q', '-m', 'squire copy fixture']);
        $this->gitCommand($root, ['tag', 'v9.9.9-outer']);

        if ($packageIsOwnRepo) {
            $packageDir = $root . '/vendor/waynestate/squire';
            $this->gitCommand($packageDir, ['init', '-q']);
            $this->gitCommand($packageDir, ['config', 'user.name', 'Sourdough Test']);
            $this->gitCommand($packageDir, ['config', 'user.email', 'sourdough@example.com']);
            $this->gitCommand($packageDir, ['add', 'bin']);
            $this->gitCommand($packageDir, ['commit', '-q', '-m', 'fake squire package']);
            $this->gitCommand($packageDir, ['tag', '0.4.0']);
        }

        return $root;
    }

    /**
     * Run a git command in $cwd and fail the test when it does not exit 0.
     *
     * @param list<string> $arguments
     */
    private function gitCommand(string $cwd, array $arguments): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open(['git', ...$arguments], $descriptors, $pipes, $cwd);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $message = 'git ' . implode(' ', $arguments) . " did not exit 0.\nstdout: {$stdout}\nstderr: {$stderr}";
        self::assertSame(0, $exit, $message);
    }

    /**
     * The body of the fake vendor/waynestate/squire/bin/export-templates.php.
     *
     * It stands in for Squire's exporter: it reads the version the way
     * Squire's Exporter::version() starts, with a `git describe` from the
     * package directory, and prints it in the OK line. NONE stands for
     * "describe failed", which is what a dist install must produce.
     */
    private static function exporterStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

$to = 'unknown';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--to=')) {
        $to = substr($arg, 5);
    }
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open(['git', 'describe', '--tags', '--always'], $descriptors, $pipes, __DIR__);
$version = 'NONE';
if (is_resource($process)) {
    fclose($pipes[0]);
    $output = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit === 0 && $output !== '') {
        $version = $output;
    }
}

fwrite(STDOUT, "OK: {$to} matches SQUIRE {$version}.\n");
exit(0);
STUB;
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
