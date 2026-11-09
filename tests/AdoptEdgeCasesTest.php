<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Adopt\Adopt;
use Waynestate\Sourdough\Adopt\AdoptEntry;
use Waynestate\Sourdough\Adopt\AdoptException;
use Waynestate\Sourdough\Adopt\AdoptPlan;
use Waynestate\Sourdough\Adopt\AdoptStatus;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

/**
 * The adopt engine's edge cases (design/sourdough-adopt-spec.md, section 6),
 * called directly (AdoptCommandTest drives the command).
 *
 * F4, a wrong name; F5, a vendored Squire kit of another release; F7,
 * symlinks; F10, a big tree; then one test each for a name in another case,
 * the size shortcut, a deprecated shape and a `.sourdough/base/` left from
 * a manifest that is gone. Every fixture starts from a fresh bake that is
 * then changed, so nothing goes stale when the templates move, and the
 * Laravel shape bakes on the checked-in skeleton, so nothing here touches
 * the network. Odd loaves, every one of them, and still bread.
 */
final class AdoptEdgeCasesTest extends TestCase
{
    private const VERSION = '0.1.6';

    private const NAME = 'Adopt Demo';

    private const SYMLINK = 'a symlink: Sourdough never writes through links';

    private const YOURS = 'differs from the template: yours, never overwritten';

    private const NAME_HINT = 'differs; the template writes the app name here as "%s", and your file does not have it: if the app goes by another name, delete .sourdough/ and adopt again with --name';

    private const NAME_NOTE = '%d files differ where the template writes the app name; if the app goes by another name, delete .sourdough/ and adopt again with --name.';

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
     * F4: an app baked as "alpha" in a folder named "beta". Adopted under
     * the folder's name, each file the template writes the name into is
     * yours with the hint. Raw writes the name into two of them, and two
     * hints make the note (lead decision 2026-11-09: it took three, which
     * raw and laravel-blade never reach). Under its real name it matches
     * throughout.
     */
    public function testAWrongNameHintsAtEachFileThatCarriesIt(): void
    {
        $app = $this->bake(Permutation::Raw, 'alpha', 'beta');
        $named = $this->filesNamed(Permutation::Raw, 'alpha', 'beta');
        self::assertGreaterThanOrEqual(2, count($named), 'the raw template writes the name into two files or more: ' . implode(', ', $named));
        $this->loseManifest($app);
        $this->commitAll($app, 'baked as alpha in a folder named beta');

        $plan = $this->adopter()->plan($app, Permutation::Raw, 'beta', $this->options());

        self::assertSame($named, $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        foreach ($named as $path) {
            self::assertSame(sprintf(self::NAME_HINT, 'beta'), $this->entry($plan, $path)->detail, $path);
        }
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        self::assertSame([sprintf(self::NAME_NOTE, count($named))], $plan->notes, 'two hints make the note');
        self::assertTrue(Adopt::isNameNote($plan->notes[0]));

        $right = $this->adopter()->plan($app, Permutation::Raw, 'alpha', $this->options());
        self::assertSame(0, $right->count(AdoptStatus::Yours), $this->describe($right));
        self::assertSame(0, $right->count(AdoptStatus::Missing), $this->describe($right));
        self::assertSame([], $right->notes);
    }

    /**
     * One hint alone is no note: a single file that lacks the name is more
     * likely the app's own edit than a wrong --name.
     */
    public function testOneNameHintMakesNoNote(): void
    {
        $app = $this->bake(Permutation::Raw, 'alpha', 'beta');
        $named = $this->filesNamed(Permutation::Raw, 'alpha', 'beta');
        self::assertGreaterThanOrEqual(2, count($named));
        $this->loseManifest($app);

        // Every named file but the first takes the bytes a bake named "beta" writes.
        $beta = $this->bake(Permutation::Raw, 'beta');
        foreach (array_slice($named, 1) as $path) {
            $this->put($app, $path, $this->read($beta, $path));
        }
        $this->commitAll($app, 'all but one file renamed to beta');

        $plan = $this->adopter()->plan($app, Permutation::Raw, 'beta', $this->options());

        self::assertSame([$named[0]], $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(sprintf(self::NAME_HINT, 'beta'), $this->entry($plan, $named[0])->detail);
        self::assertSame([], $plan->notes, 'one hint makes no note');
    }

    /**
     * F4 on the Inertia shape, which writes the name into more files it
     * manages: the hints add up to the note too.
     */
    public function testNameHintsOnTheInertiaShapeAddTheNote(): void
    {
        $app = $this->bake(Permutation::LaravelInertia, 'alpha', 'beta');
        $named = $this->filesNamed(Permutation::LaravelInertia, 'alpha', 'beta');
        self::assertGreaterThanOrEqual(2, count($named), implode(', ', $named));
        $this->loseManifest($app);
        $this->commitAll($app, 'baked as alpha in a folder named beta');

        $plan = $this->adopter()->plan($app, Permutation::LaravelInertia, 'beta', $this->options());

        self::assertSame($named, $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        self::assertSame([sprintf(self::NAME_NOTE, count($named))], $plan->notes);
        self::assertSame($plan->notes, $plan->toArray(false)['notes']);

        $right = $this->adopter()->plan($app, Permutation::LaravelInertia, 'alpha', $this->options());
        self::assertSame(0, $right->count(AdoptStatus::Yours), $this->describe($right));
        self::assertSame([], $right->notes);
    }

    /**
     * F5: `.squire.json` says an older kit than the one Sourdough bakes:
     * the note says to update it, and the kit file the app changed is
     * plain yours. A newer kit gets its own note; the same kit, none.
     */
    public function testAVendoredSquireKitOfAnotherReleaseGetsANote(): void
    {
        $app = $this->bake(Permutation::Raw);
        $kitFile = $this->firstManaged($app, '.squire/');
        $this->loseManifest($app);
        $this->put($app, $kitFile, $this->read($app, $kitFile) . "# set by hand on the old kit\n");
        $this->writeSquireRecord($app, '0.1.0');
        $this->commitAll($app, 'an app on Squire kit 0.1.0');
        $ours = $this->kitVersion();

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        self::assertSame([sprintf(
            '.squire.json says Squire\'s kit 0.1.0; this Sourdough bakes kit %s. Run vendor/bin/squire update and commit: the next feed takes over each kit file that then matches.',
            $ours,
        )], $plan->notes);
        self::assertSame([$kitFile], $this->pathsWith($plan, AdoptStatus::Yours));
        self::assertSame(self::YOURS, $this->entry($plan, $kitFile)->detail);
        self::assertArrayNotHasKey('.squire.json', $plan->manifest['files'] ?? [], 'the record is the app\'s, not a template path');

        $this->writeSquireRecord($app, '99.0.0');
        self::assertSame([sprintf(
            '.squire.json says Squire\'s kit 99.0.0, newer than the kit this Sourdough bakes (%s); kit files that differ stay yours until Sourdough catches up, and the feed after that takes them over.',
            $ours,
        )], $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options())->notes);

        $this->writeSquireRecord($app, $ours);
        self::assertSame([], $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options())->notes);

        unlink($app . '/.squire.json');
        self::assertSame([], $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options())->notes, 'a kit copied by hand gets no note');
    }

