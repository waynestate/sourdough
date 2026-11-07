<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

/**
 * Feed cannot run: no manifest, no git, a dirty tree, leftovers from the
 * last feed, a manifest from a newer Sourdough. The command answers exit 2
 * with the message.
 */
final class FeedException extends \RuntimeException {}
