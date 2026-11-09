<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

/**
 * What adopting found for one template path in an existing app. Every path
 * a fresh bake of the shape writes gets exactly one; files the template
 * does not ship get none and are never read.
 */
enum AdoptStatus: string
{
    /** The app's file is the template's, line endings aside: managed from now on. */
    case Match = 'match';

    /** The app has its own version: recorded as yours, never overwritten. */
    case Yours = 'yours';

    /** A file every Sourdough app owns (README.md, .env.example): recorded as seeded. */
    case Seeded = 'seeded';

    /** The app has no file there: left out of the manifest, so the next feed adds it. */
    case Missing = 'missing';

    /**
     * The class the manifest records for this status, or null when the path
     * stays out of the manifest.
     */
    public function manifestClass(): ?string
    {
        return match ($this) {
            self::Match => 'managed',
            self::Yours => 'yours',
            self::Seeded => 'seeded',
            self::Missing => null,
        };
    }
}
