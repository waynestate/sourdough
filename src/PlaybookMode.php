<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * How a generated app wires PLAYBOOK (waynestate/playbook), WSU's private
 * shared-values package on git.wayne.edu.
 *
 * The stack reads each value from the environment first, then from PLAYBOOK,
 * so an app installs without it by default: not every developer has read
 * access to the package.
 */
enum PlaybookMode: string
{
    /**
     * The default: suggest the package in composer.json, list its values in
     * .env.example, document them in the README, and print a hint after
     * generation.
     */
    case Suggest = 'suggest';

    /**
     * Require the package: everything Suggest does, plus the VCS repository
     * and waynestate/playbook under require.
     */
    case Require = 'require';

    /**
     * No PLAYBOOK wiring at all: no suggest, no .env.example block, no
     * README section, no hint.
     */
    case None = 'none';
}
