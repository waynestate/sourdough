<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

use Waynestate\Sourdough\Permutation;

/**
 * Everything one adoption will do, worked out before anything is written:
 * the status of every template path, and the manifest the app ends up with.
 * Applying it writes that manifest and nothing else.
 */
final class AdoptPlan
{
    /**
     * @param string               $dir      the app root, resolved (forward slashes)
     * @param string               $version  the Sourdough version the manifest records
     * @param list<AdoptEntry>     $entries  one per template path, sorted by path
     * @param array<string, mixed> $manifest the manifest to write
     * @param list<string>         $notes    things worth saying that are not about one file
     */
    public function __construct(
        public readonly string $dir,
        public readonly Permutation $shape,
        public readonly string $name,
        public readonly string $version,
        public readonly array $entries,
        public readonly array $manifest,
        public readonly array $notes = [],
    ) {}

    public function count(AdoptStatus $status): int
    {
        return count(array_filter($this->entries, static fn(AdoptEntry $entry): bool => $status === $entry->status));
    }

    /**
     * The plan as the JSON report has it (section 4 of the adopt spec), less
     * the exit code, which is the command's to add.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $dryRun): array
    {
        $summary = [];
        foreach ([AdoptStatus::Match, AdoptStatus::Missing, AdoptStatus::Seeded, AdoptStatus::Yours] as $status) {
            $summary[$status->value] = $this->count($status);
        }

        return [
            'dir' => $this->dir,
            'dry_run' => $dryRun,
            'files' => array_map(static fn(AdoptEntry $entry): array => $entry->toArray(), $this->entries),
            'name' => $this->name,
            'notes' => $this->notes,
            'shape' => $this->shape->value,
            'sourdough_version' => $this->version,
            'summary' => $summary,
        ];
    }
}
