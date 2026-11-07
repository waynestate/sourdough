<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Every baked app pins config.platform.php to the floor of its
 * require.php, so the lock resolves against the app's PHP and PROCTOR's
 * ComposerConfigCheck does not flag it ("require.php is set but
 * config.platform.php is not"). The Laravel shapes bake on the fixture
 * skeleton, so the test never touches the network.
 */
final class TemplatePlatformPinTest extends TestCase
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

    public function testEveryTemplatePinsThePhpPlatform(): void
    {
        foreach ($this->shapes() as [$shape, $generator]) {
            $target = $this->makeTempDir();
            $generator->generate($target, 'My Cool App', [], PlaybookMode::Suggest);

            $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('php', $composer['require'], "The $shape app requires php.");
            $floor = $this->phpFloor($composer['require']['php']);
            self::assertSame(
                $floor,
                $composer['config']['platform']['php'] ?? null,
                "The $shape app pins config.platform.php to the floor of its require.php ($floor), so the lock resolves against the app's PHP, not the developer's.",
            );
        }
    }

    /**
     * The five shapes and their generators, all pointed at the real
     * templates (the Laravel ones on the fixture skeleton).
     *
     * @return list<array{string, RawGenerator|RawVueGenerator|LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator}>
     */
    private function shapes(): array
    {
        $skeleton = new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');

        return [
            ['raw', new RawGenerator()],
            ['raw-vue', new RawVueGenerator()],
            ['laravel-blade', new LaravelBladeGenerator($skeleton)],
            ['laravel-blade-vue', new LaravelBladeVueGenerator($skeleton)],
            ['laravel-inertia', new LaravelInertiaGenerator($skeleton)],
        ];
    }

    /**
     * The floor of a php constraint: ^8.3 and >=8.3 both mean 8.3.0.
     */
    private function phpFloor(string $constraint): string
    {
        if (1 !== preg_match('/\d+\.\d+(?:\.\d+)?/', $constraint, $matches)) {
            self::fail('No version in the php constraint "' . $constraint . '".');
        }
        $parts = explode('.', $matches[0]);
        if (2 === \count($parts)) {
            $parts[] = '0';
        }

        return implode('.', $parts);
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
