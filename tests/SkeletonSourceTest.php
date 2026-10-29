<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Waynestate\Sourdough\Generator\ComposerSkeletonSource;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;

final class SkeletonSourceTest extends TestCase
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

    public function testFixtureSourceCopiesEveryFileWithIdenticalContent(): void
    {
        $target = $this->makeTempDir();

        (new FixtureSkeletonSource($this->fixtureDir()))->create($target);

        $expected = $this->relativeFiles($this->fixtureDir());
        self::assertSame($expected, $this->relativeFiles($target));

        // The trimmed skeleton keeps its load-bearing files.
        foreach ([
            'composer.json',
            'artisan',
            '.env.example',
            'app/Models/User.php',
            'bootstrap/app.php',
            'public/index.php',
            'routes/web.php',
        ] as $path) {
            self::assertContains($path, $expected, $path);
        }

        foreach ($expected as $path) {
            self::assertFileEquals($this->fixtureDir() . '/' . $path, $target . '/' . $path, $path);
        }
    }

    public function testFixtureSourceRefusesNonEmptyTarget(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        (new FixtureSkeletonSource($this->fixtureDir()))->create($target);
    }

    public function testComposerSourceRefusesNonEmptyTargetWithoutRunningComposer(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        (new ComposerSkeletonSource())->create($target);
    }

    public function testComposerSourceBuildsTheCreateProjectArguments(): void
    {
        $target = $this->makeTempDir();

        $command = $this->composerCommand(new ComposerSkeletonSource(), $target);
        self::assertIsArray($command);

        self::assertSame([
            'composer',
            'create-project',
            'laravel/laravel:^13.0',
            $target,
            '--no-install',
            '--no-scripts',
            '--no-interaction',
            '--prefer-dist',
        ], $command);
    }

    public function testComposerSourceHonoursTheSourDoughComposerEnvVar(): void
    {
        putenv('SOURDOUGH_COMPOSER=/opt/sourdough/bin/composer');

        try {
            $command = $this->composerCommand(new ComposerSkeletonSource(), '/tmp/app');
            self::assertIsArray($command);
            self::assertSame('/opt/sourdough/bin/composer', $command[0]);
        } finally {
            putenv('SOURDOUGH_COMPOSER');
        }
    }

    public function testComposerSourcePrefersAnInjectedBinaryOverTheEnvVar(): void
    {
        putenv('SOURDOUGH_COMPOSER=/opt/sourdough/bin/composer');

        try {
            $command = $this->composerCommand(new ComposerSkeletonSource('injected-composer'), '/tmp/app');
            self::assertIsArray($command);
            self::assertSame('injected-composer', $command[0]);
        } finally {
            putenv('SOURDOUGH_COMPOSER');
        }
    }

    public function testFixtureSourceMissingFixtureDirThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new FixtureSkeletonSource($target . '/no-such-fixture'))->create($target);
    }

    /**
     * Call the protected command() without running Composer.
     */
    private function composerCommand(ComposerSkeletonSource $source, string $targetDir): mixed
    {
        return (new ReflectionMethod(ComposerSkeletonSource::class, 'command'))->invoke($source, $targetDir);
    }

    private function fixtureDir(): string
    {
        return __DIR__ . '/fixtures/laravel-skeleton';
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Sorted slash-separated paths of every file under $dir.
     *
     * @return list<string>
     */
    private function relativeFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                foreach ($this->relativeFiles($path) as $sub) {
                    $files[] = $entry . '/' . $sub;
                }
            } else {
                $files[] = $entry;
            }
        }
        sort($files);

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
