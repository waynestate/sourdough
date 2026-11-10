<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * BEST_PRACTICES 3.6: keep the template thin. HELMET merges its own config
 * (HelmetServiceProvider::register() calls mergeConfigFrom), so the Laravel
 * overlays carry only the app's override, not a copy of the package's file
 * that falls behind it. A copy had already lost HELMET's Playbook lookup
 * for HELMET_PRESET and four newer settings before it was cut back. The app
 * overrides two keys on purpose: `policy_path` (CREED's bundled policy) and
 * `error_pages` (new apps answer every 4xx with HELMET's fixed page).
 */
final class ThinTemplateTest extends TestCase
{
    public function testTheLaravelOverlaysOverrideOnlyTheirOwnHelmetSettings(): void
    {
        foreach (['laravel-blade', 'laravel-inertia'] as $overlay) {
            $path = dirname(__DIR__) . '/templates/' . $overlay . '/config/helmet.php';
            $config = (string) file_get_contents($path);

            self::assertSame(1, preg_match('/^return \[\n(.*?)^\];/ms', $config, $matches), $overlay . ': one return array');
            self::assertSame(2, preg_match_all("/^    '([a-z_]+)' =>/m", $matches[1] ?? '', $keys), $overlay . ': two keys');
            self::assertSame(['policy_path', 'error_pages'], $keys[1], $overlay . ': only the app\'s overrides (policy path, 4xx pages on); the rest comes from the package');
            self::assertStringNotContainsString('{{app_name}}', $config, $overlay . ': nothing app-specific, so WELD can carry the same bytes');
        }

        self::assertFileEquals(
            dirname(__DIR__) . '/templates/laravel-blade/config/helmet.php',
            dirname(__DIR__) . '/templates/laravel-inertia/config/helmet.php',
        );
    }
}
