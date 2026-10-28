<?php

declare(strict_types=1);

/**
 * Verify that the checked copy of SQUIRE's deploy templates in
 * templates/squire/ still matches a fresh export from the SQUIRE checkout.
 *
 * The SQUIRE checkout is located with the SQUIRE_PATH environment variable
 * (default: /squire, the mount point used by the CI and acceptance images).
 * The drift detection itself is done by SQUIRE's own bin/export-templates.php
 * in --check mode, so the comparison logic lives in SQUIRE, not here. This
 * script only finds the checkout and runs it, then passes the exit code
 * straight through.
 *
 * Unlike the other copy checks, a missing checkout is not an error: the copy
 * is committed and the gate is only meaningful where a SQUIRE checkout is
 * mounted. When SQUIRE_PATH does not point at a checkout, the check skips
 * with a clear message and exits 0, so it never blocks a build that simply
 * has no SQUIRE checkout to compare against.
 *
 * Usage:
 *   composer squire-copy:check
 *   SQUIRE_PATH=/path/to/squire composer squire-copy:check
 *
 * Exit codes:
 *   0  the copy matches a fresh export, or no SQUIRE checkout is available
 *   1  the copy has drifted (a file differs, is missing, or is extra)
 *   2  the SQUIRE export could not be started
 */

$repoRoot = dirname(__DIR__);
$defaultSquirePath = '/squire';

$squirePath = getenv('SQUIRE_PATH');
if ($squirePath === false || $squirePath === '') {
    $squirePath = $defaultSquirePath;
}

$export = $squirePath . '/bin/export-templates.php';
if (!is_file($export)) {
    fwrite(STDOUT, "squire-copy:check: no SQUIRE checkout at {$squirePath}; skipping.\n");
    fwrite(STDOUT, "Set SQUIRE_PATH to a SQUIRE checkout to run the check (default: {$defaultSquirePath}).\n");
    exit(0);
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open(
    [PHP_BINARY, $export, '--check', '--to=' . $repoRoot . '/templates/squire'],
    $descriptors,
    $pipes,
    $repoRoot,
);
if (!is_resource($process)) {
    fwrite(STDERR, "squire-copy:check: could not start the SQUIRE export\n");
    exit(2);
}
fclose($pipes[0]);
// The export writes either an all-clear (stdout) or a list of problems
// (stderr), never both in volume, so draining one pipe then the other cannot
// deadlock. Stream each straight through to the matching parent handle.
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($stdout !== false && $stdout !== '') {
    fwrite(STDOUT, $stdout);
}
if ($stderr !== false && $stderr !== '') {
    fwrite(STDERR, $stderr);
}
exit($exit);
