<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * `sourdough feed` end to end, on every shape: an app baked at an "older
 * template" (a managed file and its manifest hash set back to what an
 * earlier Sourdough wrote), one with local edits, one with a conflict.
 *
 * The older template is made by aging a fresh bake: the file's content and
 * its manifest hash are rewound together, exactly what an app baked by an
 * earlier release looks like, without keeping old releases around. The
 * Laravel shapes bake on the checked-in skeleton fixture, so nothing here
 * touches the network. Every app is a real git repository, because feed
 * stands on git.
 */
final class FeedCommandTest extends TestCase
{
    private const OLD_VERSION = '0.1.0';

    private const NEW_VERSION = '0.1.6';

    private const TEMPLATE_LINE = "# an older template wrote this line\n";

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

    #[DataProvider('shapes')]
    public function testAFreshAppHasNothingToFeed(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $this->commitAll($app, 'baked');

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('Fresh', $dry->getDisplay());

        $real = $this->feed($app);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        self::assertSame([], (new Git())->status($app), 'a fresh feed writes nothing');
    }

    #[DataProvider('shapes')]
    public function testAnUntouchedFileTakesTheTemplateUpdate(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->age($app, $file, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        $this->commitAll($app, 'baked by an older Sourdough');

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(1, $dry->getStatusCode(), $dry->getDisplay());
        self::assertMatchesRegularExpression('/update\s+' . preg_quote($file, '/') . ' /', $dry->getDisplay());
        self::assertStringContainsString('dry run: nothing written', $dry->getDisplay());
        self::assertSame([], (new Git())->status($app), 'a dry run writes nothing');

        $real = $this->feed($app);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        self::assertSame($current, $this->read($app, $file));

        $manifest = Manifest::read($app);
        self::assertSame(self::NEW_VERSION, $manifest['sourdough_version']);
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app));
        self::assertDirectoryDoesNotExist($app . '/' . Feed::BASE_DIR, 'nothing changed by the app, so no base copies');

