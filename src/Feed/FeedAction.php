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
     * The app already has the template's new version, but the manifest does
     * not know it yet: the file is left as it is and the manifest records it.
     */
    case Record = 'record';

    /**
     * Whether this action writes or deletes anything in the app (besides the
     * manifest, which every feed with a step moves).
     */
    public function writes(): bool
    {
        return self::Keep !== $this && self::Record !== $this;
    }
}
