<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Every baked README tells the developer how to run the app like Citadel
 * with Parity, inside "Run it locally", beside the everyday bench.
 *
 * Parity is a separate install, so Sourdough bakes no parity.json: the
 * README names `parity init`, which reads the app's folder (its
 * composer.json, public directory and routes) and writes parity.json, the
 * app's Citadel settings. This test holds the README to that command and
 * keeps it away from the kit files Squire 0.12.0 stopped shipping
 * (docker-compose.squire.yml, .env.squire.example and the parity scripts).
 * The raw shapes add `--no-cron`, because a raw app has no artisan to run
 * Laravel's scheduler.
 *
 * Proofed for real on 2026-11-09 against Parity's main branch: init, check,
 * up and test on a raw and a Blade bake.
 */
final class ParityReadmeTest extends TestCase
{
    private const HEADING = '### Run it like Citadel';

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
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shapes(): array
    {
        return [
            'raw' => ['raw', true],
            'raw-vue' => ['raw-vue', true],
            'laravel-blade' => ['laravel-blade', false],
            'laravel-blade-vue' => ['laravel-blade-vue', false],
            'laravel-inertia' => ['laravel-inertia', false],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheReadmeRunsTheAppLikeCitadelWithParity(string $shape, bool $raw): void
    {
        $target = $this->bake($shape);
        $readme = (string) file_get_contents($target . '/README.md');

        // The section sits inside "Run it locally", before the next H2.
        $local = strpos($readme, "\n## Run it locally\n");
        self::assertNotFalse($local, 'the README has a Run it locally section');
        $start = strpos($readme, "\n" . self::HEADING . "\n", $local);
        self::assertNotFalse($start, 'Run it locally has a Run it like Citadel subsection');
        $end = strpos($readme, "\n## ", $start + 1);
        self::assertNotFalse($end, 'another section follows Run it locally');
        $nextH2 = strpos($readme, "\n## ", $local + 1);
        self::assertSame($nextH2, $end, 'the subsection is the end of Run it locally, not a later section');
        $section = substr($readme, $start, $end - $start);

        self::assertSame(1, substr_count($readme, self::HEADING), 'one Parity section');
        self::assertStringContainsString('[Parity](https://git.wayne.edu/enterprise-apps/parity)', $section);
        foreach (['parity check', 'parity up', 'parity test', 'parity image get', 'parity up --fallback'] as $command) {
            self::assertStringContainsString($command, $section, $command);
        }
        self::assertStringContainsString('separate install', $section, 'Parity is never a hard dependency');

        $init = $raw ? 'parity init --no-cron' : 'parity init';
        self::assertMatchesRegularExpression('/^' . preg_quote($init, '/') . '$/m', $section, $init);
        if (!$raw) {
            self::assertStringNotContainsString('--no-cron', $section, 'a Laravel shape keeps its scheduler entry');
        }

        // The kit files Squire 0.12.0 stopped shipping stay out of the app.
        self::assertFileDoesNotExist($target . '/docker-compose.squire.yml');
        self::assertFileDoesNotExist($target . '/.env.squire.example');
        // Parity writes parity.json; Sourdough bakes none.
        self::assertFileDoesNotExist($target . '/parity.json');

        // The old kit-based wording stays out of the new text.
        self::assertStringNotContainsString('--from-squire', $section);
        self::assertStringNotContainsString('docker-compose.squire.yml', $section);
        self::assertStringNotContainsString('.env.squire.example', $section);
        self::assertStringNotContainsString('scripts/parity', $section);
        self::assertStringNotContainsString('--only=parity', $section);
        self::assertStringNotContainsString("\u{2014}", $section, 'no em dashes');
        self::assertStringNotContainsString("\u{2013}", $section, 'no en dashes');
    }

    private function bake(string $shape): string
    {
        $target = $this->makeTempDir();
        $skeleton = new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
        $generator = match ($shape) {
            'raw' => new RawGenerator(),
            'raw-vue' => new RawVueGenerator(),
            'laravel-blade' => new LaravelBladeGenerator($skeleton),
            'laravel-blade-vue' => new LaravelBladeVueGenerator($skeleton),
            'laravel-inertia' => new LaravelInertiaGenerator($skeleton),
            default => throw new \InvalidArgumentException($shape),
        };
        $generator->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
