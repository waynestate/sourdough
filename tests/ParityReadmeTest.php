<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\GeneratorSupport;
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
 * README names `parity init --from-squire` once, then `parity up`,
 * `parity test` and `parity down`. When the kit's local-running files left
 * Squire (D-095), the section lost the commands that read them; this test
 * holds the README to the four remaining commands and the bake to the
 * absence of the five files it no longer ships.
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
     * @return array<string, array{0: string}>
     */
    public static function shapes(): array
    {
        return [
            'raw' => ['raw'],
            'raw-vue' => ['raw-vue'],
            'laravel-blade' => ['laravel-blade'],
            'laravel-blade-vue' => ['laravel-blade-vue'],
            'laravel-inertia' => ['laravel-inertia'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheReadmeRunsTheAppLikeCitadelWithParity(string $shape): void
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
        foreach (['parity init --from-squire', 'parity up', 'parity test', 'parity down'] as $command) {
            self::assertStringContainsString($command, $section, $command);
        }
        self::assertMatchesRegularExpression('/^parity init --from-squire$/m', $section, 'parity init runs as its own line');
        self::assertStringContainsString('separate install', $section, 'Parity is never a hard dependency');

        // The kit's local-running files left Squire 0.12; the bake ships
        // none of them, and the section never names them.
        foreach (GeneratorSupport::NO_LONGER_SHIPPED as $path) {
            self::assertFileDoesNotExist($target . '/' . $path, $path);
            self::assertStringNotContainsString($path, $section, $path);
        }
        // Parity writes parity.json; Sourdough bakes none.
        self::assertFileDoesNotExist($target . '/parity.json');

        // The deprecated parity kit in Squire stays out of the new text.
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
