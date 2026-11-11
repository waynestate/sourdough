<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Sourdough's own smoke jobs bake on PHP 8.5, but the apps they bake pin
 * config.platform.php to the floor (CREED's oldest supported line). The
 * smoke-floor job bakes the raw and Inertia shapes on that floor, and this
 * test keeps its image on the templates' pin: when the floor moves, the
 * job moves with it or the suite says so. A starter that only rises in a
 * warm kitchen is not much of a starter.
 */
final class SmokeFloorTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testTheFloorJobBakesOnTheTemplatesPlatformPin(): void
    {
        $floor = $this->templateFloor();
        $job = $this->job('smoke-floor');

        self::assertStringContainsString("\n  extends: .smoke-base\n", $job, 'smoke-floor reuses the smoke tools and cache');
        self::assertStringContainsString(
            "\n  image: \${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/php:" . $floor . "-cli-trixie\n",
            $job,
            'smoke-floor runs on PHP ' . $floor . ', the floor every template pins in config.platform.php',
        );
        self::assertStringContainsString('- sh scripts/smoke-shape.sh raw' . "\n", $job, 'the floor bakes the raw shape');
        self::assertStringContainsString('- sh scripts/smoke-shape.sh inertia' . "\n", $job, 'the floor bakes the Inertia shape');
        self::assertStringNotContainsString('allow_failure', $job, 'the floor blocks, like every other smoke job');
    }

    public function testTheOtherSmokeJobsStayOnTheNewestPhp(): void
    {
        $base = $this->job('.smoke-base');
        self::assertStringContainsString('/php:8.5-cli-trixie', $base);
        self::assertNotSame($this->templateFloor(), '8.5', 'the floor job only means something while the floor is older than 8.5');
    }

    /** The major.minor every template pins in config.platform.php, the same in all of them. */
    private function templateFloor(): string
    {
        $files = [
            self::ROOT . '/templates/raw/composer.json',
            self::ROOT . '/templates/laravel-blade/composer.overlay.json',
            self::ROOT . '/templates/laravel-inertia/composer.overlay.json',
        ];
        $floors = [];
        foreach ($files as $file) {
            $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($json);
            $pin = $json['config']['platform']['php'] ?? null;
            self::assertIsString($pin, basename(dirname($file)) . ' pins config.platform.php');
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $pin);
            $floors[implode('.', array_slice(explode('.', $pin), 0, 2))] = true;
        }
        self::assertCount(1, $floors, 'every template pins the same floor');

        return (string) array_key_first($floors);
    }

    /** One top-level job from .gitlab-ci.yml, from its name line to the next top-level key. */
    private function job(string $name): string
    {
        $ci = str_replace("\r\n", "\n", (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml'));
        $found = preg_match('/^' . preg_quote($name, '/') . ":\n((?:[ #][^\n]*\n|\n)*)/m", $ci, $m);
        self::assertSame(1, $found, '.gitlab-ci.yml has a ' . $name . ' job');

        return "\n" . $m[1];
    }
}
