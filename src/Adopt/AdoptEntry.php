<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

/**
 * One line of an adopt plan: a template path, what the app has there, and
 * what that means.
 */
final class AdoptEntry
{
    /**
     * @param string $path   the template path, relative to the app root (forward slashes)
     * @param string $detail what it means, in a sentence fragment for the report
     */
    public function __construct(
        public readonly string $path,
        public readonly AdoptStatus $status,
        public readonly string $detail,
    ) {}

    /**
     * @return array{path: string, status: string, detail: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'status' => $this->status->value, 'detail' => $this->detail];
    }
}
