<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\SailScaffold;

/**
 * SailScaffold lays Sail onto a generated Laravel app: laravel/sail in
 * require-dev, the compose file, the .env.example lines and the README
 * section. It writes files and edits composer.json; it runs no process.
 */
final class SailScaffoldTest extends TestCase
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

    public function testAddsSailToRequireDevSortedAndKeepsTheRest(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);

        (new SailScaffold())->apply($dir);

        $raw = (string) file_get_contents($dir . '/composer.json');
        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // laravel/sail lands in require-dev with the scaffold's constraint...
        self::assertSame('^1.41', $composer['require-dev']['laravel/sail']);
        // ...sorted alphabetically by package name, ahead of the entries it
        // found (which were seeded unsorted)...
        self::assertSame(
            ['laravel/sail', 'phpunit/phpunit', 'waynestate/proctor'],
            array_keys($composer['require-dev']),
        );
        // ...while everything else survives.
        self::assertSame('app/demo', $composer['name']);
        self::assertSame(['php' => '^8.3', 'laravel/framework' => '^13.0'], $composer['require']);

        // The formatting rules hold: pretty printed, unescaped slashes,
        // trailing newline.
        self::assertStringEndsWith("\n", $raw);
        self::assertStringNotContainsString('\/', $raw);
    }

    public function testCreatesRequireDevWhenMissing(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'app/demo',
            'require' => ['php' => '^8.3'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        (new SailScaffold())->apply($dir);

        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['laravel/sail' => '^1.41'], $composer['require-dev']);
    }

    public function testComposerEditIsIdempotent(): void
    {
        $first = $this->makeTempDir();
        $this->writeComposer($first);
        (new SailScaffold())->apply($first);
        $expected = (string) file_get_contents($first . '/composer.json');

        // A second bake starts from the first bake's composer.json: the
        // package is already there, so the bytes come out the same.
        $second = $this->makeTempDir();
        file_put_contents($second . '/composer.json', $expected);
        (new SailScaffold())->apply($second);

        self::assertSame($expected, (string) file_get_contents($second . '/composer.json'));
    }

    public function testWritesTheComposeFile(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);

        (new SailScaffold())->apply($dir);

        $yaml = (string) file_get_contents($dir . '/compose.yaml');
        self::assertStringContainsString('sail-8.4/app', $yaml);
        self::assertStringContainsString("./vendor/laravel/sail/runtimes/8.4", $yaml);
        self::assertStringContainsString('mysql:8.4', $yaml);
        self::assertStringContainsString('sail-mysql', $yaml);
        self::assertStringContainsString('service_healthy', $yaml);
    }

    public function testRefusesAnExistingComposeFile(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);
        file_put_contents($dir . '/compose.yaml', "services: {}\n");
        $composerBefore = (string) file_get_contents($dir . '/composer.json');

        try {
            (new SailScaffold())->apply($dir);
            self::fail('Expected a RuntimeException for the existing compose.yaml.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('compose.yaml', $exception->getMessage());
        }

        // The refusal happens before any write: nothing else was touched.
        self::assertSame($composerBefore, (string) file_get_contents($dir . '/composer.json'));
        self::assertFileDoesNotExist($dir . '/.env.example');
        self::assertFileDoesNotExist($dir . '/README.md');
    }

    public function testReplacesExistingEnvLinesCommentedOrNot(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);
        file_put_contents($dir . '/.env.example', "APP_ENV=local\n# DB_HOST=127.0.0.1\nDB_CONNECTION=sqlite\nAPP_DEBUG=true\n");

        (new SailScaffold())->apply($dir);

        $env = (string) file_get_contents($dir . '/.env.example');
        // The commented line and the live line are replaced in place...
        self::assertStringContainsString('DB_HOST=mysql', $env);
        self::assertStringNotContainsString('# DB_HOST=127.0.0.1', $env);
        self::assertStringContainsString('DB_CONNECTION=mysql', $env);
        self::assertStringNotContainsString('DB_CONNECTION=sqlite', $env);
        // ...every other line survives, in its place...
        self::assertStringContainsString('APP_ENV=local', $env);
        self::assertStringContainsString('APP_DEBUG=true', $env);
        // ...and APP_PORT, which had no line, is appended at the end.
        self::assertStringContainsString('APP_PORT=8000', $env);
        $debugPos = strpos($env, 'APP_DEBUG=true');
        $portPos = strpos($env, 'APP_PORT=8000');
        self::assertIsInt($debugPos);
        self::assertIsInt($portPos);
        self::assertLessThan($portPos, $debugPos);
    }

    public function testCreatesTheEnvExampleWhenMissing(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);

        (new SailScaffold())->apply($dir);

        self::assertFileExists($dir . '/.env.example');
        // Ten lines, in order, LF endings, trailing newline.
        self::assertSame(
            "DB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=laravel\nDB_USERNAME=sail\nDB_PASSWORD=password\nAPP_PORT=8000\nVITE_PORT=5173\nWWWUSER=1000\nWWWGROUP=1000\n",
            (string) file_get_contents($dir . '/.env.example'),
        );
    }

    public function testEnvExampleIsIdempotent(): void
    {
        $first = $this->makeTempDir();
        $this->writeComposer($first);
        file_put_contents($first . '/.env.example', "APP_ENV=local\n# DB_HOST=127.0.0.1\nDB_CONNECTION=sqlite\n");
        (new SailScaffold())->apply($first);
        $expected = (string) file_get_contents($first . '/.env.example');

        $second = $this->makeTempDir();
        $this->writeComposer($second);
        file_put_contents($second . '/.env.example', $expected);
        (new SailScaffold())->apply($second);

        self::assertSame($expected, (string) file_get_contents($second . '/.env.example'));
    }

    public function testAppendsTheReadmeSectionOnce(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);
        file_put_contents($dir . '/README.md', "# Demo\n\nSome docs.\n");

        (new SailScaffold())->apply($dir);

        $readme = (string) file_get_contents($dir . '/README.md');
        // The app's own text survives, separated from the section by a
        // blank line...
        self::assertStringStartsWith("# Demo\n\nSome docs.", $readme);
        self::assertStringContainsString("Some docs.\n\n## Run it locally with Sail", $readme);
        // ...and the section lands exactly once, with its steps and URL.
        self::assertSame(1, substr_count($readme, '## Run it locally with Sail'));
        self::assertStringContainsString('./vendor/bin/sail up -d', $readme);
        self::assertStringContainsString('http://localhost:8000', $readme);
    }

    public function testCreatesTheReadmeWhenMissing(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);

        (new SailScaffold())->apply($dir);

        $readme = (string) file_get_contents($dir . '/README.md');
        self::assertStringStartsWith("## Run it locally with Sail\n", $readme);
        self::assertSame(1, substr_count($readme, '## Run it locally with Sail'));
    }

    public function testSkipsTheReadmeSectionWhenTheMarkerIsPresent(): void
    {
        $dir = $this->makeTempDir();
        $this->writeComposer($dir);
        $readme = "# Demo\n\n## Run it locally with Sail\n\nAlready documented.\n";
        file_put_contents($dir . '/README.md', $readme);

        (new SailScaffold())->apply($dir);

        self::assertSame($readme, (string) file_get_contents($dir . '/README.md'));
    }

    public function testMissingComposerJsonThrows(): void
    {
        $dir = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json');

        (new SailScaffold())->apply($dir);
    }

    public function testInvalidComposerJsonThrows(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/composer.json', "{ not json");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer.json');

        (new SailScaffold())->apply($dir);
    }

    /**
     * The real binary, with --laravel --sail --dry-run: exit 0, the plan on
     * stdout carries the Sail step, and nothing is written.
     */
    public function testRealBinarySailDryRunPrintsThePlanAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        [$exit, $stdout, $stderr] = $this->runNew($target, ['--laravel', '--sail', '--dry-run']);

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('Permutation:', $stdout);
        self::assertStringContainsString('Use Sail for the local runtime', $stdout);
        self::assertDirectoryDoesNotExist($target);
    }

    /**
     * The real binary refuses --sail and --docker together: exit 1, the
     * reason on stderr, nothing written.
     */
    public function testRealBinaryRefusesSailAndDockerTogether(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        [$exit, $stdout, $stderr] = $this->runNew($target, ['--laravel', '--sail', '--docker', '--dry-run']);

        self::assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('Choose one of --sail or --docker', $stderr);
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

    /**
     * Seed the dir with a minimal app composer.json: require-dev on
     * purpose unsorted, so the sort has something to do.
     */
    private function writeComposer(string $dir): void
    {
        $composer = [
            'name' => 'app/demo',
            'description' => 'Demo, a Wayne State app baked from Sourdough.',
            'require' => ['php' => '^8.3', 'laravel/framework' => '^13.0'],
            'require-dev' => ['waynestate/proctor' => '^0.4', 'phpunit/phpunit' => '^11.5'],
        ];
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-sail-' . bin2hex(random_bytes(8));
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
