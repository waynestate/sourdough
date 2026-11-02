<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

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
}
