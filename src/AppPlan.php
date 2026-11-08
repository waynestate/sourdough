<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use Symfony\Component\Yaml\Yaml;

/**
 * The plan for an app, as `sourdough new --describe` asks a model for and
 * `--plan=<file>` reads back.
 *
 * This is the *what* (permutation, parts, name, models, pages), as opposed to
 * the steps-plan in Plan, which is the *how* (what `sourdough new` will do).
 *
 * Every bake saves the plan in the app as `.sourdough/plan.yaml` (ADR-0014,
 * decision 3): the recipe card taped inside the jar's lid. It sits next to
 * the manifest but is not in it, so it is neither managed nor seeded:
 * `sourdough feed` and `manifest --check` never read, rewrite or flag it.
 * The file is a valid `--plan` file, so the same loaf can be baked again.
 *
 * @phpstan-type Model array{name: string, fields: list<array{name: string, type: string}>}
 */
final class AppPlan
{
    /**
     * The saved plan's path, relative to the app root (forward slashes).
     */
    public const FILE = '.sourdough/plan.yaml';

    /**
     * The comment lines at the top of the saved plan. YAML comments, so the
     * file still matches resources/plan.schema.json, which allows no extra keys.
     */
    private const YAML_HEADER = <<<'YAML'
        # The plan this app was baked from, saved by sourdough new (ADR-0014).
        # It matches Sourdough's plan schema, so it bakes the same shape again:
        #   sourdough new <dir> --plan=.sourdough/plan.yaml
        # Sourdough never rewrites it after the bake: sourdough feed and
        # sourdough manifest --check leave it alone. Commit it with the app.


        YAML;

    /**
     * @param list<Part> $parts
     * @param list<Model> $models
     * @param list<string> $pages
     */
    private function __construct(
        public readonly Permutation $permutation,
        public readonly array $parts,
        public readonly string $name,
        public readonly array $models,
        public readonly array $pages,
    ) {}

    /**
     * Build a plan from an array that has already been validated against
     * resources/plan.schema.json (see Describe).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parts = [];
        foreach ($data['parts'] ?? [] as $part) {
            $parts[] = Part::from((string) $part);
        }

        $models = [];
        foreach ($data['models'] ?? [] as $model) {
            if (!is_array($model)) {
                continue;
            }
            $fields = [];
            foreach ($model['fields'] ?? [] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fields[] = [
                    'name' => (string) ($field['name'] ?? ''),
                    'type' => (string) ($field['type'] ?? ''),
                ];
            }
            $models[] = [
                'name' => (string) ($model['name'] ?? ''),
                'fields' => $fields,
            ];
        }

        $pages = [];
        foreach ($data['pages'] ?? [] as $page) {
            $pages[] = (string) $page;
        }

        return new self(
            Permutation::from((string) $data['permutation']),
            $parts,
            (string) $data['name'],
            $models,
            $pages,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'permutation' => $this->permutation->value,
            'parts' => array_map(static fn(Part $part): string => $part->value, $this->parts),
            'name' => $this->name,
        ];

        if ($this->models !== []) {
            $data['models'] = $this->models;
        }

        if ($this->pages !== []) {
            $data['pages'] = $this->pages;
        }

        return $data;
    }

    /**
     * The plan for a bake from flags alone (no --plan file): the shape and
     * the name, with every part, because every shape wires all four in.
     */
    public static function forShape(Permutation $permutation, string $name): self
    {
        return new self($permutation, Part::cases(), $name, [], []);
    }

    /**
     * The same plan with another name: an explicit --name wins over the
     * plan's, and the saved plan records the name the app was baked with.
     */
    public function withName(string $name): self
    {
        return new self($this->permutation, $this->parts, $name, $this->models, $this->pages);
    }

    /**
     * The plan as YAML, in the shape `--plan` reads back: block style, two
     * spaces, each field on one line, empty lists as `[]`.
     */
    public function toYaml(): string
    {
        return self::YAML_HEADER . Yaml::dump(
            $this->toArray(),
            4,
            2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_COMPACT_NESTED_MAPPING,
        );
    }

    /**
     * Save the plan into the app in $dir as `.sourdough/plan.yaml`.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function write(string $dir): void
    {
        $dirPath = $dir . '/.sourdough';
        if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dirPath));
        }

        $path = $dir . '/' . self::FILE;
        if (false === file_put_contents($path, $this->toYaml())) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }
}
