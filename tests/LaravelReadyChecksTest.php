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
 * WELD W11 in every Laravel shape: /up stays liveness and never looks at the
 * database, and /ready (HELMET's readiness route) checks the database and
 * the cache as well as the scheduler heartbeat (W23). It is registered in
 * bootstrap/app.php, outside the web group, as HELMET's readiness guide
 * asks, so a probe writes no session row and sets no cookie. The baked
 * app's own tests/Feature/ReadyChecksTest.php boots it and breaks each
 * dependency; this suite checks the baked tree statically, because running
 * the app's Pest suite needs a composer install (the smoke gate does that).
 * WELD copies the Inertia shape byte for byte, so the Blade and Inertia
 * copies must stay identical.
 */
final class LaravelReadyChecksTest extends TestCase
{
    /** The files both Laravel overlays carry, byte for byte. */
    private const SHARED_FILES = [
        'app/Health/CacheReady.php',
        'app/Health/DatabaseReady.php',
        'app/Health/SchedulerHeartbeat.php',
        'tests/Feature/ReadyChecksTest.php',
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
    public function testReadyChecksTheDatabaseTheCacheAndTheScheduler(string $generator): void
    {
        $target = $this->bake($generator);

        foreach (self::SHARED_FILES as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        $bootstrap = $this->stripComments((string) file_get_contents($target . '/bootstrap/app.php'));

        // One readiness route, in withRouting()'s then: callback, which
        // Laravel runs outside the web group. /up stays Laravel's own
        // health route.
        self::assertSame(1, substr_count($bootstrap, 'Route::helmetReady('), 'one /ready route');
        self::assertSame(
            1,
            preg_match("/then:\\s*function\\s*\\(\\)\\s*\\{\\s*Route::helmetReady\\('\\/ready',\\s*\\[(.*?)\\]\\);/s", $bootstrap, $matches),
            "Route::helmetReady('/ready', [...]) inside then:",
        );
        self::assertStringContainsString("health: '/up'", $bootstrap);

        preg_match_all("/'([a-z_]+)'\\s*=>\\s*([A-Za-z]+)::class/", $matches[1] ?? '', $checks, PREG_SET_ORDER);
        $named = [];
        foreach ($checks as $check) {
            $named[$check[1]] = $check[2];
        }
        self::assertSame(
            ['database' => 'DatabaseReady', 'cache' => 'CacheReady', 'scheduler' => 'SchedulerHeartbeat'],
            $named,
        );
        foreach ($named as $class) {
            self::assertStringContainsString('use App\\Health\\' . $class . ';', $bootstrap, $class);
        }

        // Not in the web group: routes/web.php no longer registers it.
        $web = $this->stripComments((string) file_get_contents($target . '/routes/web.php'));
        self::assertStringNotContainsString('helmetReady', $web);
        self::assertStringNotContainsString('SchedulerHeartbeat', $web);

        // The checks answer with a bool and let HELMET log a throw, so no
        // address, path or message reaches the public body.
        $database = (string) file_get_contents($target . '/app/Health/DatabaseReady.php');
        self::assertStringContainsString('DB::connection()->getPdo();', $database);
        $cache = (string) file_get_contents($target . '/app/Health/CacheReady.php');
        self::assertStringContainsString('$store->put($key, true, 10);', $cache);
        self::assertStringContainsString('$store->forget($key);', $cache);

        // The app's own test breaks the database and the cache in turn.
        $test = (string) file_get_contents($target . '/tests/Feature/ReadyChecksTest.php');
        self::assertStringContainsString("it('answers 503 on /ready when the database cannot be reached'", $test);
        self::assertStringContainsString("it('answers 503 on /ready when the cache store fails'", $test);
        self::assertStringContainsString("it('serves /ready outside the web group, with no session cookie'", $test);
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

    /**
     * Drop T_COMMENT and T_DOC_COMMENT tokens, keep everything else.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $out .= $token[1];
                }
            } else {
                $out .= (string) $token;
            }
        }

        return $out;
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
