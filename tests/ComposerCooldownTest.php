<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * CREED's decision of 2026-11-03 (handbook dependencies.md:25, BEST_PRACTICES
 * 4.9): every app shape sets Composer's release cooldown,
 * config.policy.cooldown.period, to three days. Composer 2.11 is stable and
 * pinned in the stack's images, so the key is read. The raw shapes carry it
 * in the template's own composer.json; the Laravel shapes carry it in the
 * overlay that deep-merges into the skeleton's composer.json.
 */
final class ComposerCooldownTest extends TestCase
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

    /**
     * The four shapes the suite can render offline (no network: the Laravel
     * shapes bake from the checked-in fixture skeleton).
     *
     * @return iterable<string, array{string}>
     */
    public static function shapes(): iterable
    {
        yield 'raw' => ['raw'];
        yield 'raw-vue' => ['raw-vue'];
        yield 'laravel-blade' => ['laravel-blade'];
        yield 'laravel-inertia' => ['laravel-inertia'];
    }

    #[DataProvider('shapes')]
    public function testEveryShapeSetsTheThreeDayComposerCooldown(string $shape): void
    {
        $target = $this->makeTempDir();
        $this->bake($shape, $target);

        self::assertFileExists($target . '/composer.json', $shape);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The cooldown is set, at exactly three days...
        self::assertSame('3 days', $composer['config']['policy']['cooldown']['period'] ?? null, $shape);
        // ...and the old config keys survived the bake and the merge, the
        // PHP floor among them.
        self::assertArrayHasKey('php', $composer['config']['platform'] ?? [], $shape);
    }

    private function bake(string $shape, string $target): void
    {
        match ($shape) {
            'raw' => (new RawGenerator(__DIR__ . '/../templates/raw'))
                ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false),
            'raw-vue' => (new RawVueGenerator())
                ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false),
            'laravel-blade' => (new LaravelBladeGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
                ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false),
            'laravel-inertia' => (new LaravelInertiaGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
                ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false),
            default => throw new \RuntimeException(sprintf('Unknown shape "%s".', $shape)),
        };
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
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
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
