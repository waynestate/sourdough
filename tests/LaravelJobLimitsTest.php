<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * WELD W18 and W20 in every Laravel shape: every queued job declares
 * Laravel's #[Tries] and #[Timeout], with the timeout below the queue
 * connection's retry_after. The baked app's own
 * tests/Feature/JobLimitsTest.php enforces it: it passes while app/Jobs is
 * empty and fails on a fixture job without the attributes. The make:job
 * stubs write both, so the first job is born with its limits. Static checks
 * on the baked tree, because running the app's Pest suite needs a composer
 * install (the smoke gate does that). WELD copies the Inertia shape byte for
 * byte, so the Blade and Inertia copies must stay identical.
 */
final class LaravelJobLimitsTest extends TestCase
{
    /** The files both Laravel overlays carry, byte for byte. */
    private const SHARED_FILES = [
        'stubs/job.batched.queued.stub',
        'stubs/job.queued.stub',
        'tests/Feature/JobLimitsTest.php',
    ];

    /** Laravel's default retry_after on every queue connection that has one. */
    private const DEFAULT_RETRY_AFTER = 90;

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
     * @return array<string, array{0: class-string<LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator>}>
     */
    public static function laravelShapes(): array
    {
        return [
            'blade' => [LaravelBladeGenerator::class],
            'blade-vue' => [LaravelBladeVueGenerator::class],
            'inertia' => [LaravelInertiaGenerator::class],
        ];
    }

    /**
     * @param class-string<LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator> $generator
     */
    #[DataProvider('laravelShapes')]
    public function testEveryQueuedJobIsHeldToItsLimits(string $generator): void
    {
        $target = $this->bake($generator);

        $test = (string) file_get_contents($target . '/tests/Feature/JobLimitsTest.php');
        self::assertStringContainsString("sourdoughJobClasses(app_path('Jobs'), 'App\\\\Jobs')", $test);
        self::assertStringContainsString('use Illuminate\\Queue\\Attributes\\Tries;', $test);
        self::assertStringContainsString('use Illuminate\\Queue\\Attributes\\Timeout;', $test);
        self::assertStringContainsString("it('keeps every job timeout below the queue connection retry_after'", $test);
        // The test is tested: it must fail on a fixture job without them.
        self::assertStringContainsString("it('catches a queued job without its limits'", $test);
        self::assertStringContainsString("'Bare' => ['#[Tries]', '#[Timeout]']", $test);

        // No job classes ship, so the app's test passes until the first one.
        self::assertDirectoryDoesNotExist($target . '/app/Jobs');

        // make:job reads stubs/ first: the jobs it writes carry both limits,
        // and the timeout sits below Laravel's default retry_after.
        foreach (['stubs/job.queued.stub', 'stubs/job.batched.queued.stub'] as $stub) {
            $source = (string) file_get_contents($target . '/' . $stub);
            self::assertStringContainsString('class {{ class }} implements ShouldQueue', $source, $stub . ' stays a stub');
            self::assertSame(1, preg_match('/^#\[Tries\((\d+)\)\]$/m', $source, $tries), $stub . ': #[Tries]');
            self::assertSame(1, preg_match('/^#\[Timeout\((\d+)\)\]$/m', $source, $timeout), $stub . ': #[Timeout]');
            self::assertGreaterThan(0, (int) ($tries[1] ?? 0), $stub);
            self::assertGreaterThan(0, (int) ($timeout[1] ?? 0), $stub);
            self::assertLessThan(self::DEFAULT_RETRY_AFTER, (int) ($timeout[1] ?? 0), $stub . ': timeout below retry_after');
            self::assertStringContainsString('use Illuminate\\Queue\\Attributes\\Tries;', $source, $stub);
            self::assertStringContainsString('use Illuminate\\Queue\\Attributes\\Timeout;', $source, $stub);
        }
    }

    public function testBothOverlaysCarryTheSameBytes(): void
    {
        $templates = dirname(__DIR__) . '/templates';

        foreach (self::SHARED_FILES as $path) {
            self::assertFileEquals($templates . '/laravel-inertia/' . $path, $templates . '/laravel-blade/' . $path, $path);
        }
    }

    /**
     * @param class-string<LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator> $generator
     */
    private function bake(string $generator): string
    {
        $target = $this->makeTempDir();
        (new $generator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        return $target;
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
