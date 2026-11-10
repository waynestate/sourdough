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
 *
 * The plan also knows what the developer types after the bake (the Next
 * steps `sourdough new` prints and `--dry-run --json` reports), so the
 * front page's order ticket reads them from here too and never keeps a
 * second copy. One starter, one recipe card.
 */
final class Plan
{
    /**
     * @param list<string> $steps     what the bake does
     * @param list<string> $nextSteps what to run after it, in order: shell lines, plus notes in parentheses
     */
    public function __construct(
        public readonly Permutation $permutation,
        public readonly string $dir,
        public readonly array $steps,
        public readonly array $nextSteps = [],
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

        return new self($permutation, $dir, $steps, self::nextSteps($permutation, $dir, $advisor, $sail, $docker));
    }

    /**
     * What to run after the bake, for the shape and the oven. Lines in
     * parentheses are notes, not commands.
     *
     * @return list<string>
     */
    private static function nextSteps(Permutation $permutation, string $dir, bool $advisor, bool $sail, bool $docker): array
    {
        $lines = [sprintf('cd %s', $dir), 'composer install'];
        if ($advisor) {
            $lines[] = 'php vendor/bin/advisor install --client=claude --write';
        }

        $frontEnd = Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation;

        if ($docker) {
            array_push(
                $lines,
                'cp .env.example .env',
                'composer dev-up',
                'docker compose exec app php artisan key:generate',
                'docker compose exec app php artisan migrate',
            );
            if ($frontEnd) {
                $lines[] = '(Vite runs as its own service on port 5173)';
            }

            return $lines;
        }

        if ($sail) {
            array_push(
                $lines,
                'cp .env.example .env',
                './vendor/bin/sail up -d',
                './vendor/bin/sail artisan key:generate',
                './vendor/bin/sail artisan migrate',
            );
            if ($frontEnd) {
                array_push($lines, './vendor/bin/sail npm install', './vendor/bin/sail npm run dev');
            }
            $lines[] = '(Windows: run sail from WSL2, or use --docker)';

            return $lines;
        }

        return match ($permutation) {
            Permutation::LaravelBlade => [...$lines, 'cp .env.example .env', 'php artisan key:generate', 'php artisan migrate', 'php artisan serve'],
            Permutation::LaravelBladeVue, Permutation::LaravelInertia => [...$lines, 'cp .env.example .env', 'php artisan key:generate', 'php artisan migrate', 'npm install', 'npm run build', 'php artisan serve'],
            // The same composer serve as raw, after Vite has built the Vue
            // app once; npm run dev in a second terminal adds hot reload.
            Permutation::RawVue => [...$lines, 'npm install', 'npm run build', 'composer serve', '(npm run dev in a second terminal for hot reload)'],
            // composer serve sets APP_ENV=local for the raw app, which has no
            // .env loader; a plain php -S would boot as production.
            Permutation::Raw => [...$lines, 'composer serve'],
        };
    }
}
