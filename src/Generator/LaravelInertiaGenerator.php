<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\PlaybookMode;

/**
 * Generate a Laravel + Inertia/Vue app: the laravel-inertia overlay over a
 * fresh Laravel skeleton. The overlay ships the Vue front end (pages,
 * layouts, components, the Vite build) and wires Inertia and Ziggy into
 * the composer.json merge. See LaravelOverlayGenerator for the shared
 * steps.
 */
final class LaravelInertiaGenerator extends LaravelOverlayGenerator
{
    public function __construct(
        SkeletonSource $skeleton = new ComposerSkeletonSource(),
        string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        parent::__construct(__DIR__ . '/../../templates/laravel-inertia', $skeleton, $squireDir);
    }

    protected function templateName(): string
    {
        return 'laravel-inertia';
    }

    /**
     * {@inheritDoc}
     *
     * After the shared steps, the skeleton's vite.config.js is removed when
     * it survived the overlay: next to the overlay's vite.config.ts it
     * breaks npm run build, and the TypeScript file wins.
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Suggest, bool $advisor = true): void
    {
        parent::generate($targetDir, $name, $answers, $playbook, $advisor);

        $jsConfig = $targetDir . '/vite.config.js';
        if (is_file($jsConfig)) {
            @unlink($jsConfig);
        }
    }
}
