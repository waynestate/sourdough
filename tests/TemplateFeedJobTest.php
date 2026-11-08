<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Every shape's pipeline carries the sourdough-feed job, and the job's own
 * script lines do what its comment promises on a real bake: a fresh loaf
 * passes, a hand edit to a managed file is reported without failing, a
 * template change the app has not fed exits 1 (the warning allow_failure
 * maps), and a broken record exits 2 (a failure).
 *
 * The job's first line installs Sourdough from Packagist; here
 * SOURDOUGH_HOME is this checkout instead, so the lines after it run this
 * Sourdough. The app is baked, committed and cloned, so the job sees what
 * a CI checkout sees: tracked files only, plus the restored .cache/. The
 * Laravel shapes bake on the fixture skeleton, both here and inside the
 * job's feed (SOURDOUGH_COMPOSER is a stand-in that copies the fixture),
 * so nothing touches the network. A starter you test is a starter you trust.
 */
final class TemplateFeedJobTest extends TestCase
{
    private const JOB = 'sourdough-feed';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    /**
     * @return array<string, array{Permutation, bool, bool}>
     */
    public static function shapes(): array
    {
        return [
            'raw' => [Permutation::Raw, false, false],
            'raw-vue' => [Permutation::RawVue, false, false],
            'laravel-blade' => [Permutation::LaravelBlade, false, false],
            'laravel-blade-vue' => [Permutation::LaravelBladeVue, false, false],
            'laravel-inertia' => [Permutation::LaravelInertia, false, false],
            'laravel-blade with docker' => [Permutation::LaravelBlade, false, true],
            'laravel-inertia with sail' => [Permutation::LaravelInertia, true, false],
        ];
    }

    #[DataProvider('pipelines')]
    public function testEveryShapeRunsTheFeedJob(string $file): void
    {
        $shape = basename(dirname($file));
        $ci = Yaml::parse(self::read($file));
        self::assertIsArray($ci);
        self::assertArrayHasKey(self::JOB, $ci, "$shape: no " . self::JOB . ' job');
        $job = $ci[self::JOB];
        self::assertIsArray($job);
        self::assertIsArray($ci['composer-ci'] ?? null);

        // The same image as the shape's composer-ci job (multi-arch, so it
        // runs on the arm64 runner), through the proxy rule, on the cache.
        self::assertSame($ci['composer-ci']['image'], $job['image'], "$shape: the feed job runs on composer-ci's image");
        self::assertStringStartsWith('${IMAGE_PREFIX}/composer:', $job['image']);
        self::assertSame('.composer-cache', $job['extends']);

        // Exit 1 (a feed is due) warns; anything else that is not 0 fails.
        self::assertSame(['exit_codes' => [1]], $job['allow_failure']);

        $script = self::script($file);
        self::assertStringStartsWith('composer create-project ', $script[0]);
        self::assertStringContainsString(' waynestate/sourdough "$SOURDOUGH_HOME"', $script[0]);
        self::assertSame(1, count(preg_grep('/bin\/sourdough" manifest --check \|\| drift=\$\?$/', $script) ?: []), "$shape: runs manifest --check once");
        self::assertSame(1, count(preg_grep('/bin\/sourdough" feed --dry-run \|\| fed=\$\?$/', $script) ?: []), "$shape: runs feed --dry-run once");
    }

    /**
     * One job, word for word, in every shape, so a fix lands everywhere.
     */
    public function testTheJobIsTheSameInEveryShape(): void
    {
        $blocks = [];
        foreach (self::pipelines() as $shape => [$file]) {
            self::assertSame(1, preg_match('/\n((?:#[^\n]*\n)*' . self::JOB . ':\n(?:[ ][^\n]*\n)*)/', self::read($file), $m), "$shape: no " . self::JOB . ' block');
            $blocks[$shape] = $m[1] ?? '';
        }

        self::assertCount(5, $blocks);
        self::assertCount(1, array_unique($blocks), 'the ' . self::JOB . ' job differs between shapes');
        self::assertStringContainsString('allow_failure turns that into a', (string) reset($blocks), 'the comment explains warn versus fail');
    }

    #[DataProvider('shapes')]
    public function testAFreshBakePassesTheJob(Permutation $shape, bool $sail, bool $docker): void
    {
        $checkout = $this->checkout($this->bake($shape, $sail, $docker));

        [$code, $out] = $this->runJob($checkout);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Clean: every managed file matches the manifest.', $out);
        self::assertStringContainsString('Fresh: the app already has everything Sourdough', $out);
        self::assertStringNotContainsString('Uncommitted changes', $out, 'the restored .cache/ is ignored');
    }

    /**
     * Drift is normal (feed merges it), so the job lists the file and passes.
     */
    #[DataProvider('shapes')]
    public function testAHandEditToAManagedFileIsReportedNotFailed(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        self::assertSame('managed', Manifest::files(Manifest::read($app))['phpstan.neon']['class']);
        file_put_contents($app . '/phpstan.neon', "# tuned by hand\n", FILE_APPEND);
        $this->commitAll($app, 'hand edit');

        [$code, $out] = $this->runJob($this->checkout($app));

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('changed  phpstan.neon', $out);
        self::assertStringContainsString('Fresh: the app already has everything Sourdough', $out);
    }

