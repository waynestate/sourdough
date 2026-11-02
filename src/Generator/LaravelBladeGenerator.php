<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Generate a Laravel + Blade app: the laravel-blade overlay over a fresh
 * Laravel skeleton. See LaravelOverlayGenerator for the shared steps.
 */
final class LaravelBladeGenerator extends LaravelOverlayGenerator
{
    public function __construct(
        SkeletonSource $skeleton = new ComposerSkeletonSource(),
        string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        parent::__construct(__DIR__ . '/../../templates/laravel-blade', $skeleton, $squireDir);
    }

    protected function templateName(): string
    {
        return 'laravel-blade';
    }
}
