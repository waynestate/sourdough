<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;

/**
 * `sourdough adopt` end to end (design/sourdough-adopt-spec.md, sections 1,
 * 3 and 4): F6, a small legacy raw app checked in as a fixture; F8, every
 * refusal with its exit code and message, and the notes a dry run gives
 * instead; F11, the JSON report and a dry run that writes nothing; and the
 * text report's line shapes, folding and -v.
 *
 * The engine's own cases are AdoptTest's and AdoptEdgeCasesTest's; feeding
 * an adopted app is AdoptFeedTest's. We knead the command, not the dough.
 */
final class AdoptCommandTest extends TestCase
{
    use AdoptsApps;

    private const FIXTURE = __DIR__ . '/fixtures/adopt/legacy-raw';

    private const SHAPES = 'raw, raw-vue, laravel-blade, laravel-blade-vue or laravel-inertia';

    /** One report line: two spaces, the status padded to 7, two spaces, the path column, two spaces or more, the detail. */
    private const LINE = '/^  (match  |yours  |seeded |missing)  (\S(?:.*?\S)?) {2,}(\S.*)$/';

    /**
     * F6: an older raw app that shares nothing with the template. Its
     * template paths are yours or seeded, everything else is missing, and
     * the `.squire/` kit it never had folds into one line.
     */
    public function testALegacyRawAppIsAdopted(): void
    {
        $app = $this->legacyApp();
        $templatePaths = $this->rawTemplatePaths();

        $tester = $this->adopt($app, $this->raw());
        $display = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode(), $display);

        $manifest = Manifest::read($app);
        self::assertSame(['README.md' => 'seeded', 'composer.json' => 'yours', 'public/.htaccess' => 'yours', 'public/index.php' => 'yours'], array_map(
            static fn(array $info): string => $info['class'],
            Manifest::files($manifest),
        ));
        self::assertSame('raw', $manifest['template']);
        $answers = $manifest['answers'];
        self::assertIsArray($answers);
        ksort($answers);
        self::assertSame(['adopted' => true, 'name' => 'parking-permits', 'no-advisor' => true, 'no-playbook' => true, 'raw' => true], $answers);
        self::assertSame(['?? .sourdough/manifest.json'], (new Git())->status($app), 'adopt writes the manifest and nothing else');

        $missing = count($templatePaths) - 4;
        $lines = explode("\n", rtrim(str_replace("\r", '', $display)));
        self::assertSame(sprintf('Adopting "parking-permits" as a raw app (%s) with Sourdough %s.', Permutation::Raw->label(), self::VERSION), $lines[0]);
        self::assertSame(sprintf('Compared your app with a fresh raw bake: %d template files.', count($templatePaths)), $lines[1]);
        self::assertSame('', $lines[2]);

        $rows = $this->rows($display);
        self::assertSame(['yours', 'composer.json', 'differs from the template: yours, never overwritten'], $rows[0]);
        self::assertSame(['yours', 'public/.htaccess'], array_slice($rows[1], 0, 2));
        self::assertSame(['yours', 'public/index.php'], array_slice($rows[2], 0, 2));
        self::assertStringStartsWith('differs', $rows[2][2]);
        self::assertSame(['seeded', 'README.md', 'yours, as in every Sourdough app'], $rows[3]);

        // The kit's folder, every file of it missing, is one line.
        $kit = array_values(array_filter($templatePaths, static fn(string $p): bool => str_starts_with($p, '.squire/')));
        self::assertGreaterThan(1, count($kit));
        self::assertContains(['missing', sprintf('.squire/ (%d files)', count($kit)), 'the next sourdough feed adds them'], $rows);
        self::assertDoesNotMatchRegularExpression('#^  missing  \.squire/\S#m', $display);
        foreach ($rows as [$status, $path]) {
            self::assertNotSame('match', $status, 'match lines need -v');
            self::assertStringNotContainsString('public/ (', $path, 'public/ holds yours files, so it never folds');
        }

