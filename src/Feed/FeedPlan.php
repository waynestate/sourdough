<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

/**
 * Everything one `sourdough feed` will do, worked out before anything is
 * written: the steps, the base copies to keep for next time, and the
 * manifest the app ends up with.
 */
final class FeedPlan
{
    /**
     * @param list<FeedStep>        $steps    one per file that changes, sorted by path
     * @param array<string, string> $bases    path => the template's version of a managed file the app has changed
     * @param array<string, mixed>  $manifest the new manifest, written when the feed applies
     * @param list<string>          $notes    things a real run would refuse or warn about (dry runs), and what feed inferred
     */
    public function __construct(
        public readonly string $appDir,
        public readonly string $template,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly array $steps,
        public readonly array $bases,
        public readonly array $manifest,
        public readonly array $notes = [],
    ) {}

    /**
     * Whether feeding would change the app. A plan with no steps is a fresh
     * app: feed writes nothing at all, not even the manifest.
     */
    public function hasChanges(): bool
    {
        return [] !== $this->steps;
    }

    public function conflicts(): int
    {
        return $this->count(FeedAction::Conflict);
    }

    public function count(FeedAction $action): int
    {
        return count(array_filter($this->steps, static fn(FeedStep $step): bool => $action === $step->action));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $dryRun): array
    {
        $summary = [];
        foreach (FeedAction::cases() as $action) {
            $summary[$action->value] = $this->count($action);
        }

        return [
            'dir' => $this->appDir,
            'template' => $this->template,
            'from' => $this->fromVersion,
            'to' => $this->toVersion,
            'dry_run' => $dryRun,
            'fresh' => !$this->hasChanges(),
            'files' => array_map(static fn(FeedStep $step): array => $step->toArray(), $this->steps),
            'summary' => $summary,
            'base_copies' => array_keys($this->bases),
            'notes' => $this->notes,
        ];
    }
}
