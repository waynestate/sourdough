<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

/**
 * One line of a feed plan: a file, what happens to it, and why.
 */
final class FeedStep
{
    /**
     * @param string      $path       the file, relative to the app root (forward slashes)
     * @param string      $detail     why, in a sentence fragment for the report
     * @param string|null $content    what to write at $path (null: write nothing there)
     * @param bool        $executable set the executable bits after writing (Squire's scripts)
     * @param string|null $sidecar    what to write at "$path.sourdough-new" (binary conflicts)
     */
    public function __construct(
        public readonly string $path,
        public readonly FeedAction $action,
        public readonly string $detail,
        public readonly ?string $content = null,
        public readonly bool $executable = false,
        public readonly ?string $sidecar = null,
    ) {}

    /**
     * @return array{path: string, action: string, detail: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'action' => $this->action->value, 'detail' => $this->detail];
    }
}
