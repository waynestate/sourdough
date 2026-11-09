<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

/**
 * Adopt cannot run on this app: it is not there, or it already has a
 * manifest. The message says what to do instead.
 */
final class AdoptException extends \RuntimeException {}
