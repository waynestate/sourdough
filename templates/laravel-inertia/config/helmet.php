<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HELMET: this app's overrides only
|--------------------------------------------------------------------------
|
| Every HELMET setting lives in the package, in
| vendor/waynestate/helmet/config/helmet.php, which HELMET's service
| provider merges under this file. Read that file for the full list and
| the HELMET_* environment variables that set each value. Keeping the
| defaults in the package means a composer update brings new settings and
| fixes in (the Playbook lookup for HELMET_PRESET, for one), instead of a
| stale copy here hiding them.
|
| Laravel merges one level deep: a key set here replaces the package's
| value for that key whole, so override a key only when the environment
| variable cannot do it, and copy the package's whole value for that key
| when you do.
|
| "policy_path" The CREED policy.json the environment ribbon reads to show a
|               version warning for the running PHP and Laravel. The package
|               default is none; this app requires waynestate/creed, so it
|               points at the copy Composer installs, unless
|               HELMET_POLICY_PATH names another file.
|
| "error_pages" A new app answers every 4xx with HELMET's fixed page, so an
|               abort() message never reaches the visitor; the package leaves
|               it off for old apps, and HELMET_ERROR_PAGES=false turns it
|               off here too.
|
*/

$policyPath = env('HELMET_POLICY_PATH');
if (! is_string($policyPath) || $policyPath === '') {
    $defaultPolicyPath = base_path('vendor/waynestate/creed/policy/policy.json');
    $policyPath = is_file($defaultPolicyPath) ? $defaultPolicyPath : null;
}

return [
    'policy_path' => $policyPath,

    // New apps answer every 4xx with HELMET's fixed page, so an abort()
    // message never reaches the visitor. HELMET_ERROR_PAGES=false turns
    // it off; JSON clients, validation, auth redirects and 5xx stay
    // with Laravel either way.
    'error_pages' => filter_var(env('HELMET_ERROR_PAGES', true), FILTER_VALIDATE_BOOL),
];
