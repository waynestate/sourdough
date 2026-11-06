<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * What `sourdough new` will do, as a list of plain steps.
 *
 * The plan is built from the permutation, the target dir, the local
 * runtime flags (--sail/--docker) and whether ADVISOR is wired in
 * (--no-advisor opts out). Every permutation generates: raw and raw + Vue
 * copy their templates, the Laravel ones create the app over a fresh
 * Laravel skeleton.
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

        if ($permutation === Permutation::Raw) {
            $steps[] = sprintf('Copy the raw template into %s', $dir);
            $steps[] = 'Replace {{app_name}} and {{app_slug}} in file names and contents';
        } elseif ($permutation === Permutation::RawVue) {
            $steps[] = sprintf('Copy the raw template, then the raw-vue template over it, into %s', $dir);
            $steps[] = 'Replace {{app_name}} and {{app_slug}} in file names and contents';
        } else {
            $steps[] = sprintf('Create the %s app in %s', $permutation->label(), $dir);
        }

        if ($advisor) {
            $steps[] = 'Wire ADVISOR into the app';
        }

        if ($sail) {
            $steps[] = 'Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)';
        }

        if ($docker) {
            $steps[] = 'Use Docker for the local runtime (Dockerfile, compose.yaml, MySQL 8.4)';
        }

        return new self($permutation, $dir, $steps);
    }
}
