<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * A freshly baked Laravel app must survive its first run: the views must
 * read the user the way SESAME returns it, the Next steps must build the
 * database before serving, the templates must pass their own Pint, and the
 * islands app must test without a Vite build.
 */
final class LaravelFirstRunTest extends TestCase
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
     * SESAME's Sesame::user() returns an array, so a view that reads ->name
     * on it crashes the page after sign-in.
     *
     * @return iterable<string, array{string}>
     */
    public static function bladeViewShapes(): iterable
    {
        yield 'blade' => ['laravel-blade'];
        yield 'blade-vue' => ['laravel-blade-vue'];
    }

    #[DataProvider('bladeViewShapes')]
    public function testBakedViewsReadTheUserAsAnArray(string $shape): void
    {
        $target = $this->generate($shape);

        foreach ($this->phpFiles($target . '/resources/views') as $file) {
            $content = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\$(user|signedInUser)->name\b/', $content, $file);
        }
    }

    /**
     * The sqlite database and the sessions table exist only after a
     * migration, so the Next steps must run one between the key and the
     * server, in every Laravel shape.
     *
     * @return iterable<string, array{array<string, bool>}>
     */
    public static function laravelShapes(): iterable
    {
        yield 'blade' => [['--laravel' => true]];
        yield 'blade-vue' => [['--laravel' => true, '--vue' => true]];
        yield 'inertia' => [['--inertia' => true]];
    }

    /**
     * @param array<string, bool> $flags
     */
    #[DataProvider('laravelShapes')]
    public function testNextStepsMigrateAfterKeyGenerate(array $flags): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester(new NewCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute(array_merge(['dir' => $target, '--no-playbook' => true], $flags));

        self::assertSame(0, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString('php artisan key:generate', $display);
        self::assertStringContainsString('php artisan migrate', $display);
        self::assertStringContainsString('php artisan serve', $display);

        $key = strpos($display, 'php artisan key:generate');
        $migrate = strpos($display, 'php artisan migrate');
        $serve = strpos($display, 'php artisan serve');
        self::assertIsInt($key);
        self::assertIsInt($migrate);
        self::assertIsInt($serve);
        self::assertLessThan($migrate, $key);
        self::assertLessThan($serve, $migrate);
    }

    /**
     * The skeleton source runs create-project with --no-scripts, so
     * Laravel's post-create-project-cmd never touches
     * database/database.sqlite. The bake must leave the empty file the way
     * Laravel's own installer does, or the printed php artisan migrate
     * stops to ask whether to create the database.
     *
     * @param array<string, bool> $flags
     */
    #[DataProvider('laravelShapes')]
    public function testBakeCreatesTheSqliteDatabaseLikeLaravelDoes(array $flags): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester(new NewCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute(array_merge(['dir' => $target, '--no-playbook' => true], $flags));

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/database/database.sqlite');
        self::assertSame(0, filesize($target . '/database/database.sqlite'));
    }

    public function testBakeLeavesNoSqliteFileWhenTheAppDoesNotUseSqlite(): void
    {
        $target = $this->makeTempDir();
        mkdir($target . '/database');
        file_put_contents($target . '/.env.example', "APP_NAME=Laravel\nDB_CONNECTION=mysql\n");

        $generator = new LaravelBladeGenerator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton'));
        $method = new \ReflectionMethod($generator, 'createSqliteDatabase');
        $method->invoke($generator, $target);

        self::assertFileDoesNotExist($target . '/database/database.sqlite');
    }

    public function testBladeAppSetsTheDefaultStringLength(): void
    {
        $target = $this->generate('laravel-blade');

        self::assertFileExists($target . '/app/Providers/AppServiceProvider.php');
        $provider = (string) file_get_contents($target . '/app/Providers/AppServiceProvider.php');
        self::assertStringContainsString('Schema::defaultStringLength(191)', $provider);
    }

    public function testBladeVueAppTestsWithoutAViteBuild(): void
    {
        $target = $this->generate('laravel-blade-vue');

        self::assertFileExists($target . '/tests/Feature/ExampleTest.php');
        $test = (string) file_get_contents($target . '/tests/Feature/ExampleTest.php');
        self::assertStringContainsString('$this->withoutVite();', $test);
    }

    public function testLaravelTemplatesKeepPintsNonYodaComparisons(): void
    {
        foreach (['laravel-blade', 'laravel-inertia'] as $template) {
            $app = __DIR__ . '/../templates/' . $template . '/app';
            self::assertDirectoryExists($app);

            foreach ($this->phpFiles($app) as $file) {
                $content = (string) file_get_contents($file);
                self::assertDoesNotMatchRegularExpression('/\b(null|true|false)\s*(===|!==)/', $content, $file);
            }
        }
    }

    private function generate(string $shape): string
    {
        $target = $this->makeTempDir();
        $source = new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');
        if ('laravel-blade-vue' === $shape) {
            (new LaravelBladeVueGenerator($source))->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);
        } else {
            (new LaravelBladeGenerator($source))->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);
        }

        return $target;
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
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->phpFiles($path));
            } elseif ('php' === pathinfo($path, PATHINFO_EXTENSION)) {
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
