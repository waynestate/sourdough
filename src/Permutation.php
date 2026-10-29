<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * The five ways a Wayne State app can be built. Every project is a framework-free core with thin adapters, and every permutation must work.
 *
 * The flags come from `sourdough new`: --raw, --laravel, --vue and --inertia.
 * --inertia implies --laravel: Inertia here means Laravel. Combinations that
 * make no sense are refused with an InvalidArgumentException that names the
 * five shapes and their flags.
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
        // Inertia here means Laravel: --inertia implies --laravel.
        $laravel = $laravel || $inertia;

        if ($raw && $laravel) {
            throw new \InvalidArgumentException(
                '--raw and --laravel are mutually exclusive: pick one base. ' . self::shapeList(),
            );
        }

        if ($vue && $inertia) {
            throw new \InvalidArgumentException(
                '--vue is not needed with --inertia: Inertia already means Vue. ' . self::shapeList(),
            );
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
     * The five shapes and the flags that select each, for error messages.
     */
    private static function shapeList(): string
    {
        $rows = [
            '(none or --raw)' => self::Raw,
            '--raw --vue' => self::RawVue,
            '--laravel' => self::LaravelBlade,
            '--laravel --vue' => self::LaravelBladeVue,
            '--laravel --inertia (or --inertia)' => self::LaravelInertia,
        ];

        $parts = [];
        foreach ($rows as $flags => $shape) {
            $parts[] = $flags . ' ' . $shape->label();
        }

        return 'The five shapes are: ' . implode(', ', $parts) . '.';
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
