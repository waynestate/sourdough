<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * What `sourdough new` will do, as a list of plain steps.
 *
 * The plan is built from the permutation, the target dir and the local
 * runtime flags (--sail/--docker). In 0.1 only the raw permutation is
 * generated; the generation step for the other four says "not available in
 * this release" (steps 2 and 3 add them).
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
     * @throws \InvalidArgumentException when --sail or --docker is combined with a raw permutation
     */
    public static function forPermutation(Permutation $permutation, string $dir, bool $sail, bool $docker): self
    {
        $isRaw = $permutation === Permutation::Raw || $permutation === Permutation::RawVue;

        if ($isRaw && ($sail || $docker)) {
            throw new \InvalidArgumentException(
                '--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.',
            );
        }

        $steps = [];

        if ($permutation === Permutation::Raw) {
            $steps[] = sprintf('Copy the raw template into %s', $dir);
            $steps[] = 'Replace {{app_name}} and {{app_slug}} in file names and contents';
        } else {
            $steps[] = sprintf('Create the %s app in %s (not available in this release)', $permutation->label(), $dir);
        }

        if ($sail) {
            $steps[] = 'Use Sail for the local runtime';
        }

        if ($docker) {
            $steps[] = 'Use Docker for the local runtime';
        }

        return new self($permutation, $dir, $steps);
    }
}
