<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Command\CheckCommand;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;

/**
 * `sourdough check`, the poke test: fed or behind, supported or past it,
 * and never a byte written. Apps are baked as FeedCommandTest bakes them
 * (an "older template" is a fresh bake with one managed file and its
 * manifest hash rewound together), on the checked-in Laravel skeleton, so
 * nothing here touches the network.
 */
final class CheckCommandTest extends TestCase
{
    private const OLD_VERSION = '0.1.0';

    private const NEW_VERSION = '0.1.6';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        if (!(new Git())->available()) {
            self::markTestSkipped('git is not on PATH; sourdough check plans a feed, which stands on git.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testAFreshRawAppIsFedAndSupported(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->commitAll($app, 'baked');

        $tester = $this->check($app, today: '2026-11-11');
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        self::assertMatchesRegularExpression('/^Support:     supported, owned by /m', $display);
        self::assertStringContainsString("Manifest:    clean: every managed file matches the manifest\n", $display);
        self::assertStringContainsString('Feed:        fresh: nothing to feed from Sourdough ' . self::NEW_VERSION . "\n", $display);
        self::assertStringContainsString('OK: the raw app is fed and supported.', $display);
        self::assertStringNotContainsString('Needs attention', $display);
    }

    public function testALaravelAppRunsOnTheLaravelClock(): void
    {
        $app = $this->bake(Permutation::LaravelBlade);
        $this->commitAll($app, 'baked');

        $tester = $this->check($app, today: '2026-11-11');
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        self::assertMatchesRegularExpression('/^Support:     .*Laravel \d+/m', $display);
        self::assertStringContainsString('OK: the laravel-blade app is fed and supported.', $display);
    }

    public function testAnAppBehindTheTemplateNeedsAttentionAndNothingIsWritten(): void
    {
        $app = $this->agedApp();

        $tester = $this->check($app, today: '2026-11-11');
        $display = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode(), $display);
        self::assertStringContainsString('Feed:        behind: Sourdough ' . self::OLD_VERSION . ' to ' . self::NEW_VERSION . ', 1 file would change', $display);
        self::assertStringContainsString("Needs attention:\n  - behind: feeding to Sourdough " . self::NEW_VERSION . ' would change 1 file; run sourdough feed --dry-run for the plan', $display);
        self::assertSame([], (new Git())->status($app), 'a check writes nothing');
        self::assertSame(self::OLD_VERSION, Manifest::read($app)['sourdough_version'], 'the manifest is untouched');
    }

    public function testTheAppsOwnEditsAreReportedButNeverFail(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->commitAll($app, 'baked');
        $this->put($app, 'phpstan.neon', "# our own first line\n" . $this->read($app, 'phpstan.neon'));
        $this->commitAll($app, 'our edit');

        $tester = $this->check($app, today: '2026-11-11');
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        self::assertStringContainsString('Manifest:    1 changed, 0 missing (the app owns these; feed merges them)', $display);
        self::assertStringContainsString('OK: the raw app is fed and supported.', $display);
    }

    public function testALinePastItsSecuritySupportNeedsAttention(): void
    {
        $app = $this->bake(Permutation::Raw);
        $this->commitAll($app, 'baked');

        $tester = $this->check($app, today: '2999-01-01');
        $display = $tester->getDisplay();

        // CREED's policy may not be readable in every checkout; when it is
        // not, there is no date to be past, and the check stays quiet.
        if (!str_contains($display, 'security fixes until')) {
            self::assertSame(0, $tester->getStatusCode(), $display);
            self::markTestSkipped('CREED\'s policy was not read, so there is no end-of-security date to compare.');
        }

        self::assertSame(1, $tester->getStatusCode(), $display);
        self::assertMatchesRegularExpression('/^  - past support: PHP \d+\.\d+ stopped getting security fixes on \d{4}-\d{2}-\d{2}$/m', $display);
    }

    public function testJsonCarriesTheWholeCheck(): void
    {
        $app = $this->agedApp();

        $tester = $this->check($app, ['--json' => true], '2026-11-11');
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['dir', 'template', 'manifest', 'feed', 'support', 'problems', 'exit'], array_keys($data));
        self::assertSame('raw', $data['template']);
        self::assertSame(['changed' => [], 'missing' => []], $data['manifest']);
        self::assertSame(['from' => self::OLD_VERSION, 'to' => self::NEW_VERSION, 'changes' => 1, 'fresh' => false], $data['feed']);
        self::assertIsArray($data['support']);
        self::assertSame('raw', $data['support']['shape']);
        self::assertIsArray($data['problems']);
        self::assertCount(1, $data['problems']);
        self::assertStringStartsWith('behind: ', $data['problems'][0]);
        self::assertSame(1, $data['exit']);
    }

    public function testNoManifestIsAToolError(): void
    {
        $dir = $this->makeTempDir();

        $tester = $this->check($dir);

        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('manifest', strtolower($tester->getDisplay()));
    }

    public function testTheCliRegistersTheCheckCommand(): void
    {
        $bin = file_get_contents(dirname(__DIR__) . '/bin/sourdough');
        self::assertIsString($bin);
        self::assertStringContainsString('$application->addCommand(new CheckCommand());', $bin);
    }

    // Helpers.

    /**
     * @param array<string, mixed> $options
     */
    private function check(string $app, array $options = [], ?string $today = null): CommandTester
    {
        $command = new CheckCommand(
            new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'),
            null,
            self::NEW_VERSION,
            null === $today ? null : new \DateTimeImmutable($today),
        );
        $tester = new CommandTester($command);
        $tester->execute(['dir' => $app] + $options);

        return $tester;
    }

    /**
     * A committed raw app whose phpstan.neon (in every shape) and its
     * manifest hash say an older template wrote it.
     */
    private function agedApp(): string
    {
        $app = $this->bake(Permutation::Raw);
        $old = $this->read($app, 'phpstan.neon') . "# an older template wrote this line\n";
        $this->put($app, 'phpstan.neon', $old);

        $manifest = Manifest::read($app);
        self::assertIsArray($manifest['files']);
        self::assertIsArray($manifest['files']['phpstan.neon']);
        $manifest['files']['phpstan.neon']['sha256'] = hash('sha256', $old);
        $manifest['sourdough_version'] = self::OLD_VERSION;
        Manifest::writeData($app, $manifest);

        $this->commitAll($app, 'baked by an older Sourdough');

        return $app;
    }

    private function bake(Permutation $shape): string
    {
        $app = $this->makeTempDir() . '/app';

        $answers = ['no-playbook' => true, 'no-advisor' => true];
        if (Permutation::Raw === $shape || Permutation::RawVue === $shape) {
            $answers['raw'] = true;
        } else {
            $answers['laravel'] = true;
        }

        (new Baker(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->bake($shape, $app, 'Check Demo', $answers, PlaybookMode::None, false, false, false, self::OLD_VERSION);
        $this->git($app, ['init', '-q']);

        return $app;
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
            '-c', 'user.name=Check Test',
            '-c', 'user.email=check@example.com',
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
        file_put_contents($app . '/' . $path, $content);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-check-test-' . bin2hex(random_bytes(8));
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
