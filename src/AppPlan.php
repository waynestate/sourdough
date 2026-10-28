<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * The plan for an app, as `sourdough new --describe` asks a model for and
 * `--plan=<file>` reads back.
 *
 * This is the *what* (permutation, parts, name, models, pages), as opposed to
 * the steps-plan in Plan, which is the *how* (what `sourdough new` will do).
 *
 * @phpstan-type Model array{name: string, fields: list<array{name: string, type: string}>}
 */
final class AppPlan
{
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
}