    /**
     * F7: a file that is a link, and files under a folder that is a link,
     * are yours even when the link leads to the template's own bytes, and
     * still yours when it leads nowhere: feed must never add through one.
     */
    public function testSymlinksAreYoursWhereverTheyPoint(): void
    {
        $app = $this->bake(Permutation::Raw);
        $outside = $this->makeTempDir();
        $stubs = $this->pathsUnder($app, 'stubs/');
        self::assertNotEmpty($stubs, 'the raw template ships stubs/');
        $this->loseManifest($app);

        rename($app . '/.gitlab-ci.yml', $outside . '/gitlab-ci.yml');
        rename($app . '/stubs', $outside . '/stubs');
        rename($app . '/README.md', $outside . '/README.md');
        if (!@symlink($outside . '/gitlab-ci.yml', $app . '/.gitlab-ci.yml')) {
            self::markTestSkipped('symlink() is not allowed here (Windows without the privilege).');
        }
        self::assertTrue(symlink($outside . '/stubs', $app . '/stubs'));
        self::assertTrue(symlink($outside . '/README.md', $app . '/README.md'));
        $this->commitAll($app, 'links into a shared folder');

        $linked = array_merge(['.gitlab-ci.yml', 'README.md'], $stubs);
        sort($linked, SORT_STRING);

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
        self::assertSame($linked, $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        foreach ($linked as $path) {
            self::assertSame(self::SYMLINK, $this->entry($plan, $path)->detail, $path);
            self::assertSame('yours', $plan->manifest['files'][$path]['class'] ?? null, $path);
        }
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));

        // The targets go away; the links stay. Still yours, never missing.
        $this->removeDir($outside);
        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
        self::assertSame($linked, $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
    }

