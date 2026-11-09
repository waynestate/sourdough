<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Command\AdoptCommand;
use Waynestate\Sourdough\Command\FeedCommand;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The apps AdoptCommandTest and AdoptFeedTest adopt, and the commands they
 * run on them. Every app is a real git repository (the tests skip without
 * git), and the Laravel shapes bake on the checked-in skeleton, so nothing
 * touches the network.
 */
trait AdoptsApps
{
    private const VERSION = '0.1.6';

    private const NAME = 'Adopt Demo';

    /** Raw template paths F2 edits, so they become yours. */
    private const EDITED = ['phpstan.neon', 'public/index.php'];

    /** Raw template paths F2 deletes (one seeded, one managed), so they are missing. */
    private const DELETED = ['.env.example', 'pint.json'];

    /** Files F2 adds of its own, which the template never ships. */
    private const OWN = ['lib/legacy.php', 'notes.txt', 'public/old.php'];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        if (!(new Git())->available()) {
            self::markTestSkipped('git is not on PATH; sourdough adopt only runs in a git repository.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Bake $shape the way `sourdough new --no-playbook --no-advisor` does,
     * at VERSION, into a folder named $folder, inside a fresh git
     * repository with no commits yet.
     */
    private function bake(Permutation $shape, bool $sail = false, bool $docker = false, string $folder = 'app'): string
    {
        $app = $this->makeTempDir() . '/' . $folder;
        $answers = $shape->flags() + ['no-playbook' => true, 'no-advisor' => true];
        (new Baker($this->skeleton()))->bake($shape, $app, self::NAME, $answers, PlaybookMode::None, false, $sail, $docker, self::VERSION);
        $this->git($app, ['init', '-q']);

        return $app;
    }

    /**
     * F2: a raw bake with no manifest, two template files edited, a managed
     * and a seeded file deleted, three files of its own, all committed.
     * Returns the app, and the template's bytes of each edited file.
     *
     * @return array{string, array<string, string>}
     */
    private function editedApp(): array
    {
        $app = $this->bake(Permutation::Raw);
        $files = Manifest::files(Manifest::read($app));
        foreach (self::EDITED as $path) {
            self::assertSame('managed', $files[$path]['class'] ?? null, $path);
        }
        self::assertSame('managed', $files['pint.json']['class'] ?? null);
        self::assertSame('seeded', $files['.env.example']['class'] ?? null);

        $this->loseManifest($app);
        $template = [];
        foreach (self::EDITED as $path) {
            $template[$path] = $this->read($app, $path);
            $this->put($app, $path, "# the app's own first line\n" . $template[$path]);
        }
        foreach (self::DELETED as $path) {
            unlink($app . '/' . $path);
        }
        foreach (self::OWN as $path) {
            $this->put($app, $path, "<?php // the app's own, older than any template\n");
        }
        $this->commitAll($app, 'an app Sourdough did not make');

        return [$app, $template];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function adopt(string $app, array $options = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL, ?Git $git = null, ?SkeletonSource $skeleton = null): CommandTester
    {
        $tester = new CommandTester(new AdoptCommand($skeleton ?? $this->skeleton(), $git, self::VERSION));
        $tester->execute(['dir' => $app] + $options, ['verbosity' => $verbosity]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function feed(string $app, array $options = []): CommandTester
    {
        $tester = new CommandTester(new FeedCommand($this->skeleton(), null, self::VERSION));
        $tester->execute(['dir' => $app] + $options);

        return $tester;
    }

    private function skeleton(): FixtureSkeletonSource
    {
        return new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
    }

    private function loseManifest(string $app): void
    {
        $this->removeDir($app . '/.sourdough');
    }

    /**
     * Make $path look written by an older template: the manifest records
     * $old's hash for it, at an older Sourdough.
     */
    private function rewindManifest(string $app, string $path, string $old): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files'][$path]);
        $manifest['files'][$path]['sha256'] = hash('sha256', $old);
        $manifest['sourdough_version'] = '0.1.0';
        Manifest::writeData($app, $manifest);
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
            '-c', 'user.name=Adopt Test',
            '-c', 'user.email=adopt@example.com',
            '-c', 'commit.gpgsign=false',
            '-c', 'core.autocrlf=false',
            '-c', 'init.defaultBranch=main',
        ], $args), $dir);
        self::assertSame(0, $code, 'git ' . implode(' ', $args) . ': ' . $out . $err);
    }

    private function read(string $app, string $path): string
    {
        $content = file_get_contents($app . '/' . $path);
        self::assertIsString($content);

        return $content;
    }

    private function put(string $app, string $path, string $content): void
    {
        $file = $app . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    /**
     * $content with line $index (negative counts from the end) replaced.
     */
    private function replaceLine(string $content, int $index, string $line): string
    {
        $lines = preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        array_splice($lines, $index, 1, [$line]);

        return implode('', $lines);
    }

    private function resolved(string $dir): string
    {
        return str_replace('\\', '/', (string) realpath($dir));
    }

    private function makeTempDir(): string
    {
        // "-test-" keeps these apart from the engine's hex scratch dirs, which AdoptTest counts.
        $dir = sys_get_temp_dir() . '/sourdough-adopt-test-cmd-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                $this->copyTree($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        @chmod($dir, 0777);
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                if (!@unlink($path)) {
                    @rmdir($path);
                }
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @chmod($path, 0666);
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
