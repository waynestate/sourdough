<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Adopt\Adopt;
use Waynestate\Sourdough\Adopt\AdoptEntry;
use Waynestate\Sourdough\Adopt\AdoptException;
use Waynestate\Sourdough\Adopt\AdoptPlan;
use Waynestate\Sourdough\Adopt\AdoptStatus;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Command\FeedCommand;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Feed\FeedAction;
use Waynestate\Sourdough\Feed\FeedStep;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The adopt engine (design/sourdough-adopt-spec.md, sections 2 and 5),
 * called directly: there is no `sourdough adopt` command yet.
 *
 * Every fixture starts from a fresh bake that is then changed, so nothing
 * goes stale when the templates move: F1, a bake that lost its manifest, on
 * every shape and oven; F2, an app that edited, deleted and added files;
 * F3, a Windows checkout with CRLF everywhere. Then the adopted F2 app is
 * fed, to show feed treats each status the way section 5 says. The Laravel
 * shapes bake on the checked-in skeleton fixture, so nothing here touches
 * the network. Some starters come in a jar we did not label; this one gets
 * a label.
 */
final class AdoptTest extends TestCase
{
    private const VERSION = '0.1.6';

    private const NAME = 'Adopt Demo';

    private const TEMPLATE_LINE = "# an older template wrote this line\n";

    /** Raw template paths F2 edits, so they become yours. */
    private const EDITED = ['phpstan.neon', 'public/index.php'];

    /** Raw template paths F2 deletes (one managed, one seeded), so they are missing. */
    private const DELETED = ['.env.example', 'pint.json'];

    /** Files F2 adds of its own, which the template never ships. */
    private const OWN = ['lib/legacy.php', 'notes.txt', 'public/old.php'];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        if (!(new Git())->available()) {
            self::markTestSkipped('git is not on PATH; the adopted apps are git repositories, as adopt requires.');
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

    /**
     * F1: a baked app that lost its manifest is adopted back to the record
     * the bake wrote, and feed then finds it fresh.
     */
    #[DataProvider('shapes')]
    public function testALostManifestIsAdoptedAsItWasBaked(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $baked = Manifest::read($app);
        $this->loseManifest($app);
        $this->commitAll($app, 'baked, then the manifest was lost');

        $scratchBefore = $this->scratchDirs();
        $plan = $this->adopter()->plan($app, $shape, self::NAME, $this->options($sail, $docker));
        self::assertSame($scratchBefore, $this->scratchDirs(), 'the scratch bake is cleaned up');

        self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        self::assertSame(count(Manifest::files($baked)), $plan->count(AdoptStatus::Match) + $plan->count(AdoptStatus::Seeded));
        foreach ($plan->entries as $entry) {
            $expected = 'seeded' === Manifest::files($baked)[$entry->path]['class'] ? AdoptStatus::Seeded : AdoptStatus::Match;
            self::assertSame($expected, $entry->status, $entry->path);
        }

        $answers = $plan->manifest['answers'];
        self::assertIsArray($answers);
        self::assertTrue($answers['adopted'] ?? null, 'the manifest says the app was adopted');
        self::assertSame($this->withoutAdoption($baked), $this->withoutAdoption($plan->manifest));

        $this->adopter()->apply($plan);
        self::assertSame(['?? .sourdough/manifest.json'], (new Git())->status($app), 'adopt writes the manifest and nothing else');
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app));

