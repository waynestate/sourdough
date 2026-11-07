<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

/**
 * What `sourdough feed` does to one file. Files it leaves alone get no
 * action at all and are not listed.
 */
enum FeedAction: string
{
    /** New in the template: written into the app. */
    case Add = 'add';

    /** The template changed it and the app had not: replaced. */
    case Update = 'update';

    /** Both changed it, in different places: three-way merged cleanly. */
    case Merge = 'merge';

    /** Both changed the same lines, or there was nothing to merge from: marked for a person. */
    case Conflict = 'conflict';

    /** The template dropped it and the app had not changed it: deleted. */
    case Remove = 'remove';

    /** The template moved on, but the app's choice stands: left as it is, and said so. */
    case Keep = 'keep';

    /**
     * Whether this action writes or deletes anything in the app.
     */
    public function writes(): bool
    {
        return self::Keep !== $this;
    }
}
