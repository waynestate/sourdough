<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * HELMET 1.2.0 and later answers every 4xx HTTP exception with its fixed
 * page when helmet.error_pages is on, so an abort() message never reaches
 * a visitor. The package leaves it off for old apps; a new app starts with
 * it on, in its own config/helmet.php. JSON clients, validation, auth
 * redirects and every 5xx stay with Laravel either way.
 */
final class LaravelErrorPagesTest extends TestCase
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
     * The two Laravel shapes bake from the checked-in fixture skeleton, so
     * the suite can render them offline (no network).
     *
     * @return iterable<string, array{string}>
     */
    public static function shapes(): iterable
    {
        yield 'laravel-blade' => ['laravel-blade'];
        yield 'laravel-inertia' => ['laravel-inertia'];
    }

    #[DataProvider('shapes')]
    public function testNewAppsTurnOnHelmetErrorPages(string $shape): void
    {
        $target = $this->makeTempDir();
        $this->bake($shape, $target);

        $helmet = (string) file_get_contents($target . '/config/helmet.php');

        // The app's config turns the fixed 4xx page on by default...
        self::assertStringContainsString(
            "'error_pages' => filter_var(env('HELMET_ERROR_PAGES', true), FILTER_VALIDATE_BOOL)",
            $helmet,
            $shape,
        );
        // ...and the existing override survives the new key.
        self::assertStringContainsString("'policy_path' => $policyPath", $helmet, $shape);

        // The env key ships commented out, so the config's default holds
        // instead of an empty value overriding it.
        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# HELMET_ERROR_PAGES=', $env, $shape);

        // The file still parses: tokenizing it does not throw.
        self::assertIsArray(token_get_all($helmet), $shape);
    }

    private function bake(string $shape, string $target): void
    {
        match ($shape) {
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
