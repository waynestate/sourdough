<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The generated TestCase skips .env loading, so a fresh app has no APP_KEY
 * anywhere. The templates must give the tests one of their own, or
 * config('app.key') stays empty and every encrypted request throws
 * MissingAppKeyException.
 */
final class TemplateTestKeyTest extends TestCase
{
    public function testSkipEnvFileProvidesAThrowawayAppKey(): void
    {
        foreach ([
            __DIR__ . '/../templates/laravel-blade/tests/Support/SkipEnvFile.php',
            __DIR__ . '/../templates/laravel-inertia/tests/Support/SkipEnvFile.php',
        ] as $file) {
            self::assertFileExists($file);
            $content = (string) file_get_contents($file);

            self::assertStringContainsString('APP_KEY', $content, $file);
            self::assertStringContainsString('base64_encode', $content, $file);
        }
    }
}
