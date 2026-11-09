<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Command\FeedCommand;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The manifest's third class, "yours": a template file the app keeps its own
 * version of. `sourdough adopt` will record one wherever an existing app's
 * copy differs from the template's; here a fresh raw bake has one managed
 * file set to yours by hand.
 *
 * Feed never writes, merges, removes or reports a yours file, and
 * `manifest --check` skips it. The day the app's copy equals the template's,
 * feed records it and the file is managed from then on. A sourdough starter
 * that was never ours can still be fed; we just do not stir the jar.
 */
final class FeedYoursClassTest extends TestCase
{
    private const OLD_VERSION = '0.1.0';

    private const NEW_VERSION = '0.1.6';

    private const TEMPLATE_LINE = "# an older template wrote this line\n";

    private const YOURS = 'phpstan.neon';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        if (!(new Git())->available()) {
            self::markTestSkipped('git is not on PATH; sourdough feed stands on git.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testManifestFilesKeepsTheYoursClass(): void
    {
        $files = Manifest::files(['files' => [
            'a' => ['class' => 'yours', 'sha256' => 'x'],
            'b' => ['class' => 'seeded', 'sha256' => 'y'],
            'c' => ['class' => 'something-else', 'sha256' => 'z'],
            'd' => ['sha256' => 'w'],
        ]]);

        self::assertSame('yours', $files['a']['class']);
        self::assertSame('seeded', $files['b']['class']);
        self::assertSame('managed', $files['c']['class']);
        self::assertSame('managed', $files['d']['class']);
    }

    public function testManifestCheckSkipsAYoursFileEvenWhenEdited(): void
    {
        $app = $this->bake();
        $this->markYours($app, self::YOURS);
        $this->put($app, self::YOURS, "# our own phpstan setup\n");
        $this->commitAll($app, 'adopted, with our own phpstan.neon');

        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app));

        unlink($app . '/' . self::YOURS);
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app), 'a deleted yours file is not reported missing either');
    }

    public function testAYoursFileGetsNoStepWhenTheTemplateChangesIt(): void
    {
        $app = $this->bake();
        $current = $this->read($app, self::YOURS);
        // Aged: the app holds what an older template wrote, which would be
        // an update for a managed file. A yours file is never updated.
        $this->age($app, self::YOURS, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        $this->markYours($app, self::YOURS);
        $this->commitAll($app, 'adopted');
        $before = $this->read($app, self::YOURS);

        $plan = $this->feeder()->plan($app);
        self::assertSame([], $plan->steps, 'no step for a yours file');
        self::assertSame([], $plan->bases, 'no base copy for a yours file');

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('Fresh', $dry->getDisplay());
        self::assertStringNotContainsString(self::YOURS, $dry->getDisplay());

        $real = $this->feed($app);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        self::assertSame($before, $this->read($app, self::YOURS));
        self::assertSame([], (new Git())->status($app), 'feed wrote nothing');
    }

    public function testAYoursFileThatNowMatchesTheTemplateIsRecordedAndManaged(): void
    {
        $app = $this->bake();
        $current = $this->read($app, self::YOURS);
        // The manifest says an older template wrote something else; the
        // app's copy already equals what the template writes today.
        $this->rewindManifest($app, self::YOURS, self::TEMPLATE_LINE . $current);
        $this->markYours($app, self::YOURS);
        $this->commitAll($app, 'adopted, then brought level with the template');

        $plan = $this->feeder()->plan($app);
        self::assertCount(1, $plan->steps);
        self::assertSame(self::YOURS, $plan->steps[0]->path);
        self::assertSame('record', $plan->steps[0]->action->value);
        self::assertSame('your file now matches the template\'s: Sourdough manages it from here', $plan->steps[0]->detail);

        $real = $this->feed($app);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        self::assertMatchesRegularExpression('/record\s+' . preg_quote(self::YOURS, '/') . ' /', $real->getDisplay());
        self::assertSame($current, $this->read($app, self::YOURS), 'record never writes the file');

        $files = Manifest::files(Manifest::read($app));
        self::assertSame('managed', $files[self::YOURS]['class']);
        self::assertSame(hash('sha256', $current), $files[self::YOURS]['sha256']);
        self::assertDirectoryDoesNotExist($app . '/' . Feed::BASE_DIR);
    }

    public function testADeletedYoursFileGetsNoStep(): void
    {
        $app = $this->bake();
        $current = $this->read($app, self::YOURS);
        $this->age($app, self::YOURS, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        $this->markYours($app, self::YOURS);
        unlink($app . '/' . self::YOURS);
        $this->commitAll($app, 'adopted, and we never wanted phpstan.neon');

        $plan = $this->feeder()->plan($app);
        self::assertSame([], $plan->steps, 'a deleted yours file is neither kept nor added');

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringNotContainsString(self::YOURS, $dry->getDisplay());
        self::assertFileDoesNotExist($app . '/' . self::YOURS);
    }

    public function testTheYoursClassCarriesOverThroughAFeed(): void
    {
        $app = $this->bake();
        $yoursNow = $this->read($app, self::YOURS);
        $this->age($app, self::YOURS, $this->replaceLine($yoursNow, -1, self::TEMPLATE_LINE));
        $this->put($app, self::YOURS, "# our own phpstan setup\n");
        $this->markYours($app, self::YOURS);

        // A managed file the template also changed, so the feed has work to
        // do and writes a new manifest.
        $managed = $this->pickManaged($app);
        $managedNow = $this->read($app, $managed);
        $this->age($app, $managed, $this->replaceLine($managedNow, -1, self::TEMPLATE_LINE));
        $this->commitAll($app, 'adopted');

        $real = $this->feed($app);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        self::assertMatchesRegularExpression('/update\s+' . preg_quote($managed, '/') . ' /', $real->getDisplay());
        self::assertStringNotContainsString(self::YOURS, $real->getDisplay());
        self::assertSame("# our own phpstan setup\n", $this->read($app, self::YOURS));
        self::assertSame($managedNow, $this->read($app, $managed));

        $manifest = Manifest::read($app);
        self::assertSame(self::NEW_VERSION, $manifest['sourdough_version']);
        $files = Manifest::files($manifest);
        self::assertSame('yours', $files[self::YOURS]['class']);
        self::assertSame(hash('sha256', $yoursNow), $files[self::YOURS]['sha256'], 'the record moves to the template\'s new version');
        self::assertSame('managed', $files[$managed]['class']);
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app));
        self::assertFileDoesNotExist($app . '/' . Feed::BASE_DIR . '/' . self::YOURS . '.base');

        $this->commitAll($app, 'fed');
        $again = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
    }

    // Helpers, after FeedCommandTest's.

    /**
     * A raw app baked the way `sourdough new` does, recorded as
     * OLD_VERSION, inside a fresh git repository with no commits yet.
     */
    private function bake(): string
    {
        $app = $this->makeTempDir() . '/app';
        (new Baker($this->skeleton()))->bake(
            Permutation::Raw,
            $app,
            'Feed Demo',
            ['no-playbook' => true, 'no-advisor' => true, 'raw' => true],
            PlaybookMode::None,
            false,
            false,
            false,
            self::OLD_VERSION,
        );
        $this->git($app, ['init', '-q']);

        $files = Manifest::files(Manifest::read($app));
        self::assertSame('managed', $files[self::YOURS]['class'] ?? null, self::YOURS . ' is a managed file in every shape');
        self::assertGreaterThanOrEqual(4, count($this->lines($this->read($app, self::YOURS))));

        return $app;
    }

    /**
     * A second managed text file with a few lines, to give a feed real work.
     */
    private function pickManaged(string $app): string
    {
        foreach (Manifest::files(Manifest::read($app)) as $path => $info) {
            if (self::YOURS === $path || 'managed' !== $info['class'] || !str_ends_with($path, '.php')) {
                continue;
            }
            if (count($this->lines($this->read($app, $path))) >= 4) {
                return $path;
            }
        }
        self::fail('the raw bake has no second managed PHP file');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function feed(string $app, array $options = []): CommandTester
    {
        $tester = new CommandTester(new FeedCommand($this->skeleton(), null, self::NEW_VERSION));
        $tester->execute(['dir' => $app] + $options);

        return $tester;
    }

    private function feeder(): Feed
    {
        return new Feed($this->skeleton(), null, self::NEW_VERSION);
    }

    private function skeleton(): FixtureSkeletonSource
    {
        return new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
    }

    private function markYours(string $app, string $path): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files'][$path]);
        $manifest['files'][$path]['class'] = 'yours';
        Manifest::writeData($app, $manifest);
    }

    private function age(string $app, string $path, string $old): void
    {
        $this->put($app, $path, $old);
        $this->rewindManifest($app, $path, $old);
    }

    private function rewindManifest(string $app, string $path, string $old): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files'][$path]);
        $manifest['files'][$path]['sha256'] = hash('sha256', $old);
        $manifest['sourdough_version'] = self::OLD_VERSION;
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
            '-c', 'user.name=Feed Test',
            '-c', 'user.email=feed@example.com',
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
        $lines = $this->lines($content);
        array_splice($lines, $index, 1, [$line]);

        return implode('', $lines);
    }

    /**
     * @return list<string>
     */
    private function lines(string $content): array
    {
        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-yours-test-' . bin2hex(random_bytes(8));
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
