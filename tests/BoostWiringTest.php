<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\LaravelOverlayGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Laravel Boost ships in every Laravel shape as a dev tool and never
 * reaches production: require-dev only, a post-update refresh that skips
 * itself where Boost is not set up, its regenerated files git-ignored and
 * the team's .ai/ files committed. (WELD W5 and W29; Sourdough 2.3.)
 * A starter rises without a boost on the server.
 */
final class BoostWiringTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../templates';

    /**
     * The files both Laravel overlays carry byte for byte.
     *
     * @var list<string>
     */
    private const SHARED_FILES = [
        'gitignore-additions.txt',
        '.ai/rules/index.md',
        '.ai/rules/migrations.md',
        '.ai/rules/routes.md',
        'tests/Feature/BoostDevOnlyTest.php',
    ];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'laravel-blade' => ['laravel-blade'],
            'laravel-blade-vue' => ['laravel-blade-vue'],
            'laravel-inertia' => ['laravel-inertia'],
        ];
    }

    #[DataProvider('shapes')]
    public function testBoostIsADevDependencyOnly(string $shape): void
    {
        $composer = $this->composer($this->bake($shape));

        self::assertSame('^2.10', $composer['require-dev']['laravel/boost'] ?? null);
        self::assertArrayNotHasKey('laravel/boost', $composer['require']);
    }

    #[DataProvider('shapes')]
    public function testComposerUpdateKeepsLaravelsHookThenRefreshesBoost(string $shape): void
    {
        $composer = $this->composer($this->bake($shape));

        // The skeleton's own hook stays first; the overlay's is appended.
        self::assertSame(
            ['@php artisan vendor:publish --tag=laravel-assets --ansi --force', '@boost-update'],
            $composer['scripts']['post-update-cmd'],
        );
        self::assertStringStartsWith('@php -r "', $composer['scripts']['boost-update']);
        self::assertStringContainsString('--no-dev', $composer['scripts-descriptions']['boost-update']);
    }

    #[DataProvider('shapes')]
    public function testGitignoreKeepsWhatBoostRegeneratesOut(string $shape): void
    {
        $target = $this->bake($shape);
        $lines = array_map('trim', explode("\n", (string) file_get_contents($target . '/.gitignore')));

        foreach (['/.mcp.json', '/boost.json', '/AGENTS.md', '/CLAUDE.md', '/.claude/skills/', '/.junie/'] as $ignored) {
            self::assertContains($ignored, $lines, $ignored);
            self::assertSame(1, count(array_keys($lines, $ignored, true)), $ignored . ' appears once');
        }
        // .agents/ stays tracked: Booster keeps its playbooks there.
        foreach ($lines as $line) {
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            self::assertStringNotContainsString('.agents', $line);
        }
        // The skeleton's own lines are kept.
        self::assertContains('/vendor', $lines);
    }

    #[DataProvider('shapes')]
    public function testShipsTheTeamsAiFilesAndTheGuardTest(string $shape): void
    {
        $target = $this->bake($shape);

        foreach (['.ai/guidelines/wayne-state.md', '.ai/rules/index.md', '.ai/rules/migrations.md', '.ai/rules/routes.md', 'tests/Feature/BoostDevOnlyTest.php'] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // The rules are the team's from the first commit (seeded); the
        // stack's guideline is refreshed by sourdough feed (managed).
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('seeded', $manifest['files']['.ai/rules/index.md']['class']);
        self::assertSame('seeded', $manifest['files']['.ai/rules/migrations.md']['class']);
        self::assertSame('seeded', $manifest['files']['.ai/rules/routes.md']['class']);
        self::assertSame('managed', $manifest['files']['.ai/guidelines/wayne-state.md']['class']);
    }

    #[DataProvider('shapes')]
    public function testReadmeExplainsBoostBeforeAdvisorAndTheHandbook(string $shape): void
    {
        $readme = (string) file_get_contents($this->bake($shape) . '/README.md');

        $boost = strpos($readme, '## Laravel Boost');
        $advisor = strpos($readme, '## ADVISOR');
        $handbook = strpos($readme, '## Handbook');
        self::assertIsInt($boost);
        self::assertIsInt($advisor);
        self::assertIsInt($handbook);
        self::assertLessThan($advisor, $boost);
        self::assertLessThan($handbook, $advisor);
        self::assertStringContainsString('php artisan boost:install', $readme);
    }

    #[DataProvider('shapes')]
    public function testDropsTheSkeletonsBoostBootstrapFiles(string $shape): void
    {
        $target = $this->bake($shape);

        // The fixture skeleton ships both, as laravel/laravel 13 does.
        self::assertFileExists(__DIR__ . '/fixtures/laravel-skeleton/CLAUDE.md');
        self::assertFileDoesNotExist($target . '/CLAUDE.md');
        self::assertFileDoesNotExist($target . '/AGENTS.md');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('CLAUDE.md', $manifest['files']);
        self::assertArrayNotHasKey('AGENTS.md', $manifest['files']);
    }

    public function testKeepsAnAgentFileWithGuidanceOutsideTheBootstrapBlock(): void
    {
        $skeleton = $this->makeTempDir() . '/skeleton';
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        file_put_contents($skeleton . '/AGENTS.md', "# House rules\n\n" . (string) file_get_contents($skeleton . '/AGENTS.md'));

        $target = $this->makeTempDir() . '/app';
        (new LaravelInertiaGenerator(new FixtureSkeletonSource($skeleton)))->generate($target, 'Boost Demo', [], PlaybookMode::Suggest);

        self::assertStringStartsWith('# House rules', (string) file_get_contents($target . '/AGENTS.md'));
        self::assertFileDoesNotExist($target . '/CLAUDE.md');

        $lines = array_map('trim', explode("\n", (string) file_get_contents($target . '/.gitignore')));
        self::assertNotContains('/AGENTS.md', $lines, 'a file the bake ships stays in git');
        self::assertContains('/CLAUDE.md', $lines);
    }

    public function testNoAdvisorKeepsBoostsMcpLine(): void
    {
        $target = $this->bake('laravel-inertia', false);
        $lines = array_map('trim', explode("\n", (string) file_get_contents($target . '/.gitignore')));

        self::assertContains('/.mcp.json', $lines);
        self::assertNotContains('.mcp.json', $lines);
        self::assertArrayHasKey('laravel/boost', $this->composer($target)['require-dev']);
    }

    public function testBothLaravelOverlaysCarryTheSameBoostFiles(): void
    {
        foreach (self::SHARED_FILES as $path) {
            self::assertFileEquals(self::TEMPLATES . '/laravel-inertia/' . $path, self::TEMPLATES . '/laravel-blade/' . $path, $path);
        }

        $blade = $this->overlay('laravel-blade');
        $inertia = $this->overlay('laravel-inertia');
        self::assertSame($inertia['require-dev']['laravel/boost'], $blade['require-dev']['laravel/boost']);
        self::assertSame($inertia['scripts']['boost-update'], $blade['scripts']['boost-update']);
        self::assertSame($inertia['scripts']['post-update-cmd'], $blade['scripts']['post-update-cmd']);
        self::assertSame($inertia['scripts-descriptions']['boost-update'], $blade['scripts-descriptions']['boost-update']);
    }

    /**
     * The starter rules are in Boost's own format: every rule file is in
     * the index with the globs its frontmatter declares, so Boost's
     * boost:index-rules writes the same index back.
     */
    public function testTheRulesIndexMatchesTheRuleFiles(): void
    {
        $dir = self::TEMPLATES . '/laravel-inertia/.ai/rules';
        $rows = [];
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            if ('index.md' === basename($file)) {
                continue;
            }
            self::assertSame(1, preg_match("/\\A---\npaths:\n((?:  - '[^']+'\n)+)---\n/", (string) file_get_contents($file), $match), basename($file));
            preg_match_all("/  - '([^']+)'/", $match[1] ?? '', $globs);
            $rows[] = '| ' . implode(', ', $globs[1]) . ' | .ai/rules/' . basename($file) . ' |';
        }
        sort($rows);

        $expected = "# Project Rules Index\n\n"
            . "Before planning or editing, find the row whose globs match the file's path and read that rule file.\n\n"
            . "| Applies to | Rule file |\n| --- | --- |\n" . implode("\n", $rows) . "\n";
        self::assertSame($expected, file_get_contents($dir . '/index.md'));
    }

    public function testTheGuardSkipsAFreshCloneAndANoDevInstall(): void
    {
        $dir = $this->makeTempDir();
        // An artisan that fails loudly if the guard ever calls it.
        file_put_contents($dir . '/artisan', "<?php\nfwrite(STDERR, 'called');\nexit(9);\n");

        [$exit, , $stderr] = $this->runGuard($dir);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);

        file_put_contents($dir . '/boost.json', "{}\n");
        [$exit, , $stderr] = $this->runGuard($dir);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
    }

    public function testTheGuardRunsBoostUpdateAndKeepsItsExitCode(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/vendor/laravel/boost', 0o777, true);
        file_put_contents($dir . '/boost.json', "{}\n");
        file_put_contents($dir . '/artisan', "<?php\necho implode(' ', array_slice(\$argv, 1));\nexit(3);\n");

        [$exit, $stdout] = $this->runGuard($dir);

        self::assertSame('boost:update --ansi', $stdout);
        self::assertSame(3, $exit);
    }

    /**
     * Run the boost-update guard the way Composer would, with $dir as the
     * app root.
     *
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private function runGuard(string $dir): array
    {
        $command = (string) $this->overlay('laravel-inertia')['scripts']['boost-update'];
        self::assertSame(1, preg_match('/^@php -r "(.+)"$/s', $command, $match));

        $process = proc_open([PHP_BINARY, '-r', $match[1] ?? ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function bake(string $shape, bool $advisor = true): string
    {
        $skeleton = new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
        $generator = match ($shape) {
            'laravel-blade' => new LaravelBladeGenerator($skeleton),
            'laravel-blade-vue' => new LaravelBladeVueGenerator($skeleton),
            'laravel-inertia' => new LaravelInertiaGenerator($skeleton),
            default => throw new \InvalidArgumentException($shape),
        };
        self::assertInstanceOf(LaravelOverlayGenerator::class, $generator);

        $target = $this->makeTempDir() . '/app';
        $generator->generate($target, 'Boost Demo', [], PlaybookMode::Suggest, $advisor);

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(string $target): array
    {
        $decoded = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function overlay(string $template): array
    {
        $decoded = json_decode((string) file_get_contents(self::TEMPLATES . '/' . $template . '/composer.overlay.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-boost-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($dest, 0o777, true) : copy($item->getPathname(), $dest);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
