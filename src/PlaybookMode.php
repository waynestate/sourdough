<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * How a generated app wires Playbook (waynestate/playbook), WSU's private
 * shared-values package on git.wayne.edu.
 *
 * Require is the default for apps Sourdough makes: the command checks that
 * the developer can read the package before writing anything, and the app
 * requires it in composer.json. The stack still reads each value from the
 * environment first, then from Playbook. Suggest keeps the old wiring for
 * library mode and tests; None skips the wiring entirely.
 */
enum PlaybookMode: string
{
    /**
     * Library mode: suggest the package in composer.json, list its values in
     * .env.example, document them in the README, and print a hint after
     * generation. Not the default for apps anymore; Require is.
     */
    case Suggest = 'suggest';

    /**
     * The default for generated apps: everything Suggest does, plus the VCS
     * repository, waynestate/playbook under require, and the gitlab-domains
     * config entry that lets composer read the private host.
     */
    case Require = 'require';

    /**
     * No Playbook wiring at all: no suggest, no .env.example block, no
     * README section, no hint.
     */
    case None = 'none';
}
