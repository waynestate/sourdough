<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * The five ways a Wayne State app can be built (ADR-0001).
 *
 * The flags come from `sourdough new`: --raw, --laravel, --vue and --inertia.
 * Combinations that make no sense are refused with an InvalidArgumentException.
 */
enum Permutation: string
{
    case Raw = 'raw';
    case RawVue = 'raw-vue';
    case LaravelBlade = 'laravel-blade';
    case LaravelBladeVue = 'laravel-blade-vue';
    case LaravelInertia = 'laravel-inertia';

    /**
     * Resolve the permutation from the new command flags.
     *
     * @throws \InvalidArgumentException when the flags make no sense
     */
    public static function fromFlags(bool $raw, bool $laravel, bool $vue, bool $inertia): self
    {
        if ($inertia && !$laravel) {
            throw new \InvalidArgumentException('--inertia needs --laravel: Inertia is a Laravel package.');
        }

        if ($raw && $laravel) {
            throw new \InvalidArgumentException('--raw and --laravel are mutually exclusive: pick one base.');
        }

        if ($vue && $inertia) {
            throw new \InvalidArgumentException('--vue is not needed with --inertia: Inertia already means Vue.');
        }

        if ($laravel) {
            return match (true) {
                $inertia => self::LaravelInertia,
                $vue => self::LaravelBladeVue,
                default => self::LaravelBlade,
            };
        }

        return $vue ? self::RawVue : self::Raw;
    }

    /**
     * The human name, from the stack's permutation table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw PHP with WayneUI\'s standalone path',
            self::RawVue => 'Raw PHP + Vue SPA over JSON',
            self::LaravelBlade => 'Laravel + Blade',
            self::LaravelBladeVue => 'Laravel + Blade + Vue islands',
            self::LaravelInertia => 'Laravel + Inertia/Vue',
        };
    }
}
