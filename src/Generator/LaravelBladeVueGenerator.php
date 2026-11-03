<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Bake the Laravel + Blade + Vue islands shape: the laravel-blade overlay
 * with the islands front end layered over it (package.json, vite.config.js,
 * resources/js, the island views). Blade renders the page; Vue mounts each
 * element that carries a data-vue-island attribute, built by Vite. No
 * Inertia, no Ziggy: the page stays a Blade template. See
 * LaravelOverlayGenerator for the shared steps.
 */
final class LaravelBladeVueGenerator extends LaravelOverlayGenerator
{
    public function __construct(
        SkeletonSource $skeleton = new ComposerSkeletonSource(),
        string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        parent::__construct(__DIR__ . '/../../templates/laravel-blade', $skeleton, $squireDir, __DIR__ . '/../../templates/laravel-blade-vue');
    }

    protected function templateName(): string
    {
        return 'laravel-blade-vue';
    }
}
