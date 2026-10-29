<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Where a Laravel shape's base app comes from.
 *
 * The real source runs `composer create-project laravel/laravel`; tests use a
 * fixture copy, so they never touch the network.
 */
interface SkeletonSource
{
    /**
     * Put a fresh Laravel skeleton into $targetDir. The dir must not exist or
     * must be empty.
     *
     * @throws \RuntimeException when the skeleton cannot be created
     */
    public function create(string $targetDir): void;
}
