<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use RuntimeException;

/**
 * A "refused" outcome from the describe/plan flow: the model could not produce
 * a valid plan, the environment is not set, or a plan file is not a valid plan.
 *
 * The command maps this to exit code 1 (refused), never a blocked command.
 */
final class DescribeException extends RuntimeException {}