    /**
     * An app an older template baked: the job exits 1, which allow_failure
     * shows as a warning instead of a red pipeline.
     */
    #[DataProvider('shapes')]
    public function testAnUnfedTemplateChangeWarns(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $old = "# an older template wrote this line\n";
        file_put_contents($app . '/phpstan.neon', $old);
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files']['phpstan.neon']);
        $manifest['files']['phpstan.neon']['sha256'] = hash('sha256', $old);
        Manifest::writeData($app, $manifest);
        $this->commitAll($app, 'baked by an older template');

        [$code, $out] = $this->runJob($this->checkout($app));

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('Clean: every managed file matches the manifest.', $out);
        self::assertMatchesRegularExpression('/^  update +phpstan\.neon /m', $out);
        self::assertStringContainsString('This app has template changes to feed. Run sourdough feed, review and commit.', $out);
    }

    public function testABrokenRecordFails(): void
    {
        $app = $this->bake(Permutation::Raw, false, false);
        unlink($app . '/.sourdough/manifest.json');
        $this->commitAll($app, 'lost the manifest');

        [$code, $out] = $this->runJob($this->checkout($app));

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('No Sourdough manifest found', $out);
    }

    public function testLeftoverConflictMarkersFail(): void
    {
        $app = $this->bake(Permutation::Raw, false, false);
        file_put_contents($app . '/phpstan.neon', "<<<<<<< app\nmine\n=======\ntheirs\n>>>>>>> sourdough 0.1.9\n", FILE_APPEND);
        $this->commitAll($app, 'committed a conflict');

        [$code, $out] = $this->runJob($this->checkout($app));

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('Finish the last feed first', $out);
    }

    // Helpers.

    /**
     * The job's script lines, as GitLab runs them.
     *
     * @return list<string>
     */
    private static function script(string $file): array
    {
        $ci = Yaml::parse(self::read($file));
        self::assertIsArray($ci);
        self::assertIsArray($ci[self::JOB] ?? null);
        $script = $ci[self::JOB]['script'] ?? null;
        self::assertIsArray($script);
        $lines = [];
        foreach ($script as $line) {
            self::assertIsString($line, 'every script line is one shell line');
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Run the job's lines after the install, in one shell the way GitLab
     * does, with this checkout as SOURDOUGH_HOME.
     *
     * @return array{int, string} the exit code and the combined output
     */
    private function runJob(string $checkout): array
    {
        $lines = self::script(dirname(__DIR__) . '/templates/raw/.gitlab-ci.yml');
        array_shift($lines);

        // The pipeline cache GitLab restores before the script starts.
        mkdir($checkout . '/.cache/composer', 0777, true);
        file_put_contents($checkout . '/.cache/composer/restored', "flour\n");

        $env = getenv();
        $env['SOURDOUGH_HOME'] = dirname(__DIR__);
        $env['SOURDOUGH_COMPOSER'] = $this->fakeComposer();

        $process = proc_open(
            ['sh', '-ec', "exec 2>&1\n" . implode("\n", $lines) . "\n"],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $checkout,
            $env,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out . $err];
    }

    /**
     * A composer stand-in for the feed's Laravel bake: create-project
     * <package> <dir> copies the skeleton fixture into <dir>.
     */
    private function fakeComposer(): string
    {
        $bin = $this->makeTempDir() . '/composer';
        $fixture = __DIR__ . '/fixtures/laravel-skeleton';
        file_put_contents($bin, "#!/bin/sh\nset -e\nmkdir -p \"\$3\"\ncp -R " . escapeshellarg($fixture) . "/. \"\$3\"\n");
        chmod($bin, 0755);

        return $bin;
    }

    /**
     * Bake $shape the way `sourdough new` does (no Playbook, no ADVISOR,
     * this Sourdough's version) into a git repository with one commit.
     */
    private function bake(Permutation $shape, bool $sail, bool $docker): string
    {
        if ('Windows' === PHP_OS_FAMILY) {
            self::markTestSkipped('The job runs in sh on a Linux runner.');
        }
        if (!(new Git())->available()) {
            self::markTestSkipped('git is not on PATH; sourdough feed stands on git.');
        }

        $answers = ['no-playbook' => true, 'no-advisor' => true];
        if (Permutation::Raw === $shape || Permutation::RawVue === $shape) {
            $answers['raw'] = true;
        } else {
            $answers['laravel'] = true;
        }
        if (Permutation::RawVue === $shape || Permutation::LaravelBladeVue === $shape) {
            $answers['vue'] = true;
        }
        if (Permutation::LaravelInertia === $shape) {
            $answers['inertia'] = true;
        }

        $app = $this->makeTempDir() . '/app';
        (new Baker(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->bake($shape, $app, 'Feed Job Demo', $answers, PlaybookMode::None, false, $sail, $docker);

        $this->git($app, ['init', '-q']);
        $this->commitAll($app, 'baked');

        return $app;
    }

    /** A CI checkout: a clone, so only what was committed is there. */
    private function checkout(string $app): string
    {
        $checkout = $this->makeTempDir() . '/checkout';
        $this->git(dirname($checkout), ['clone', '-q', $app, $checkout]);

        return $checkout;
    }

    private function commitAll(string $app, string $message): void
    {
        $this->git($app, ['add', '-A']);
        $this->git($app, ['commit', '-q', '--allow-empty', '-m', $message]);
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): void
    {
        [$code, $out, $err] = (new Git())->run(array_merge([
            '-c', 'user.name=Feed Job Test',
            '-c', 'user.email=feed-job@example.com',
            '-c', 'commit.gpgsign=false',
            '-c', 'core.autocrlf=false',
            '-c', 'init.defaultBranch=main',
        ], $args), $dir);
        self::assertSame(0, $code, 'git ' . implode(' ', $args) . ': ' . $out . $err);
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-feed-job-test-' . bin2hex(random_bytes(8));
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                @chmod($path, 0666);
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
