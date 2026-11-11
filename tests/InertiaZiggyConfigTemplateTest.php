<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Boost registers its routes (boost.*) only where the dev package is
 * installed, so the committed Ziggy route list differs between a laptop
 * and CI unless the template excepts them. The laravel-inertia overlay
 * must ship config/ziggy.php with the boost.* wildcard and a Pest test
 * that pins it, so every baked app generates one answer everywhere.
 */
final class InertiaZiggyConfigTemplateTest extends TestCase
{
    public function testTheInertiaTemplateExceptsBoostRoutesFromZiggy(): void
    {
        $configPath = dirname(__DIR__) . '/templates/laravel-inertia/config/ziggy.php';
        $this->assertFileExists($configPath, 'the laravel-inertia template is missing config/ziggy.php');

        $source = file_get_contents($configPath);
        $this->assertNotFalse($source, $configPath . ' could not be read');

        // The config keeps Ziggy's defaults and adds the except list.
        $this->assertStringContainsString(
            "'except'",
            $source,
            $configPath . ' does not set Ziggy\'s except key',
        );

        // Boost's routes are named boost.*, so the wildcard names them all.
        $this->assertStringContainsString(
            'boost.*',
            $source,
            $configPath . ' does not except Boost\'s boost.* routes',
        );

        $pestPath = dirname(__DIR__) . '/templates/laravel-inertia/tests/Feature/ZiggyConfigTest.php';
        $this->assertFileExists(
            $pestPath,
            'the laravel-inertia template is missing tests/Feature/ZiggyConfigTest.php',
        );
    }
}
