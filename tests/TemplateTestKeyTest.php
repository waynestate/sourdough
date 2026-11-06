<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The generated TestCase skips .env loading, so a fresh app has no APP_KEY
 * anywhere. The templates must give the tests one of their own, or
 * config('app.key') stays empty and every encrypted request throws
 * MissingAppKeyException. That key is a well-known dummy, so the app must
 * also refuse to boot on it in production.
 */
final class TemplateTestKeyTest extends TestCase
{
    private const TEMPLATES = ['laravel-blade', 'laravel-inertia'];

    public function testSkipEnvFileProvidesTheDummyAppKey(): void
    {
        foreach (self::TEMPLATES as $template) {
            $file = __DIR__ . '/../templates/' . $template . '/tests/Support/SkipEnvFile.php';
            self::assertFileExists($file);
            $content = (string) file_get_contents($file);

            self::assertStringContainsString('APP_KEY', $content, $file);
            self::assertStringContainsString('DummyAppKey::VALUE', $content, $file);
        }
    }

    public function testProductionRefusesTheDummyAppKey(): void
    {
        foreach (self::TEMPLATES as $template) {
            $base = __DIR__ . '/../templates/' . $template;
            $guard = (string) file_get_contents($base . '/app/Support/DummyAppKey.php');
            $provider = (string) file_get_contents($base . '/app/Providers/AppServiceProvider.php');

            // 32 zero bytes, base64: a valid AES-256 key nobody would choose.
            self::assertStringContainsString("'base64:" . base64_encode(str_repeat("\0", 32)) . "'", $guard, $template);
            self::assertStringContainsString("'production'", $guard, $template);
            self::assertStringContainsString("DummyAppKey::refuseIn(\$this->app->environment(), config('app.key'));", $provider, $template);
        }
    }

    public function testTheGuardBehaves(): void
    {
        require_once __DIR__ . '/../templates/laravel-inertia/app/Support/DummyAppKey.php';

        $class = 'App\\Support\\DummyAppKey';
        $value = $class::VALUE;

        // Testing and local runs keep the dummy; production with a real key boots.
        $class::refuseIn('testing', $value);
        $class::refuseIn('local', $value);
        $class::refuseIn('production', 'base64:' . base64_encode(random_bytes(32)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY is the dummy test key. Run php artisan key:generate and set a real APP_KEY.');
        $class::refuseIn('production', $value);
    }
}
