<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\DockerScaffold;

final class DockerScaffoldTest extends TestCase
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

    public function testWritesAllFiles(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);

        foreach ([
            'Dockerfile',
            'compose.yaml',
            '.dockerignore',
            'docker/php.ini',
            'docker/opcache.ini',
            'composer.json',
            '.env.example',
            'README.md',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }
    }

    public function testDockerfilePinsEveryBaseImageByDigest(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);
        $dockerfile = (string) file_get_contents($target . '/Dockerfile');

        foreach ([
            'sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48',
            'sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554',
            'sha256:4cc63b3d4e05c40da73e7af5d39364ca2678ac07208919497bda3bbab975be95',
        ] as $digest) {
            self::assertStringContainsString($digest, $dockerfile);
        }

        self::assertStringContainsString('AS dev', $dockerfile);
        self::assertStringContainsString('AS prod', $dockerfile);
        self::assertStringContainsString('--mount=type=secret,id=composer_auth', $dockerfile);
        self::assertStringContainsString('USER www-data', $dockerfile);

        // Playbook credentials reach the build only as a BuildKit secret.
        self::assertStringNotContainsString('ARG COMPOSER_AUTH', $dockerfile);
        self::assertStringNotContainsString('ARG GITHUB', $dockerfile);
        self::assertStringNotContainsString('ARG PLAYBOOK', $dockerfile);
        self::assertStringNotContainsString('ENV COMPOSER_AUTH', $dockerfile);

        // Every stage starts from a digest-pinned image.
        foreach (preg_split('/\R/', $dockerfile) ?: [] as $line) {
            if (str_starts_with($line, 'FROM')) {
                self::assertStringContainsString('@sha256:', $line, $line);
            }
        }
    }

    public function testComposeHasNoViteServiceByDefault(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);
        $compose = (string) file_get_contents($target . '/compose.yaml');

        self::assertStringNotContainsString('vite:', $compose);
        $this->assertYamlStructureHolds($compose);
    }

    public function testComposeGainsTheViteServiceBeforeVolumes(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, true);
        $compose = (string) file_get_contents($target . '/compose.yaml');

        // The vite service sits with the other services: once, and before
        // the top-level volumes: block.
        self::assertSame(1, preg_match_all('/^    vite:\R/m', $compose));
        $vitePos = strpos($compose, "    vite:");
        $volumesPos = strpos($compose, "\nvolumes:");
        self::assertIsInt($vitePos);
        self::assertIsInt($volumesPos);
        self::assertLessThan($volumesPos, $vitePos);

        $this->assertYamlStructureHolds($compose);
    }

    /**
     * `npm run dev -- --host 0.0.0.0` made laravel-vite-plugin write
     * http://0.0.0.0:5173 to public/hot, which the host's browser cannot load,
     * so every page rendered blank. LARAVEL_SAIL makes the plugin listen on
     * every address and still hand the browser localhost.
     */
    public function testViteServiceHandsTheBrowserLocalhost(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, true);
        $compose = (string) file_get_contents($target . '/compose.yaml');

        self::assertStringContainsString("command: ['npm', 'run', 'dev']", $compose);
        self::assertStringContainsString("LARAVEL_SAIL: '1'", $compose);
        self::assertStringNotContainsString('--host', $compose);
        $this->assertYamlStructureHolds($compose);
    }

    public function testDockerignoreListsEnvAndVendor(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);
        $ignore = (string) file_get_contents($target . '/.dockerignore');

        self::assertStringContainsString('.env', $ignore);
        self::assertStringContainsString('vendor', $ignore);
    }

    public function testComposerGainsDevScriptsAndKeepsTheOthers(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['Composer\Config::disableProcessTimeout', 'docker compose up --build'],
            $composer['scripts']['dev-up'],
        );
        self::assertSame('docker compose down', $composer['scripts']['dev-down']);
        self::assertSame(
            ['Composer\Config::disableProcessTimeout', 'docker compose exec app bash'],
            $composer['scripts']['dev-shell'],
        );
        self::assertSame(
            'Build and start the local Docker setup on http://localhost:8000.',
            $composer['scripts-descriptions']['dev-up'],
        );
        self::assertSame(
            'Stop the local Docker setup.',
            $composer['scripts-descriptions']['dev-down'],
        );
        self::assertSame(
            'Open a shell in the app container.',
            $composer['scripts-descriptions']['dev-shell'],
        );

        // The app's own scripts survive the edit.
        self::assertSame('phpunit', $composer['scripts']['test']);
        self::assertSame('Run the tests.', $composer['scripts-descriptions']['test']);
    }

    public function testApplyIsIdempotentPerFile(): void
    {
        $base = $this->makeAppDir();

        // A second apply on a fresh copy of the same starting dir produces
        // byte-identical composer.json and .env.example...
        $first = $this->copyDir($base);
        (new DockerScaffold())->apply($first, false);

        $second = $this->copyDir($base);
        (new DockerScaffold())->apply($second, false);

        self::assertSame(
            (string) file_get_contents($first . '/composer.json'),
            (string) file_get_contents($second . '/composer.json'),
        );
        self::assertSame(
            (string) file_get_contents($first . '/.env.example'),
            (string) file_get_contents($second . '/.env.example'),
        );

        // ...and running the composer and env edits over content that is
        // already applied changes nothing.
        $third = $this->makeTempDir();
        file_put_contents($third . '/composer.json', (string) file_get_contents($first . '/composer.json'));
        file_put_contents($third . '/.env.example', (string) file_get_contents($first . '/.env.example'));
        (new DockerScaffold())->apply($third, false);

        self::assertSame(
            (string) file_get_contents($first . '/composer.json'),
            (string) file_get_contents($third . '/composer.json'),
        );
        self::assertSame(
            (string) file_get_contents($first . '/.env.example'),
            (string) file_get_contents($third . '/.env.example'),
        );
    }

    public function testEnvExampleReplacesExistingLinesAndKeepsTheRest(): void
    {
        $target = $this->makeAppDir();
        (new DockerScaffold())->apply($target, false);
        $env = (string) file_get_contents($target . '/.env.example');

        // The skeleton's sqlite default and the commented host are replaced...
        self::assertStringContainsString('DB_CONNECTION=mysql', $env);
        self::assertStringNotContainsString('DB_CONNECTION=sqlite', $env);
        self::assertStringContainsString('DB_HOST=mysql', $env);
        self::assertStringNotContainsString('# DB_HOST=127.0.0.1', $env);
        // ...the other lines stay untouched...
        self::assertStringContainsString('APP_ENV=local', $env);
        self::assertStringContainsString('SESAME_DRIVER=fake', $env);
        // ...and the remaining keys land with their values.
        foreach ([
            'DB_PORT=3306',
            'DB_DATABASE=laravel',
            'DB_USERNAME=app',
            'DB_PASSWORD=password',
            'APP_PORT=8000',
            'VITE_PORT=5173',
        ] as $line) {
            self::assertStringContainsString($line, $env);
        }
    }

    public function testRefusesWhenTheDockerfileAlreadyExists(): void
    {
        $target = $this->makeAppDir();
        file_put_contents($target . '/Dockerfile', 'mine');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dockerfile');

        (new DockerScaffold())->apply($target, false);
    }

    public function testMissingComposerJsonThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);

        (new DockerScaffold())->apply($target, false);
    }

    /**
     * The real binary with --docker: a dry run prints the plan (with the
     * docker step) on stdout, exits 0 and writes nothing. These run the
     * real binary so stdout and stderr stay separate, where scripts can
     * read them.
     */
    public function testRealBinaryDryRunPrintsTheDockerStepAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        [$exit, $stdout, $stderr] = $this->runNew($target, ['--laravel', '--docker', '--dry-run']);

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        // The plan lands on stdout, including the requested step.
        self::assertStringContainsString('Permutation:', $stdout);
        self::assertStringContainsString('Use Docker for the local runtime', $stdout);
        // A dry run writes nothing.
        self::assertDirectoryDoesNotExist($target);
    }

    /**
     * The real binary refuses --sail and --docker together: exit 1, the
     * message on stderr, nothing written.
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
     * The structural stand-in for a YAML parse: the top-level volumes:
     * block appears exactly once at column 0 and declares app-mysql.
     */
    private function assertYamlStructureHolds(string $compose): void
    {
        self::assertSame(1, preg_match_all('/^volumes:\R/m', $compose));
        self::assertMatchesRegularExpression('/^    app-mysql:\R/m', $compose);
    }

    /**
     * A minimal app dir: a composer.json with its own script and an
     * .env.example carrying the lines the scaffold must replace.
     */
    private function makeAppDir(): string
    {
        $dir = $this->makeTempDir();
        file_put_contents(
            $dir . '/composer.json',
            <<<'JSON'
{
    "name": "app/test-app",
    "description": "A test app.",
    "require": {
        "php": "^8.3"
    },
    "scripts": {
        "test": "phpunit"
    },
    "scripts-descriptions": {
        "test": "Run the tests."
    }
}
JSON,
        );
        file_put_contents(
            $dir . '/.env.example',
            <<<ENV
APP_ENV=local
APP_NAME="Test App"
DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
SESAME_DRIVER=fake
ENV,
        );

        return $dir;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function copyDir(string $from): string
    {
        $to = $this->makeTempDir();
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;
            if (is_dir($source)) {
                mkdir($target, 0777, true);
                continue;
            }
            file_put_contents($target, (string) file_get_contents($source));
        }

        return $to;
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