    /**
     * F10: thousands of files outside the template paths and a dangling
     * link in node_modules/ change nothing: adopt never walks the tree.
     */
    public function testABigTreeIsNeverWalked(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->loseManifest($app);
        for ($i = 0; $i < 2000; ++$i) {
            $this->put($app, sprintf('vendor/acme/pkg%02d/src/File%04d.php', intdiv($i, 100), $i), "<?php // crumb {$i}\n");
        }
        mkdir($app . '/node_modules', 0777, true);
        @symlink($app . '/node_modules/nowhere', $app . '/node_modules/ghost');
        $this->commitAll($app, 'a big tree');

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        $json = (string) json_encode($plan->toArray(false), JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('vendor/', $json);
        self::assertStringNotContainsString('node_modules', $json);
    }

    /**
     * A name in another case: on a case-insensitive disk, readme.md and
     * Public/ answer to README.md and public/, so they are yours, named as
     * the disk names them. On a case-sensitive disk they are other files,
     * and the template's paths are missing.
     */
    public function testANameInAnotherCaseIsYours(): void
    {
        $app = $this->bake(Permutation::Raw);
        $public = $this->pathsUnder($app, 'public/');
        self::assertNotEmpty($public);
        $this->loseManifest($app);
        $this->renameCase($app, 'README.md', 'readme.md');
        $this->renameCase($app, 'public', 'Public');

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());

        $moved = array_merge(['README.md'], $public);
        sort($moved, SORT_STRING);
        if (!$this->caseInsensitive()) {
            self::assertSame($moved, $this->pathsWith($plan, AdoptStatus::Missing), $this->describe($plan));
            self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));

