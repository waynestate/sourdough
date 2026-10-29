<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * The stack parts a Sourdough app is wired with.
 *
 * These are the other projects in the stack that the raw template already
 * includes: SESAME (auth), HELMET (baseline runtime), PROCTOR (checks) and the
 * Preflight CI components. A plan's `parts` is a subset of these.
 */
enum Part: string
{
    case Sesame = 'sesame';
    case Helmet = 'helmet';
    case Proctor = 'proctor';
    case Preflight = 'preflight';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $part): string => $part->value, self::cases());
    }
}