        $this->commitAll($app, 'adopted');
        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('Fresh', $dry->getDisplay());
    }

    /**
     * With no options, adopt answers as plain `sourdough new` does:
     * Playbook required, ADVISOR wired.
     */
    public function testTheDefaultsAnswerAsNewDoes(): void
    {
        $app = $this->makeTempDir() . '/app';
        (new Baker($this->skeleton()))->bake(Permutation::Raw, $app, self::NAME, ['raw' => true, 'playbook' => true], PlaybookMode::Require, true, false, false, self::VERSION);
        $baked = Manifest::read($app);
        $this->loseManifest($app);

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME);

        self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        self::assertSame(['adopted' => true, 'name' => self::NAME, 'playbook' => true, 'raw' => true], $plan->manifest['answers']);
        self::assertSame($this->withoutAdoption($baked), $this->withoutAdoption($plan->manifest));
    }

    /**
     * F2: edited files are yours, deleted ones missing, and the app's own
     * files are never looked at.
     */
    public function testAnEditedAppKeepsItsEditsAndItsOwnFiles(): void
    {
        $app = $this->editedApp();
        $baked = $this->bakedFiles();

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        self::assertSame(self::EDITED, $this->pathsWith($plan, AdoptStatus::Yours));
        self::assertSame(self::DELETED, $this->pathsWith($plan, AdoptStatus::Missing));
        self::assertSame(['README.md'], $this->pathsWith($plan, AdoptStatus::Seeded));
        self::assertSame(count($baked) - 5, $plan->count(AdoptStatus::Match));

        $paths = array_map(static fn(AdoptEntry $entry): string => $entry->path, $plan->entries);
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $paths, 'entries are sorted by path');
        self::assertSame(array_keys($baked), $paths, 'every template path has an entry, and nothing else does');

        foreach ($plan->entries as $entry) {
            self::assertSame(match ($entry->status) {
                AdoptStatus::Match => 'same as the template: Sourdough keeps it current',
                AdoptStatus::Yours => 'differs from the template: yours, never overwritten',
                AdoptStatus::Seeded => 'yours, as in every Sourdough app',
                AdoptStatus::Missing => 'the next sourdough feed adds it',
            }, $entry->detail, $entry->path);
        }

        $files = $plan->manifest['files'];
        self::assertIsArray($files);
        foreach (self::EDITED as $path) {
            self::assertSame(['class' => 'yours', 'sha256' => $baked[$path]['sha256']], $files[$path], $path . ' records the template\'s hash, never the app\'s');
        }
        foreach (array_merge(self::DELETED, self::OWN) as $path) {
            self::assertArrayNotHasKey($path, $files);
        }
        $keys = array_keys($files);
        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);
        self::assertSame($sortedKeys, $keys, 'manifest files are sorted by key');
        self::assertSame(['answers', 'files', 'generated_at', 'schema', 'sourdough_version', 'template'], array_keys($plan->manifest));

        self::assertSame([], (new Git())->status($app), 'planning writes nothing');

        $this->adopter()->apply($plan);
        self::assertSame(['?? .sourdough/manifest.json'], (new Git())->status($app), 'adopt writes the manifest and nothing else');
        self::assertDirectoryDoesNotExist($app . '/' . Feed::BASE_DIR, 'no base copies');
        self::assertFileDoesNotExist($app . '/.sourdough/plan.yaml');
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app), 'yours files are never reported, missing ones are not recorded');
    }

    /**
     * F3: a checkout with CRLF endings matches the LF bytes the template
     * writes, and the manifest records the template's LF hashes.
     */
    public function testACrlfCheckoutMatchesAndRecordsTheTemplatesHashes(): void
    {
        $app = $this->bake(Permutation::Raw);
        $baked = Manifest::read($app);
        $this->loseManifest($app);

        $turned = 0;
        foreach (array_keys(Manifest::files($baked)) as $path) {
            $content = $this->read($app, $path);
            if (str_contains($content, "\0") || !str_contains($content, "\n")) {
                continue;
            }
            $this->put($app, $path, str_replace("\n", "\r\n", str_replace("\r\n", "\n", $content)));
            ++$turned;
        }
        self::assertGreaterThan(10, $turned);
        $this->commitAll($app, 'a Windows checkout');

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        self::assertSame($baked['files'], $plan->manifest['files']);
    }

    /**
     * Section 5's table, on the adopted F2 app: missing files are added,
     * matching files take updates, yours files are left alone until they
     * match, and `adopted` survives every feed.
     */
    public function testAnAdoptedAppFeedsAsTheSpecSays(): void
    {
        $app = $this->editedApp();
        $adopter = $this->adopter();
        $adopter->apply($adopter->plan($app, Permutation::Raw, self::NAME, $this->options()));
        $this->commitAll($app, 'adopted');

        // The first feed adds exactly the missing files.
        $plan = $this->feeder()->plan($app);
        self::assertSame(
            array_map(static fn(string $path): array => [$path, 'add'], self::DELETED),
            $this->steps($plan->steps),
        );
        $this->feedAndCommit($app, 'first feed');
        self::assertSame([], $this->feeder()->plan($app)->steps, 'then the app is fresh');

        // The template moves on for one matching file and one yours file.
        $match = 'src/Oracle.php';
        $yours = self::EDITED[0];
        $files = Manifest::files(Manifest::read($app));
        self::assertSame('managed', $files[$match]['class']);
        self::assertSame('yours', $files[$yours]['class']);
        $matchNow = $this->read($app, $match);
        $matchOld = $this->replaceLine($matchNow, -1, self::TEMPLATE_LINE);
        $this->put($app, $match, $matchOld);
        $this->rewindManifest($app, $match, $matchOld);
        $this->rewindManifest($app, $yours, self::TEMPLATE_LINE);
        $yoursContent = $this->read($app, $yours);
        $this->commitAll($app, 'baked by an older template');

        $plan = $this->feeder()->plan($app);
        self::assertSame([[$match, 'update']], $this->steps($plan->steps), 'the yours file gets no step');
        $this->feedAndCommit($app, 'second feed');
        self::assertSame($matchNow, $this->read($app, $match));
        self::assertSame($yoursContent, $this->read($app, $yours), 'feed never writes a yours file');
        self::assertSame('yours', Manifest::files(Manifest::read($app))[$yours]['class']);

        // The developer brings the yours file level with the template.
        $template = $this->bakedFiles();
        $this->put($app, $yours, $this->bakedContent($yours));
        $this->commitAll($app, 'take the template\'s phpstan.neon');

        $plan = $this->feeder()->plan($app);
        self::assertSame([[$yours, 'record']], $this->steps($plan->steps));
        $this->feedAndCommit($app, 'third feed');

        $manifest = Manifest::read($app);
        $files = Manifest::files($manifest);
        self::assertSame('managed', $files[$yours]['class']);
        self::assertSame($template[$yours]['sha256'], $files[$yours]['sha256']);
        self::assertIsArray($manifest['answers']);
        self::assertTrue($manifest['answers']['adopted'] ?? null, 'adopted survives every feed');
        self::assertSame([], $this->feeder()->plan($app)->steps);
    }

    public function testAnAppWithAManifestIsNotAdoptedAgain(): void
    {
        $app = $this->bake(Permutation::Raw);

        try {
            $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
            self::fail('adopt planned over an existing manifest');
        } catch (AdoptException $e) {
            self::assertStringContainsString('already has a Sourdough manifest: Sourdough made or adopted this app. Bring it up to date with sourdough feed.', $e->getMessage());
        }
    }

    public function testApplyRefusesAnAppThatGainedAManifestSinceThePlan(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->loseManifest($app);
        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        Manifest::writeData($app, ['files' => [], 'note' => 'written in between']);

        try {
            $this->adopter()->apply($plan);
            self::fail('apply wrote over a manifest');
        } catch (AdoptException $e) {
            self::assertStringContainsString('already has a Sourdough manifest', $e->getMessage());
        }
        self::assertSame(['files' => [], 'note' => 'written in between'], Manifest::read($app));
    }

    public function testAMissingDirIsRefused(): void
    {
        $this->expectException(AdoptException::class);
        $this->expectExceptionMessage('does not exist.');

        $this->adopter()->plan($this->makeTempDir() . '/nothing-here', Permutation::Raw, self::NAME);
    }

    public function testAnOvenOnARawShapeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sail and --docker are for the Laravel paths');

        $this->adopter()->plan($this->makeTempDir(), Permutation::Raw, self::NAME, ['docker' => true]);
    }

    public function testAnUnknownOptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown adopt option "php"');

        $this->adopter()->plan($this->makeTempDir(), Permutation::Raw, self::NAME, ['php' => true]);
    }

    public function testThePlanAsJson(): void
    {
        $app = $this->editedApp();
        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        $data = $plan->toArray(true);
        self::assertSame(['dir', 'dry_run', 'files', 'name', 'notes', 'shape', 'sourdough_version', 'summary'], array_keys($data));
        self::assertSame(str_replace('\\', '/', (string) realpath($app)), $data['dir']);
        self::assertTrue($data['dry_run']);
        self::assertFalse($plan->toArray(false)['dry_run']);
        self::assertSame(self::NAME, $data['name']);
        self::assertSame('raw', $data['shape']);
        self::assertSame(self::VERSION, $data['sourdough_version']);
        self::assertSame([], $data['notes']);
        self::assertSame(['match' => count($this->bakedFiles()) - 5, 'missing' => 2, 'seeded' => 1, 'yours' => 2], $data['summary']);
        self::assertIsArray($data['files']);
        self::assertContains(['path' => 'pint.json', 'status' => 'missing', 'detail' => 'the next sourdough feed adds it'], $data['files']);
        self::assertCount(count($this->bakedFiles()), $data['files'], 'every template path, match included');
    }

    // Helpers, after FeedCommandTest's.

    /**
     * Bake $shape the way `sourdough new --no-playbook --no-advisor` does,
     * at VERSION, inside a fresh git repository with no commits yet.
     */
    private function bake(Permutation $shape, bool $sail = false, bool $docker = false): string
    {
        $app = $this->makeTempDir() . '/app';
        $answers = $shape->flags() + ['no-playbook' => true, 'no-advisor' => true];
        (new Baker($this->skeleton()))->bake($shape, $app, self::NAME, $answers, PlaybookMode::None, false, $sail, $docker, self::VERSION);
        $this->git($app, ['init', '-q']);

        return $app;
    }

    /**
     * F2: a raw bake with no manifest, two template files edited, a managed
     * and a seeded file deleted, three files of its own, all committed.
     */
    private function editedApp(): string
    {
        $app = $this->bake(Permutation::Raw);
        $files = Manifest::files(Manifest::read($app));
        foreach (self::EDITED as $path) {
            self::assertSame('managed', $files[$path]['class'] ?? null, $path);
        }
        self::assertSame('managed', $files['pint.json']['class'] ?? null);
        self::assertSame('seeded', $files['.env.example']['class'] ?? null);
        self::assertSame('seeded', $files['README.md']['class'] ?? null);
        foreach (self::OWN as $path) {
            self::assertArrayNotHasKey($path, $files, $path . ' is not a template path');
        }

        $this->loseManifest($app);
        foreach (self::EDITED as $path) {
            $this->put($app, $path, "# the app's own first line\n" . $this->read($app, $path));
        }
        foreach (self::DELETED as $path) {
            unlink($app . '/' . $path);
        }
        foreach (self::OWN as $path) {
            $this->put($app, $path, "<?php // the app's own, older than any template\n");
        }
        $this->commitAll($app, 'an app Sourdough did not make');

        return $app;
    }

    /**
     * @return array<string, bool>
     */
    private function options(bool $sail = false, bool $docker = false): array
    {
        return array_filter(['no-playbook' => true, 'no-advisor' => true, 'sail' => $sail, 'docker' => $docker]);
    }

    /**
     * The files map of a fresh raw bake with the test's answers.
     *
     * @return array<string, array{class: string, sha256: string}>
     */
    private function bakedFiles(): array
    {
        $files = Manifest::files(Manifest::read($this->freshRaw()));
        ksort($files, SORT_STRING);

        return $files;
    }

    private function bakedContent(string $path): string
    {
        return $this->read($this->freshRaw(), $path);
    }

    private function freshRaw(): string
    {
        $dir = $this->makeTempDir() . '/fresh';
        (new Baker($this->skeleton()))->bake(Permutation::Raw, $dir, self::NAME, Permutation::Raw->flags() + ['no-playbook' => true, 'no-advisor' => true], PlaybookMode::None, false, false, false, self::VERSION);

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function pathsWith(AdoptPlan $plan, AdoptStatus $status): array
    {
        $paths = [];
        foreach ($plan->entries as $entry) {
            if ($status === $entry->status) {
                $paths[] = $entry->path;
            }
        }

        return $paths;
    }

    /**
     * @param list<FeedStep> $steps
     *
     * @return list<array{string, string}>
     */
    private function steps(array $steps): array
    {
        return array_map(static fn(FeedStep $step): array => [$step->path, $step->action->value], $steps);
    }

    private function describe(AdoptPlan $plan): string
    {
        $lines = [];
        foreach ($plan->entries as $entry) {
            if (AdoptStatus::Match !== $entry->status && AdoptStatus::Seeded !== $entry->status) {
                $lines[] = $entry->status->value . ' ' . $entry->path;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A manifest less what differs between a bake and an adoption of it.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function withoutAdoption(array $manifest): array
    {
        unset($manifest['generated_at']);
        self::assertIsArray($manifest['answers']);
        unset($manifest['answers']['adopted']);

        return $manifest;
    }

    private function feedAndCommit(string $app, string $message): void
    {
        $feeder = $this->feeder();
        $feeder->assertReady($app);
        $plan = $feeder->plan($app);
        self::assertSame(0, $plan->count(FeedAction::Conflict));
        $feeder->apply($plan);
        $this->commitAll($app, $message);
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

    private function adopter(): Adopt
    {
        return new Adopt($this->skeleton(), self::VERSION);
    }

    private function feeder(): Feed
    {
        return new Feed($this->skeleton(), null, self::VERSION);
    }

    private function skeleton(): FixtureSkeletonSource
    {
        return new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
    }

    private function loseManifest(string $app): void
    {
        $this->removeDir($app . '/.sourdough');
    }

    private function rewindManifest(string $app, string $path, string $old): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files'][$path]);
        $manifest['files'][$path]['sha256'] = hash('sha256', $old);
        Manifest::writeData($app, $manifest);
    }

    /**
     * @return list<string>
     */
    private function scratchDirs(): array
    {
        // The engine's scratch dirs: 16 hex digits, never this test's "-test-" dirs.
        return glob(sys_get_temp_dir() . '/sourdough-adopt-[0-9a-f]*') ?: [];
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
        self::assertGreaterThanOrEqual(4, count($lines));
        array_splice($lines, $index, 1, [$line]);

        return implode('', $lines);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-adopt-test-' . bin2hex(random_bytes(8));
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
