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
 * Sign-in belongs to SESAME: the adapter registers GET /login and POST
 * /logout, and the kit's own auth routes would be a second door. A later
 * skeleton or kit change that brings Fortify, Breeze, Jetstream or the
 * registration, password reset, email verification and two-factor routes
 * back must fail here. Static checks on purpose: artisan route:list needs
 * a composer install, which the unit suite avoids.
 */
final class KitRoutesAbsentTest extends TestCase
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
    public function testNoLaravelShapeShipsAKitOrItsAuthRoutes(string $generator): void
    {
        $target = $this->makeTempDir();
        (new $generator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        // No starter kit: the shapes are a bare skeleton with the SESAME
        // door on top, so none of the kit's packages may be required.
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['laravel/fortify', 'laravel/breeze', 'laravel/jetstream'] as $kit) {
            self::assertArrayNotHasKey($kit, $composer['require'] ?? [], $kit);
            self::assertArrayNotHasKey($kit, $composer['require-dev'] ?? [], $kit);
        }

        // The template's comment block says "the adapter registers GET
        // /login", so the raw file would match; strip the comments first.
        $web = $this->stripComments((string) file_get_contents($target . '/routes/web.php'));
        $lowered = strtolower($web);
        foreach ([
            'register',
            'forgot-password',
            'reset-password',
            'verify-email',
            'email/verify',
            'two-factor',
            'confirm-password',
        ] as $route) {
            self::assertStringNotContainsString($route, $lowered, $route);
        }
        // SESAME's adapter registers /login and /logout, so the file defines
        // neither itself.
        self::assertStringNotContainsString('/login', $lowered, '/login');
        self::assertStringNotContainsString('logout', $lowered, 'logout');

        // No separate auth route file.
        self::assertFileDoesNotExist($target . '/routes/auth.php');

        // Guests still land on SESAME's door, pinned to the template's line.
        $bootstrap = (string) file_get_contents($target . '/bootstrap/app.php');
        self::assertStringContainsString("redirectGuestsTo('/login')", $bootstrap);
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