            return;
        }

        self::assertSame($moved, $this->pathsWith($plan, AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
        self::assertSame('named readme.md here; the template calls it README.md', $this->entry($plan, 'README.md')->detail);
        foreach ($public as $path) {
            self::assertSame(
                sprintf('named Public/%s here; the template calls it %s', substr($path, strlen('public/')), $path),
                $this->entry($plan, $path)->detail,
            );
        }
    }

    /**
     * The size shortcut: past twice the template's size plus 1 KiB a file
     * cannot match, so it is yours without being read, and without the
     * name hint, which needs its bytes. One byte less and it is read.
     */
    public function testAFileTooBigToMatchIsYoursUnread(): void
    {
        $app = $this->bake(Permutation::Raw);
        $path = 'composer.json';
        $template = $this->read($app, $path);
        $written = str_contains($template, self::NAME) ? self::NAME : 'adopt-demo';
        self::assertStringContainsString($written, $template, 'the template writes the name or the slug here');
        $this->loseManifest($app);

        $this->put($app, $path, str_repeat('x', 2 * strlen($template) + 1024));
        $read = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
        self::assertSame(AdoptStatus::Yours, $this->entry($read, $path)->status);
        self::assertSame(sprintf(self::NAME_HINT, $written), $this->entry($read, $path)->detail, 'at the limit the file is read');

        $this->put($app, $path, str_repeat('x', 2 * strlen($template) + 1025));
        $unread = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
        self::assertSame(AdoptStatus::Yours, $this->entry($unread, $path)->status);
        self::assertSame(self::YOURS, $this->entry($unread, $path)->detail, 'past the limit it is not');

        // CRLF doubles a file of short lines at most: still well under the limit, and a match.
        $this->put($app, $path, str_replace("\n", "\r\n", $template));
        self::assertSame(AdoptStatus::Match, $this->entry($this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options()), $path)->status);
    }

    /**
     * A deprecated shape still adopts, with the catalog's notice as a note,
     * as `new` and `feed` print it.
     */
    public function testADeprecatedShapeAdoptsWithItsNotice(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->loseManifest($app);
        $support = ShapeSupport::load($this->catalogWithRawDeprecated());
        $notice = $support->notice('raw');
        self::assertNotNull($notice);

        $plan = (new Adopt($this->skeleton(), self::VERSION, $support))->plan($app, Permutation::Raw, self::NAME, $this->options());

        self::assertSame([$notice], $plan->notes);
        self::assertSame(0, $plan->count(AdoptStatus::Yours), $this->describe($plan));
        self::assertSame(0, $plan->count(AdoptStatus::Missing), $this->describe($plan));
    }

    /**
     * Base copies with no manifest are left from a record that is gone:
     * adopt refuses, in plan and again in apply.
     */
    public function testALeftoverBaseIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->loseManifest($app);
        $resolved = str_replace('\\', '/', (string) realpath($app));
        $message = sprintf('"%s/.sourdough/base/" is left from a manifest that is gone. Delete .sourdough/, commit, and run adopt again.', $resolved);

        $plan = $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
        $this->put($app, Feed::BASE_DIR . '/composer.json.base', "{}\n");

        try {
            $this->adopter()->plan($app, Permutation::Raw, self::NAME, $this->options());
            self::fail('adopt planned over a leftover base');
        } catch (AdoptException $e) {
            self::assertSame($message, $e->getMessage());
        }

        try {
            $this->adopter()->apply($plan);
            self::fail('adopt applied over a leftover base');
        } catch (AdoptException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);
    }

    // Helpers, after AdoptTest's.

    /**
     * Bake $shape the way `sourdough new --no-playbook --no-advisor` does,
     * at VERSION, into a folder named $folder, inside a fresh git
     * repository with no commits yet.
     */
    private function bake(Permutation $shape, string $name = self::NAME, string $folder = 'app'): string
    {
        $app = $this->makeTempDir() . '/' . $folder;
        $answers = $shape->flags() + ['no-playbook' => true, 'no-advisor' => true];
        (new Baker($this->skeleton()))->bake($shape, $app, $name, $answers, PlaybookMode::None, false, false, false, self::VERSION);
        $this->git($app, ['init', '-q']);

        return $app;
    }

    /**
     * The managed template paths whose bytes change with the name: those
     * that differ between a bake named $one and one named $other, sorted.
     *
     * @return list<string>
     */
    private function filesNamed(Permutation $shape, string $one, string $other): array
    {
        $a = Manifest::files(Manifest::read($this->bake($shape, $one)));
        $b = Manifest::files(Manifest::read($this->bake($shape, $other)));
        $paths = [];
        foreach ($a as $path => $info) {
            if ('managed' === $info['class'] && $info['sha256'] !== ($b[$path]['sha256'] ?? null)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function firstManaged(string $app, string $prefix): string
    {
        foreach ($this->pathsUnder($app, $prefix) as $path) {
            if ('managed' === Manifest::files(Manifest::read($app))[$path]['class']) {
                return $path;
            }
        }
        self::fail('no managed file under ' . $prefix);
    }

    /**
     * @return list<string>
     */
    private function pathsUnder(string $app, string $prefix): array
    {
        $paths = array_values(array_filter(
            array_keys(Manifest::files(Manifest::read($app))),
            static fn(string $path): bool => str_starts_with($path, $prefix),
        ));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function writeSquireRecord(string $app, string $version): void
    {
        $this->put($app, '.squire.json', (string) json_encode(['version' => $version, 'target' => 'raw', 'groups' => ['release'], 'files' => []], JSON_PRETTY_PRINT) . "\n");
    }

    private function kitVersion(): string
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/templates/squire/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['version'] ?? null);

        return $data['version'];
    }

    private function catalogWithRawDeprecated(): string
    {
        $shapes = [];
        foreach (Permutation::cases() as $permutation) {
            $shapes[$permutation->value] = ['owner' => 'Sourdough maintainers', 'status' => 'supported', 'deprecated_in' => null, 'removed_in' => null, 'replacement' => null];
        }
        $shapes['raw'] = ['owner' => 'Sourdough maintainers', 'status' => 'deprecated', 'deprecated_in' => '0.4.0', 'removed_in' => '0.5.0', 'replacement' => 'raw-vue'];
        $path = $this->makeTempDir() . '/shapes.json';
        file_put_contents($path, (string) json_encode(['schema' => 1, 'policy' => 'https://example.com/support.html', 'shapes' => $shapes], JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Rename $from to $to through a third name, so a case-only rename
     * works on every disk.
     */
    private function renameCase(string $app, string $from, string $to): void
    {
        self::assertTrue(rename($app . '/' . $from, $app . '/' . $from . '.moving'));
        self::assertTrue(rename($app . '/' . $from . '.moving', $app . '/' . $to));
    }

    private function caseInsensitive(): bool
    {
        $dir = $this->makeTempDir();
        touch($dir . '/Probe');

        return file_exists($dir . '/probe');
    }

    private function entry(AdoptPlan $plan, string $path): AdoptEntry
    {
        foreach ($plan->entries as $entry) {
            if ($path === $entry->path) {
                return $entry;
            }
        }
        self::fail('no entry for ' . $path);
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

    private function describe(AdoptPlan $plan): string
    {
        $lines = [];
        foreach ($plan->entries as $entry) {
            if (AdoptStatus::Match !== $entry->status && AdoptStatus::Seeded !== $entry->status) {
                $lines[] = $entry->status->value . ' ' . $entry->path . ': ' . $entry->detail;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, bool>
     */
    private function options(): array
    {
        return ['no-playbook' => true, 'no-advisor' => true];
    }

    private function adopter(): Adopt
    {
        return new Adopt($this->skeleton(), self::VERSION);
    }

    private function skeleton(): FixtureSkeletonSource
    {
        return new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
    }

    private function loseManifest(string $app): void
    {
        $this->removeDir($app . '/.sourdough');
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
            '-c', 'core.symlinks=true',
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

    private function makeTempDir(): string
    {
        // "-test-" keeps these apart from the engine's hex scratch dirs, which AdoptTest counts.
        $dir = sys_get_temp_dir() . '/sourdough-adopt-test-edge-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                // A link to a folder is a folder to Windows: rmdir, never a walk into its target.
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
