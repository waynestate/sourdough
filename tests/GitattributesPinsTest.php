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
 * CREED's every-OS rule (handbook/every-os.md): every app shape's root
 * .gitattributes pins *.sh to LF and *.ps1, *.bat and *.cmd to CRLF. The raw
 * shapes carry it in the template's own .gitattributes; the Laravel shapes
 * append it to the skeleton's file (or create one when the skeleton ships
 * none).
 */
final class GitattributesPinsTest extends TestCase
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
    public function testEveryShapePinsTheLineEndings(string $shape): void
    {
        $target = $this->makeTempDir();
        $this->bake($shape, $target);

        self::assertFileExists($target . '/.gitattributes', $shape);
        $content = (string) file_get_contents($target . '/.gitattributes');

        foreach ([
            '*.sh text eol=lf',
            '*.ps1 text eol=crlf',
            '*.bat text eol=crlf',
            '*.cmd text eol=crlf',
        ] as $pin) {
            self::assertSame(1, substr_count($content, $pin), $shape . ': ' . $pin);
        }
    }

    public function testLaravelBakeKeepsASkeletonPinOnceAndCreatesTheFileWhenMissing(): void
    {
        // A skeleton that already pins *.sh keeps the line exactly once.
        $skeleton = $this->makeTempDir();
        $this->copyDir(__DIR__ . '/fixtures/laravel-skeleton', $skeleton);
        file_put_contents($skeleton . '/.gitattributes', "*.sh text eol=lf\n");
        $target = $this->makeTempDir();
        (new LaravelBladeGenerator(new FixtureSkeletonSource($skeleton)))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false);

        $content = (string) file_get_contents($target . '/.gitattributes');
        self::assertSame(1, substr_count($content, '*.sh text eol=lf'));
        foreach ([
            '*.ps1 text eol=crlf',
            '*.bat text eol=crlf',
            '*.cmd text eol=crlf',
        ] as $pin) {
            self::assertSame(1, substr_count($content, $pin), $pin);
        }

        // A skeleton with no .gitattributes gets one: the CREED every-OS
        // rule first, then the four pins.
        $target = $this->makeTempDir();
        (new LaravelBladeGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, false);

        $content = (string) file_get_contents($target . '/.gitattributes');
        self::assertStringContainsString('* text=auto eol=lf', $content);
        foreach ([
            '*.sh text eol=lf',
            '*.ps1 text eol=crlf',
            '*.bat text eol=crlf',
            '*.cmd text eol=crlf',
        ] as $pin) {
            self::assertSame(1, substr_count($content, $pin), $pin);
        }
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

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $from . '/' . $entry;
            if (is_dir($path)) {
                $this->copyDir($path, $to . '/' . $entry);
            } else {
                copy($path, $to . '/' . $entry);
            }
        }
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
