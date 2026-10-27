<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;

final class RawGeneratorTest extends TestCase
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

    public function testGeneratesAppWithReplacedNamesAndSlugs(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        self::assertFileExists($target . '/README.md');
        self::assertFileExists($target . '/my-cool-app.txt');
        self::assertFileExists($target . '/config/my-cool-app.ini');

        self::assertSame("Welcome to My Cool App (my-cool-app).\n", file_get_contents($target . '/README.md'));
        self::assertSame("Slug file for My Cool App.\n", file_get_contents($target . '/my-cool-app.txt'));
        self::assertSame("app=My Cool App\n", file_get_contents($target . '/config/my-cool-app.ini'));

        // No placeholder survives anywhere in the generated tree.
        foreach ($this->allFiles($target) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('{{app_name}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', basename($file), $file);
        }
    }

    public function testRefusesNonEmptyTarget(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testRefusesTargetThatIsAFile(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/a-file';
        file_put_contents($target, 'i am a file');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a file, not a directory');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testWritesNothingOutsideTheTarget(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/app';

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        // The parent should contain only the target dir, nothing else.
        $entries = array_values(array_diff(scandir($parent) ?: [], ['.', '..']));
        self::assertSame(['app'], $entries);
    }

    public function testMissingTemplateDirThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new RawGenerator($target . '/no-such-template'))->generate($target, 'My Cool App');
    }

    public function testNameWithNoValidSlugThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not produce a valid slug');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, '!!!');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield 'plain' => ['My App', 'my-app'];
        yield 'upper case' => ['MY APP', 'my-app'];
        yield 'spaces' => ['My  App', 'my-app'];
        yield 'underscores' => ['my_app', 'my-app'];
        yield 'dots' => ['my.app', 'my-app'];
        yield 'keeps dashes' => ['my-app', 'my-app'];
        yield 'keeps numbers' => ['app2', 'app2'];
        yield 'trims leading dash' => ['-my-app', 'my-app'];
        yield 'trims trailing dash' => ['my-app-', 'my-app'];
        yield 'slashes' => ['my/app', 'my-app'];
    }

    #[DataProvider('slugs')]
    public function testSlugify(string $name, string $expected): void
    {
        self::assertSame($expected, RawGenerator::slugify($name));
    }

    private function fixtureTemplateDir(): string
    {
        return __DIR__ . '/fixtures/template';
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function allFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->allFiles($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
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
