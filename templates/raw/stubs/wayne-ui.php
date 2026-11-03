<?php

declare(strict_types=1);

/**
 * Static-analysis stub for the run-time WayneUI functions.
 *
 * The real definitions live in public/wayne-ui.php, which the curl line in
 * public/index.php fetches from the pinned WayneUI release. That file is not
 * part of the template, so PHPStan reads this stub through phpstan.neon's
 * scanFiles instead. Nothing here runs at request time; the bodies throw if
 * they ever do.
 */

/**
 * @param array{app_name: string, assets: string, csp_nonce: mixed, env: string, user: mixed} & array<string, mixed> $opts
 */
function wsu_shell_start(array $opts = []): string
{
    throw new LogicException('Static-analysis stub; the real function comes from public/wayne-ui.php.');
}

/**
 * Closes the shell that wsu_shell_start() opened.
 */
function wsu_shell_end(): string
{
    throw new LogicException('Static-analysis stub; the real function comes from public/wayne-ui.php.');
}