        self::assertSame(sprintf('Adopted: 0 match, 3 yours, 1 seeded, %d missing. Wrote .sourdough/manifest.json.', $missing), $lines[count($lines) - 4]);
        self::assertSame('Files the template does not ship are not recorded: Sourdough never reads or touches them.', $lines[count($lines) - 3]);
        self::assertSame(sprintf('Next: git add .sourdough && git commit -m "Adopt with Sourdough %s"', self::VERSION), $lines[count($lines) - 2]);
        self::assertSame(sprintf('      then sourdough feed --dry-run lists the %d files the first feed adds.', $missing), $lines[count($lines) - 1]);
    }

    /**
     * With -v every file is listed, folders unfolded, matches included.
     */
    public function testVerboseListsEveryFile(): void
    {
        [$app] = $this->editedApp();
        $templatePaths = $this->rawTemplatePaths();

        $quiet = $this->rows($this->adopt($app, $this->f2() + ['--dry-run' => true])->getDisplay());
        $loud = $this->rows($this->adopt($app, $this->f2() + ['--dry-run' => true], OutputInterface::VERBOSITY_VERBOSE)->getDisplay());

        self::assertCount(count($templatePaths), $loud, 'one line per template path');
        $paths = array_map(static fn(array $row): string => $row[1], $loud);
        sort($paths, SORT_STRING);
        self::assertSame($templatePaths, $paths);
        self::assertContains(['match', 'composer.json', 'same as the template: Sourdough keeps it current'], $loud);
        self::assertSame(['yours', 'seeded', 'missing', 'match'], array_values(array_unique(array_map(static fn(array $row): string => $row[0], $loud))), 'yours first, matches last');

        self::assertSame([
            ['yours', 'phpstan.neon', 'differs from the template: yours, never overwritten'],
            ['yours', 'public/index.php', 'differs from the template: yours, never overwritten'],
            ['seeded', 'README.md', 'yours, as in every Sourdough app'],
            ['missing', '.env.example', 'the next sourdough feed adds it'],
            ['missing', 'pint.json', 'the next sourdough feed adds it'],
        ], $quiet);
    }

    /**
     * The sample output's shapes: every file line is two spaces, a status
     * padded to seven, two spaces, a path column as wide as the longest
     * path, two spaces and the detail; the dry run's first, summary and
     * last lines say it wrote nothing, and it did not.
     */
    public function testTheReportLinesHaveTheSamplesShape(): void
    {
        [$app] = $this->editedApp();

        $tester = $this->adopt($app, $this->f2() + ['--dry-run' => true], OutputInterface::VERBOSITY_VERBOSE);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], (new Git())->status($app), 'a dry run writes nothing');
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);

        $lines = explode("\n", rtrim(str_replace("\r", '', $tester->getDisplay())));
        self::assertSame(sprintf('Would adopt "Adopt Demo" as a raw app (%s) with Sourdough %s (dry run: nothing written)', Permutation::Raw->label(), self::VERSION), $lines[0]);
        self::assertSame('Run sourdough adopt without --dry-run to write the manifest.', end($lines));
        $summary = array_values(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Plan: ')));
        self::assertCount(1, $summary);
        self::assertMatchesRegularExpression('/^Plan: \d+ match, 2 yours, 1 seeded, 2 missing\.$/', $summary[0]);
        self::assertStringNotContainsString('Wrote', $tester->getDisplay());

        $fileLines = array_values(array_filter($lines, static fn(string $l): bool => str_starts_with($l, '  ')));
        self::assertNotEmpty($fileLines);
        $detailColumn = null;
        $longest = 0;
        foreach ($fileLines as $line) {
            if (1 !== preg_match(self::LINE, $line, $m)) {
                self::fail('not a report line: ' . $line);
            }
            $longest = max($longest, strlen($m[2]));
            $at = strpos($line, $m[3], 11 + strlen($m[2]));
            $detailColumn ??= $at;
            self::assertSame($detailColumn, $at, 'the details line up: ' . $line);
        }
        self::assertSame(11 + $longest + 2, $detailColumn, 'the path column is as wide as the longest path, then two spaces');
    }

    /**
     * F11: --json, adopted or planned, in the spec's shape. Every template
     * path is listed, match included, sorted and never folded.
     */
    public function testJsonReportsInTheSpecsShape(): void
    {
        [$app] = $this->editedApp();
        $templatePaths = $this->rawTemplatePaths();

        $dry = $this->adopt($app, $this->f2() + ['--json' => true, '--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertSame([], (new Git())->status($app), 'a dry run writes nothing');
        $planned = json_decode($dry->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($planned);
        self::assertTrue($planned['dry_run']);

        $real = $this->adopt($app, $this->f2() + ['--json' => true]);
        self::assertSame(0, $real->getStatusCode(), $real->getDisplay());
        $data = json_decode($real->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        self::assertSame(['dir', 'dry_run', 'exit', 'files', 'name', 'notes', 'shape', 'sourdough_version', 'summary'], array_keys($data));
        self::assertSame($this->resolved($app), $data['dir']);
        self::assertFalse($data['dry_run']);
        self::assertSame(0, $data['exit']);
        self::assertSame(self::NAME, $data['name']);
        self::assertSame([], $data['notes']);
        self::assertSame('raw', $data['shape']);
        self::assertSame(self::VERSION, $data['sourdough_version']);

        $match = count($templatePaths) - 5;
        self::assertSame(['match' => $match, 'missing' => 2, 'seeded' => 1, 'yours' => 2], $data['summary']);

        self::assertIsArray($data['files']);
        self::assertSame($templatePaths, array_map(static fn(array $f): string => $f['path'], $data['files']));
        foreach ($data['files'] as $file) {
            self::assertSame(['path', 'status', 'detail'], array_keys($file));
        }
        self::assertContains(['path' => '.env.example', 'status' => 'missing', 'detail' => 'the next sourdough feed adds it'], $data['files']);
        self::assertContains(['path' => 'phpstan.neon', 'status' => 'yours', 'detail' => 'differs from the template: yours, never overwritten'], $data['files']);

        unset($planned['dry_run'], $data['dry_run']);
        self::assertSame($planned, $data, 'the dry run plans what the real run does');
        self::assertSame(['?? .sourdough/manifest.json'], (new Git())->status($app));
    }

    /**
     * A wrong name hints on each file and adds the note after the summary,
     * in the text and in the JSON notes.
     */
    public function testTheNameNoteFollowsTheSummary(): void
    {
        $app = $this->bake(Permutation::Raw, folder: 'beta');
        $this->loseManifest($app);
        $this->commitAll($app, 'baked as Adopt Demo in a folder named beta');

        $tester = $this->adopt($app, $this->raw() + ['--dry-run' => true]);
        $lines = explode("\n", rtrim(str_replace("\r", '', $tester->getDisplay())));
        $summary = (int) array_key_first(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Plan: ')));
        self::assertMatchesRegularExpression('/^note: \d+ files differ where the template writes the app name; if the app goes by another name, delete \.sourdough\/ and adopt again with --name\.$/', $lines[$summary + 1]);
        self::assertCount(1, array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'note: ')));

        $json = json_decode($this->adopt($app, $this->raw() + ['--dry-run' => true, '--json' => true])->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['notes']);
        self::assertSame(substr($lines[$summary + 1], strlen('note: ')), $json['notes'][0]);

        $named = $this->adopt($app, $this->raw() + ['--dry-run' => true, '--name' => self::NAME]);
        self::assertStringNotContainsString('note:', $named->getDisplay());
        self::assertStringContainsString('Would adopt "Adopt Demo"', $named->getDisplay());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingShapeGuesses(): array
    {
        return [
            'inertia' => [['artisan' => '', 'composer.json' => '{"require": {"inertiajs/inertia-laravel": "^2.0"}}', 'package.json' => '{"dependencies": {"vue": "^3"}}'], ' This app looks like laravel-inertia (it has artisan and inertiajs/inertia-laravel).'],
            'blade with vue' => [['artisan' => '', 'package.json' => '{"devDependencies": {"vue": "^3"}}'], ' This app looks like laravel-blade-vue (it has artisan and vue in package.json).'],
            'blade' => [['artisan' => '', 'composer.json' => '{"require": {"laravel/framework": "^8.0"}}'], ' This app looks like laravel-blade (it has artisan, and neither inertiajs/inertia-laravel nor vue).'],
            'raw with vue' => [['package.json' => '{"dependencies": {"vue": "^2.6"}}'], ' This app looks like raw-vue (it has vue in package.json and no artisan).'],
            'raw' => [['index.php' => '<?php echo 1;'], ' This app looks like raw (it has no artisan and no vue in package.json).'],
        ];
    }

    /**
     * F8, refusal 1: --shape is required, and the guess is only a hint.
     *
     * @param array<string, string> $files
     */
    #[DataProvider('missingShapeGuesses')]
    public function testAMissingShapeIsRefusedWithAGuess(array $files, string $guess): void
    {
        $app = $this->makeTempDir() . '/app';
        foreach ($files as $path => $content) {
            $this->put($app, $path, $content);
        }

        foreach ([[], ['--dry-run' => true]] as $options) {
            $tester = $this->adopt($app, $options);
            self::assertSame(1, $tester->getStatusCode());
            self::assertSame('--shape is required: ' . self::SHAPES . '.' . $guess, trim($tester->getDisplay()));
        }
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);
    }

    /**
     * F8, refusals 1 to 8: wrong flags or a folder adopt cannot take. Each
     * gives exit 1 and its message, on a real run and a dry run alike, and
     * before any git check (none of these folders is a repository).
     */
    public function testTheCommandRefusalsComeFirst(): void
    {
        $root = $this->makeTempDir();
        $plain = $root . '/plain';
        mkdir($plain);
        $file = $root . '/a-file.txt';
        file_put_contents($file, "not an app\n");
        $made = $root . '/made';
        $this->put($made, Manifest::FILE, "{}\n");
        $based = $root . '/based';
        $this->put($based, '.sourdough/base/composer.json.base', "{}\n");
        $badName = $root . '/_old app';
        mkdir($badName);
        $nameRule = 'The app name must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters.';

        $cases = [
            '1 no shape, no folder to guess from' => [$root . '/nowhere', [], '--shape is required: ' . self::SHAPES . '.'],
            '2 unknown shape' => [$plain, ['--shape' => 'vue'], 'Unknown shape "vue". The five shapes are: raw, raw-vue, laravel-blade, laravel-blade-vue, laravel-inertia.'],
            '3 oven on raw' => [$plain, ['--shape' => 'raw', '--docker' => true], '--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.'],
            '3 both ovens' => [$plain, ['--shape' => 'laravel-blade', '--sail' => true, '--docker' => true], 'Choose one of --sail or --docker, not both.'],
            '4 no folder' => [$root . '/nowhere', ['--shape' => 'raw'], sprintf('The app directory "%s/nowhere" does not exist.', $root)],
            '5 a file' => [$file, ['--shape' => 'raw'], sprintf('"%s" is a file, not a directory: pass the app\'s root folder.', $file)],
            '6 already has a manifest' => [$made, ['--shape' => 'raw'], sprintf('"%s" already has a Sourdough manifest: Sourdough made or adopted this app. Bring it up to date with sourdough feed. To adopt it again with another --shape or --name, delete .sourdough/, commit, and run adopt again.', $this->resolved($made))],
            '7 a leftover base' => [$based, ['--shape' => 'raw'], sprintf('"%s/.sourdough/base/" is left from a manifest that is gone. Delete .sourdough/, commit, and run adopt again.', $this->resolved($based))],
            '8 a bad --name' => [$plain, ['--shape' => 'raw', '--name' => '-dash first'], $nameRule . ' Pass --name "<a valid name>".'],
            '8 a folder name that is no app name' => [$badName, ['--shape' => 'raw'], $nameRule . ' Pass --name "<a valid name>".'],
        ];

        foreach ($cases as $label => [$dir, $options, $message]) {
            foreach ([[], ['--dry-run' => true], ['--json' => true]] as $extra) {
                $tester = $this->adopt($dir, $options + $extra);
                self::assertSame(1, $tester->getStatusCode(), $label . ': ' . $tester->getDisplay());
                self::assertSame($message, trim($tester->getDisplay()), $label . ' prints its message and no JSON');
            }
        }

        self::assertFileDoesNotExist($plain . '/' . Manifest::FILE);
        self::assertSame(['.sourdough'], array_values(array_diff(scandir($based) ?: [], ['.', '..'])));
        self::assertTrue(is_dir($badName) && [] === array_diff(scandir($badName) ?: [], ['.', '..']));

        // The default name comes from the resolved folder, and --name rescues it.
        $this->git($badName, ['init', '-q']);
        $this->put($badName, 'index.php', "<?php echo 'old';\n");
        $this->commitAll($badName, 'old app');
        $rescued = $this->adopt($badName, ['--shape' => 'raw', '--name' => 'old-app']);
        self::assertSame(0, $rescued->getStatusCode(), $rescued->getDisplay());
        self::assertSame('old-app', Manifest::read($badName)['answers']['name'] ?? null);
    }

    /**
     * F8, refusal 9: no git is a tool error; a dry run says so and plans.
     */
    public function testNoGitIsAToolErrorAndANoteOnADryRun(): void
    {
        $app = $this->legacyApp();
        $noGit = new Git($this->makeTempDir() . '/no-such-git');
        self::assertFalse($noGit->available());

        $tester = $this->adopt($app, ['--shape' => 'raw'], git: $noGit);
        self::assertSame(2, $tester->getStatusCode());
        self::assertSame('sourdough adopt needs git on PATH (or SOURDOUGH_GIT set to it): adopt checks the tree, and feed, which comes next, merges with git.', trim($tester->getDisplay()));
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);

        $dry = $this->adopt($app, ['--shape' => 'raw', '--dry-run' => true], git: $noGit);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString("\nnote: git is not available: a real adopt would fail.\n", $dry->getDisplay());
    }

    /**
     * F8, refusal 10: outside a work tree adopt refuses, and says how to
     * start one; a dry run notes it.
     */
    public function testOutsideGitIsRefused(): void
    {
        $app = $this->makeTempDir() . '/parking-permits';
        $this->copyTree(self::FIXTURE, $app);
        $resolved = $this->resolved($app);

        $tester = $this->adopt($app, ['--shape' => 'raw']);
        self::assertSame(1, $tester->getStatusCode());
        $message = trim($tester->getDisplay());
        self::assertStringStartsWith(sprintf('sourdough adopt only runs inside a git repository, so the adoption is one commit you can review and every later feed can be undone. "%s": ', $resolved), $message);
        self::assertStringEndsWith('. Start one with: git init, git add -A, git commit -m "Before Sourdough".', $message);
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);

        $dry = $this->adopt($app, ['--shape' => 'raw', '--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString("\nnote: Not inside a git repository: a real adopt would refuse.\n", $dry->getDisplay());
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);
    }

    /**
     * F8, refusal 11: a dirty tree is refused with up to ten status lines;
     * a dry run notes it.
     */
    public function testADirtyTreeIsRefused(): void
    {
        $app = $this->legacyApp();
        file_put_contents($app . '/README.md', "# Parking permits, edited\n");
        for ($i = 1; $i <= 11; ++$i) {
            $this->put($app, sprintf('drafts/%02d.txt', $i), "draft\n");
        }

        $tester = $this->adopt($app, ['--shape' => 'raw']);
        self::assertSame(1, $tester->getStatusCode());
        $lines = explode("\n", trim(str_replace("\r", '', $tester->getDisplay())));
        self::assertSame('Commit or stash your changes first: sourdough adopt compares your committed app with the template, and the adoption should be a commit of its own.', $lines[0]);
        self::assertSame('   M README.md', $lines[1]);
        self::assertSame('  ?? drafts/01.txt', $lines[2]);
        self::assertCount(12, $lines, 'ten status lines and a count');
        self::assertSame('  ... and 2 more', $lines[11]);
        self::assertFileDoesNotExist($app . '/' . Manifest::FILE);

        $dry = $this->adopt($app, ['--shape' => 'raw', '--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString("\nnote: Uncommitted changes: a real adopt would refuse until the tree is clean.\n", $dry->getDisplay());
    }

    /**
     * F8, refusal 12: a folder adopt cannot write in is a tool error; a dry
     * run notes it. Root writes anywhere, so this skips there.
     */
    public function testAFolderItCannotWriteIsAToolError(): void
    {
        $app = $this->legacyApp();
        chmod($app, 0555);
        clearstatcache();
        try {
            if (is_writable($app)) {
                self::markTestSkipped('this user can write a read-only folder (root, or a disk without modes).');
            }

            $tester = $this->adopt($app, ['--shape' => 'raw']);
            self::assertSame(2, $tester->getStatusCode());
            self::assertSame(sprintf('Cannot write "%s/.sourdough/manifest.json": the folder is not writable. adopt writes only that file; check the folder\'s permissions.', $this->resolved($app)), trim($tester->getDisplay()));

            $dry = $this->adopt($app, ['--shape' => 'raw', '--dry-run' => true]);
            self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
            self::assertStringContainsString("\nnote: The folder is not writable: a real adopt would fail.\n", $dry->getDisplay());
        } finally {
            chmod($app, 0777);
        }
    }

    /**
     * F8, refusal 13: a bake that fails is a tool error, real or dry, and a
     * Laravel shape says what its bake needs.
     */
    public function testAFailedBakeIsAToolError(): void
    {
        $app = $this->legacyApp();
        $offline = new class implements SkeletonSource {
            public function create(string $targetDir): void
            {
                throw new \RuntimeException('composer create-project could not reach the network.');
            }
        };

        foreach ([[], ['--dry-run' => true]] as $extra) {
            $tester = $this->adopt($app, ['--shape' => 'laravel-blade'] + $extra, skeleton: $offline);
            self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
            self::assertSame('sourdough adopt failed: could not bake a fresh laravel-blade app to compare with: composer create-project could not reach the network. The Laravel shapes need Composer and the network (or a warm Composer cache).', trim($tester->getDisplay()));
        }
        self::assertSame([], (new Git())->status($app));
    }

    /**
     * F8, refusal 14: the write itself fails (here `.sourdough` is a file,
     * so its folder cannot be made): a tool error, with Manifest's words.
     */
    public function testAFailedWriteIsAToolError(): void
    {
        $app = $this->legacyApp();
        file_put_contents($app . '/.sourdough', "a file where the folder goes\n");
        $this->commitAll($app, 'a file in the way');

        $tester = $this->adopt($app, ['--shape' => 'raw']);
        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(sprintf('sourdough adopt failed: Could not create the directory "%s/.sourdough".', $this->resolved($app)), trim($tester->getDisplay()));
        self::assertSame([], (new Git())->status($app));

        $dry = $this->adopt($app, ['--shape' => 'raw', '--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), 'a dry run writes nothing, so nothing fails');
    }

    /**
     * The refusals keep the spec's order where two apply at once.
     */
    public function testTheRefusalsComeInOrder(): void
    {
        $outside = $this->makeTempDir() . '/outside';
        $this->put($outside, Manifest::FILE, "{}\n");

        // 6 before 10: the record is the first thing to fix.
        $tester = $this->adopt($outside, ['--shape' => 'raw']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already has a Sourdough manifest', $tester->getDisplay());

        // 2 before 4.
        $tester = $this->adopt($outside . '/nowhere', ['--shape' => 'blade']);
        self::assertStringStartsWith('Unknown shape "blade".', trim($tester->getDisplay()));

        // 8 before 9: a bad name with no git still names the name.
        $tester = $this->adopt($outside, ['--shape' => 'raw', '--name' => '!'], git: new Git($outside . '/no-such-git'));
        self::assertStringContainsString('already has a Sourdough manifest', $tester->getDisplay(), '6 still comes before 8');
        unlink($outside . '/' . Manifest::FILE);
        $tester = $this->adopt($outside, ['--shape' => 'raw', '--name' => '!'], git: new Git($outside . '/no-such-git'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringEndsWith(' Pass --name "<a valid name>".', trim($tester->getDisplay()));
    }

    /**
     * The checked-in legacy app, copied into a folder named parking-permits
     * and committed.
     */
    private function legacyApp(): string
    {
        $app = $this->makeTempDir() . '/parking-permits';
        $this->copyTree(self::FIXTURE, $app);
        $this->git($app, ['init', '-q']);
        $this->commitAll($app, 'Before Sourdough');

        return $app;
    }

    /**
     * Every path a raw bake writes, sorted.
     *
     * @return list<string>
     */
    private function rawTemplatePaths(): array
    {
        $paths = array_keys(Manifest::files(Manifest::read($this->bake(Permutation::Raw))));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        return ['--shape' => 'raw', '--no-playbook' => true, '--no-advisor' => true];
    }

    /**
     * The F2 app's options: it was baked as "Adopt Demo" in a folder named app.
     *
     * @return array<string, mixed>
     */
    private function f2(): array
    {
        return $this->raw() + ['--name' => self::NAME];
    }

    /**
     * The report's file lines, as [status, path, detail].
     *
     * @return list<array{string, string, string}>
     */
    private function rows(string $display): array
    {
        $rows = [];
        foreach (explode("\n", str_replace("\r", '', $display)) as $line) {
            if (1 === preg_match(self::LINE, $line, $m)) {
                $rows[] = [trim($m[1]), $m[2], $m[3]];
            }
        }

        return $rows;
    }
}
