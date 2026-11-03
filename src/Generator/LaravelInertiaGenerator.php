<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Generate a Laravel + Inertia/Vue app: the laravel-inertia overlay over a
 * fresh Laravel skeleton. The overlay ships the Vue front end (pages,
 * layouts, components, the Vite build) and wires Inertia and Ziggy into
 * the composer.json merge. The skeleton's JS leftovers are dropped before
 * the manifest is written (see removeSkeletonLeftovers). See
 * LaravelOverlayGenerator for the shared steps.
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
     * The Inertia shape builds its front end from resources/js/app.ts with
     * the overlay's vite.config.ts, so the skeleton's JS twins are dead
     * weight: vite.config.js next to the TypeScript config breaks
     * npm run build, resources/js/app.js is never imported, and
     * welcome.blade.php names the never-built app.js while no route
     * renders it. They are dropped here, before the manifest is written,
     * so the manifest never lists a file that is gone.
     */
    protected function removeSkeletonLeftovers(string $targetDir): void
    {
        foreach ([
            'vite.config.js',
            'resources/js/app.js',
            'resources/views/welcome.blade.php',
        ] as $leftover) {
            $path = $targetDir . '/' . $leftover;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
