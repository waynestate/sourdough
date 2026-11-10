<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use Waynestate\Sourdough\Generator\RawGenerator;

/**
 * The menu behind the front page's order ticket (site/assets/order-menu.json).
 *
 * The ticket is the command half of the "new app" page (BEST_PRACTICES 1.8):
 * pick a loaf, an oven and a name, and it writes the `sourdough new` line, the
 * bake's steps and the Next steps. Everything it shows comes from here, and
 * everything here comes from the code `sourdough new --dry-run --json` runs:
 * the shapes and their typed flags (Permutation), which ovens a shape takes
 * (whatever Plan::forPermutation() does not refuse), the steps and next steps
 * (Plan), the support status (resources/shapes.json) and the name rule
 * (RawGenerator::NAME_PATTERN). The page never keeps a second recipe.
 *
 * `php bin/order-menu.php` writes the file; `--check` fails when it is stale,
 * and `composer ci` runs the check. tests/OrderMenuTest.php runs every order
 * the ticket can write through the real command.
 */
final class OrderMenu
{
    public const FILE = __DIR__ . '/../site/assets/order-menu.json';

    /** Where the app's folder goes in steps; the page swaps in the slug. */
    public const DIR = '{dir}';

    /**
     * The ovens, in the order the ticket lists them, with the flag each
     * adds. The bench is no flag: plain `php artisan serve` or `composer serve`.
     *
     * @var array<string, list<string>>
     */
    public const OVENS = [
        'bench' => [],
        'sail' => ['--sail'],
        'docker' => ['--docker'],
    ];

    public const NO_ADVISOR = '--no-advisor';

    /**
     * The menu as data.
     *
     * @return array<string, mixed>
     */
    public static function build(?ShapeSupport $support = null): array
    {
        $support ??= ShapeSupport::load();

        $shapes = [];
        foreach (Permutation::cases() as $shape) {
            $plans = [];
            foreach (self::OVENS as $oven => $flags) {
                $sail = 'sail' === $oven;
                $docker = 'docker' === $oven;
                foreach (['advisor' => true, 'no-advisor' => false] as $key => $advisor) {
                    try {
                        $plan = Plan::forPermutation($shape, self::DIR, $sail, $docker, $advisor);
                    } catch (\InvalidArgumentException) {
                        // The CLI refuses this oven for this shape, so the
                        // ticket never offers it.
                        continue 2;
                    }
                    $plans[$oven][$key] = ['steps' => $plan->steps, 'next_steps' => $plan->nextSteps];
                }
            }

            $entry = $support->entry($shape->value);
            $shapes[$shape->value] = [
                'label' => $shape->label(),
                'flags' => $shape->cliFlags(),
                'ovens' => array_keys($plans),
                'status' => $entry['status'] ?? ShapeSupport::STATUS_SUPPORTED,
                'notice' => $support->notice($shape->value),
                'plans' => $plans,
            ];
        }

        return [
            'about' => 'Written by bin/order-menu.php from the code sourdough new --dry-run --json runs. Do not edit; run composer site:order-menu.',
            'dir' => self::DIR,
            'name' => [
                'pattern' => self::jsPattern(RawGenerator::NAME_PATTERN),
                'rule' => 'Start with a letter or number; then letters, numbers, spaces, dots, dashes and underscores, at most 63 characters.',
            ],
            'ovens' => self::OVENS,
            'no_advisor' => self::NO_ADVISOR,
            'shapes' => $shapes,
        ];
    }

    /**
     * The menu as the file holds it: pretty JSON with a final newline.
     */
    public static function encode(?ShapeSupport $support = null): string
    {
        return json_encode(self::build($support), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * A PCRE pattern of the plain kind NAME_PATTERN is (slash delimiters, no
     * flags, ^ at the start and \z at the end) as a JavaScript RegExp source.
     * JavaScript's $ without the m flag is PCRE's \z.
     *
     * @throws \InvalidArgumentException for any other shape of pattern
     */
    public static function jsPattern(string $pcre): string
    {
        if (1 !== preg_match('~^/(\^.*)\\\\z/$~s', $pcre, $m)) {
            throw new \InvalidArgumentException(sprintf('Cannot carry the pattern %s to JavaScript: expected /^...\z/ with no flags.', $pcre));
        }

        return $m[1] . '$';
    }
}