        $this->commitAll($app, 'fed');
        $again = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
    }

    #[DataProvider('shapes')]
    public function testLocalEditsAreMergedWithTheTemplateUpdate(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->age($app, $file, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        $this->commitAll($app, 'baked by an older Sourdough');

        // The app's own edit, far from the line the template changes.
        $this->put($app, $file, "# the app's own first line\n" . $this->read($app, $file));
        $this->commitAll($app, 'our edit');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/merge\s+' . preg_quote($file, '/') . ' /', $tester->getDisplay());

        self::assertSame("# the app's own first line\n" . $current, $this->read($app, $file));
        self::assertSame($current, $this->read($app, Feed::BASE_DIR . '/' . $file . '.base'), 'the template\'s version is kept for the next feed');
        self::assertSame(self::NEW_VERSION, Manifest::read($app)['sourdough_version']);
    }

    #[DataProvider('shapes')]
    public function testAConflictIsMarkedNeverOverwritten(Permutation $shape, bool $sail, bool $docker): void
    {
        $app = $this->bake($shape, $sail, $docker);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->age($app, $file, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        $this->commitAll($app, 'baked by an older Sourdough');

        // The app changed the very line the template changes.
        $this->put($app, $file, $this->replaceLine($this->read($app, $file), -1, "# the app's own last line\n"));
        $this->commitAll($app, 'our edit');

        $tester = $this->feed($app);
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/conflict\s+' . preg_quote($file, '/') . ' \(1 conflicting hunk/', $tester->getDisplay());
        self::assertStringContainsString('git diff --check', $tester->getDisplay());

        $merged = $this->read($app, $file);
        self::assertStringContainsString("<<<<<<< app\n# the app's own last line\n=======\n", $merged);
        self::assertStringContainsString('>>>>>>> sourdough ' . self::NEW_VERSION . "\n", $merged);
        self::assertSame(self::NEW_VERSION, Manifest::read($app)['sourdough_version'], 'the manifest moves to the new base');

        // Until the markers are gone, the next feed refuses.
        $this->commitAll($app, 'oops, committed the markers');
        $next = $this->feed($app);
        self::assertSame(2, $next->getStatusCode(), $next->getDisplay());
        self::assertStringContainsString('Finish the last feed first', $next->getDisplay());
        self::assertStringContainsString($file, $next->getDisplay());
    }

    public function testASecondFeedMergesFromTheKeptBaseCopy(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->commitAll($app, 'baked');

        // A world where an earlier feed brought the app to an older
        // template (old) and kept old in .sourdough/base/, while the app's
        // own copy carries its edit. old itself never reached git history.
        $old = $this->replaceLine($current, -1, self::TEMPLATE_LINE);
        $this->put($app, $file, "# the app's own first line\n" . $old);
        $this->put($app, Feed::BASE_DIR . '/' . $file . '.base', $old);
        $this->rewindManifest($app, $file, $old);
        $this->commitAll($app, 'fed earlier, with our edit');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/merge\s+' . preg_quote($file, '/') . ' /', $tester->getDisplay());
        self::assertSame("# the app's own first line\n" . $current, $this->read($app, $file));
        self::assertSame($current, $this->read($app, Feed::BASE_DIR . '/' . $file . '.base'));
    }

    public function testWithNoBaseBothVersionsAreMarked(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $old = $this->replaceLine($current, -1, self::TEMPLATE_LINE);
        $this->age($app, $file, $old);

        // Edited before the first commit: the base never reached history.
        $this->put($app, $file, "# the app's own first line\n" . $old);
        $this->commitAll($app, 'baked and edited');

        $tester = $this->feed($app);
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('no base version to merge from', $tester->getDisplay());

        $lines = $this->lines($current);
        $expected = "<<<<<<< app\n# the app's own first line\n=======\n>>>>>>> sourdough " . self::NEW_VERSION . "\n"
            . implode('', array_slice($lines, 0, -1))
            . "<<<<<<< app\n" . self::TEMPLATE_LINE . "=======\n" . $this->withNewline((string) end($lines)) . '>>>>>>> sourdough ' . self::NEW_VERSION . "\n";
        self::assertSame($expected, $this->read($app, $file), 'only the lines that differ are marked; the rest stands once');
    }

    public function testFilesTheTemplateAddsAreAdded(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        unlink($app . '/' . $file);
        $this->forget($app, $file);
        $this->commitAll($app, 'an older Sourdough had no such file');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/add\s+' . preg_quote($file, '/') . ' \(new in the template\)/', $tester->getDisplay());
        self::assertSame($current, $this->read($app, $file));
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($app));
    }

    public function testANewTemplateFileInTheWayOfTheAppsOwnIsMarked(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $this->put($app, $file, "the app's own file\n");
        $this->forget($app, $file);
        $this->commitAll($app, 'our file');

        $tester = $this->feed($app);
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('you already have a file here', $tester->getDisplay());
        self::assertStringStartsWith("<<<<<<< app\nthe app's own file\n=======\n", $this->read($app, $file));
    }

    public function testFilesTheTemplateDroppedAreRemovedOrKept(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->put($app, 'src/Stale/Recipe.php', "<?php\n// an older template shipped this\n");
        $this->put($app, 'src/Edited.php', "<?php\n// an older template shipped this\n");
        $this->remember($app, 'src/Stale/Recipe.php', "<?php\n// an older template shipped this\n");
        $this->remember($app, 'src/Edited.php', "<?php\n// an older template shipped this\n");
        $this->put($app, 'src/Edited.php', "<?php\n// and we made it ours\n");
        $this->commitAll($app, 'older loaf');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('#remove\s+src/Stale/Recipe.php #', $tester->getDisplay());
        self::assertMatchesRegularExpression('#keep\s+src/Edited.php \(the template dropped it, but you changed it#', $tester->getDisplay());

        self::assertFileDoesNotExist($app . '/src/Stale/Recipe.php');
        self::assertDirectoryDoesNotExist($app . '/src/Stale', 'an emptied folder goes too');
        self::assertSame("<?php\n// and we made it ours\n", $this->read($app, 'src/Edited.php'));

        $files = Manifest::files(Manifest::read($app));
        self::assertArrayNotHasKey('src/Stale/Recipe.php', $files);
        self::assertArrayNotHasKey('src/Edited.php', $files, 'a kept file is the app\'s now');
    }

    public function testADeletedManagedFileStaysDeleted(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->age($app, $file, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        unlink($app . '/' . $file);
        $this->commitAll($app, 'we deleted it');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/keep\s+' . preg_quote($file, '/') . ' \(you deleted it/', $tester->getDisplay());
        self::assertFileDoesNotExist($app . '/' . $file);
        self::assertSame(hash('sha256', $current), Manifest::files(Manifest::read($app))[$file]['sha256']);
    }

    public function testSeededFilesAreNeverTouched(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $this->age($app, $file, $this->replaceLine($this->read($app, $file), -1, self::TEMPLATE_LINE));
        $this->put($app, 'README.md', "# Our app\n");
        $this->age($app, 'README.md', "# What an older template seeded\n");
        $this->put($app, 'README.md', "# Our app\n");
        $this->put($app, '.env.example', "OURS=1\n");
        $this->commitAll($app, 'ours');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('README.md', $tester->getDisplay());
        self::assertSame("# Our app\n", $this->read($app, 'README.md'));
        self::assertSame("OURS=1\n", $this->read($app, '.env.example'));
        self::assertSame('seeded', Manifest::files(Manifest::read($app))['README.md']['class']);
    }

    public function testAMissingSeededFileIsSeededAgainOnlyWhenTheTemplateAddsIt(): void
    {
        $app = $this->bake(Permutation::Raw);
        $readme = $this->read($app, 'README.md');
        unlink($app . '/README.md');
        $this->forget($app, 'README.md');
        $this->commitAll($app, 'an older Sourdough seeded no README');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/add\s+README.md \(new seeded file/', $tester->getDisplay());
        self::assertSame($readme, $this->read($app, 'README.md'));
    }

    public function testLineEndingsOfACrlfCheckoutAreKept(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $current = $this->read($app, $file);
        $this->age($app, $file, $this->replaceLine($current, -1, self::TEMPLATE_LINE));
        // A Windows checkout: same file, CRLF endings; the manifest hashed LF.
        $this->put($app, $file, str_replace("\n", "\r\n", $this->read($app, $file)));
        $this->commitAll($app, 'crlf checkout');

        $tester = $this->feed($app);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/update\s+' . preg_quote($file, '/') . ' /', $tester->getDisplay());
        self::assertSame(str_replace("\n", "\r\n", $current), $this->read($app, $file));
    }

    public function testJsonPrintsThePlanForMachines(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $this->age($app, $file, $this->replaceLine($this->read($app, $file), -1, self::TEMPLATE_LINE));
        $this->commitAll($app, 'older');

        $tester = $this->feed($app, ['--dry-run' => true, '--json' => true]);
        self::assertSame(1, $tester->getStatusCode());

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('raw', $data['template']);
        self::assertSame(self::OLD_VERSION, $data['from']);
        self::assertSame(self::NEW_VERSION, $data['to']);
        self::assertTrue($data['dry_run']);
        self::assertFalse($data['fresh']);
        self::assertSame([['path' => $file, 'action' => 'update', 'detail' => 'you had not changed it']], $data['files']);
        self::assertSame(1, $data['summary']['update']);
        self::assertSame(1, $data['exit']);
    }

    public function testADirtyTreeIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->commitAll($app, 'baked');
        $this->put($app, 'notes.txt', "untracked\n");

        $tester = $this->feed($app);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Commit or stash your changes first', $tester->getDisplay());
        self::assertStringContainsString('notes.txt', $tester->getDisplay());

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), 'a dry run still plans');
        self::assertStringContainsString('note: Uncommitted changes', $dry->getDisplay());
    }

    public function testAnAppOutsideGitIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw, git: false);

        $tester = $this->feed($app);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('only runs inside a git repository', $tester->getDisplay());

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('note: Not inside a git repository', $dry->getDisplay());
    }

    public function testNoManifestIsAToolError(): void
    {
        $dir = $this->makeTempDir();
        $this->git($dir, ['init', '-q']);

        $tester = $this->feed($dir, ['--dry-run' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('No Sourdough manifest', $tester->getDisplay());
        self::assertStringContainsString('sourdough adopt', $tester->getDisplay());
    }

    public function testAManifestFromANewerSourdoughIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw);
        $manifest = Manifest::read($app);
        $manifest['sourdough_version'] = '0.9.0';
        Manifest::writeData($app, $manifest);
        $this->commitAll($app, 'from the future');

        $tester = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('newer than this one', $tester->getDisplay());
    }

    public function testAnUnsafeManifestPathIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw);
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        $manifest['files']['../outside.txt'] = ['class' => 'managed', 'sha256' => hash('sha256', 'x')];
        Manifest::writeData($app, $manifest);
        $this->commitAll($app, 'tampered');

        $tester = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('unsafe path', $tester->getDisplay());
    }

    public function testALeftoverSidecarFromTheLastFeedIsRefused(): void
    {
        $app = $this->bake(Permutation::Raw);
        $file = $this->pickFile($app);
        $this->put($app, $file . Feed::SIDECAR_SUFFIX, "the template's copy\n");
        $this->commitAll($app, 'left it lying around');

        $tester = $this->feed($app);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString($file . Feed::SIDECAR_SUFFIX, $tester->getDisplay());
    }

    /**
     * Apps that recorded no Playbook answer keep the wiring they were baked
     * with: 0.1.3 to 0.1.5 suggested Playbook, 0.1.0 to 0.1.2 had none.
     * Feed never turns the private requirement on.
     *
     * @return array<string, array{string, PlaybookMode}>
     */
    public static function playbookEras(): array
    {
        return [
            'suggested (0.1.3 to 0.1.5)' => ['0.1.4', PlaybookMode::Suggest],
            'none yet (0.1.0 to 0.1.2)' => ['0.1.2', PlaybookMode::None],
        ];
    }

    #[DataProvider('playbookEras')]
    public function testAnAppWithNoPlaybookAnswerKeepsItsEra(string $version, PlaybookMode $mode): void
    {
        $app = $this->makeTempDir() . '/app';
        (new Baker())->bake(Permutation::Raw, $app, 'Feed Demo', ['raw' => true, 'no-advisor' => true], $mode, false, false, false, $version);
        $this->git($app, ['init', '-q']);
        $this->commitAll($app, 'baked at ' . $version);

        $tester = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Fresh', $tester->getDisplay());
    }

    public function testMatchesForgivesLineEndingsOnly(): void
    {
        $lf = "one\ntwo\n";
        self::assertTrue(Feed::matches($lf, hash('sha256', $lf)));
        self::assertTrue(Feed::matches("one\r\ntwo\r\n", hash('sha256', $lf)));
        self::assertTrue(Feed::matches($lf, hash('sha256', "one\r\ntwo\r\n")));
        self::assertFalse(Feed::matches("one\ntwo!\n", hash('sha256', $lf)));
    }

    public function testVersionOrderIgnoresDevBuilds(): void
    {
        self::assertTrue(Feed::isNewer('0.2.0', '0.1.9'));
        self::assertTrue(Feed::isNewer('v0.1.10', '0.1.9'));
        self::assertFalse(Feed::isNewer('0.1.5', '0.1.6'));
        self::assertFalse(Feed::isNewer('dev', '0.1.6'));
        self::assertFalse(Feed::isNewer('0.1.6', 'dev'));
    }

    public function testSafePathRefusesEscapes(): void
    {
        self::assertTrue(Feed::safePath('src/App.php'));
        self::assertTrue(Feed::safePath('.squire/vhost.conf'));
        self::assertFalse(Feed::safePath('../x'));
        self::assertFalse(Feed::safePath('src/../../x'));
        self::assertFalse(Feed::safePath('/etc/passwd'));
        self::assertFalse(Feed::safePath('C:/x'));
        self::assertFalse(Feed::safePath('src\\x'));
        self::assertFalse(Feed::safePath('.git/config'));
        self::assertFalse(Feed::safePath('.sourdough/manifest.json'));
    }

    // Helpers.

    /**
     * Bake $shape the way `sourdough new` does, recorded as OLD_VERSION,
     * inside a fresh git repository with no commits yet.
     */
    private function bake(Permutation $shape, bool $sail = false, bool $docker = false, bool $git = true): string
    {
        $root = $this->makeTempDir();
        $app = $root . '/app';

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

        (new Baker($this->skeleton()))->bake($shape, $app, 'Feed Demo', $answers, PlaybookMode::None, false, $sail, $docker, self::OLD_VERSION);

        if ($git) {
            $this->git($app, ['init', '-q']);
        }

        return $app;
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

    private function skeleton(): FixtureSkeletonSource
    {
        return new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
    }

    /**
     * A managed file with enough lines to change its first and last lines
     * independently. phpstan.neon is in every shape.
     */
    private function pickFile(string $app): string
    {
        $files = Manifest::files(Manifest::read($app));
        self::assertArrayHasKey('phpstan.neon', $files);
        self::assertSame('managed', $files['phpstan.neon']['class']);
        self::assertGreaterThanOrEqual(4, count($this->lines($this->read($app, 'phpstan.neon'))));

        return 'phpstan.neon';
    }

    /**
     * Make the app look baked by an older template: $path holds $old, and
     * the manifest says the template wrote $old, at OLD_VERSION.
     */
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

    /**
     * Drop $path from the manifest, as if the older template never wrote it.
     */
    private function forget(string $app, string $path): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        unset($manifest['files'][$path]);
        Manifest::writeData($app, $manifest);
    }

    /**
     * Add $path to the manifest as a managed file the older template wrote.
     */
    private function remember(string $app, string $path, string $content): void
    {
        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        $manifest['files'][$path] = ['class' => 'managed', 'sha256' => hash('sha256', $content)];
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

    private function withNewline(string $text): string
    {
        return str_ends_with($text, "\n") ? $text : $text . "\n";
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-feed-test-' . bin2hex(random_bytes(8));
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
