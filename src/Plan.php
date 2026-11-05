<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * What `sourdough new` will do, as a list of plain steps.
 *
 * The plan is built from the permutation, the target dir, the local
 * runtime flags (--sail/--docker) and whether ADVISOR is wired in
 * (--no-advisor opts out). In 0.1 the raw, Laravel + Blade, Laravel +
 * Blade + Vue islands and Laravel + Inertia/Vue permutations are
 * generated; the generation step for the others says "not available in
 * this release".
 */
final class Plan
{
    /**
     * @param list<string> $steps
     */
    public function __construct(
        public readonly Permutation $permutation,
        public readonly string $dir,
        public readonly array $steps,
    ) {}

    /**
     * @throws \InvalidArgumentException when --sail and --docker are both set, or when --sail or --docker is combined with a raw permutation
     */
    public static function forPermutation(Permutation $permutation, string $dir, bool $sail, bool $docker, bool $advisor = true): self
    {
        if ($sail && $docker) {
            throw new \InvalidArgumentException('Choose one of --sail or --docker, not both.');
        }

        $isRaw = $permutation === Permutation::Raw || $permutation === Permutation::RawVue;

        if ($isRaw && ($sail || $docker)) {
            throw new \InvalidArgumentException(
                '--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.',
            );
        }

        $steps = [];

        $generatable = $permutation === Permutation::Raw || $permutation === Permutation::LaravelBlade || $permutation === Permutation::LaravelBladeVue || $permutation === Permutation::LaravelInertia;

        if ($permutation === Permutation::Raw) {
            $steps[] = sprintf('Copy the raw template into %s', $dir);
            $steps[] = 'Replace {{app_name}} and {{app_slug}} in file names and contents';
        } else {
            $suffix = $generatable ? '' : ' (not available in this release)';
            $steps[] = sprintf('Create the %s app in %s%s', $permutation->label(), $dir, $suffix);
        }

        // ADVISOR is wired in as part of generation, so the step only shows
        // for the permutations this release actually generates.
        if ($generatable && $advisor) {
            $steps[] = 'Wire ADVISOR into the app';
        }

        if ($sail) {
            $steps[] = 'Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)';
        }

        if ($docker) {
            $steps[] = 'Use Docker for the local runtime';
        }

        return new self($permutation, $dir, $steps);
    }
}
