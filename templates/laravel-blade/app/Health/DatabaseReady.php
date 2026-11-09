<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Support\Facades\DB;

/**
 * HELMET readiness check: can the app reach its database?
 *
 * Laravel connects lazily, so getPdo() opens the default connection here and
 * now. A server that is down, refuses the login or (SQLite) has lost its file
 * throws, and HELMET counts a throw as a failure: /ready answers 503 and the
 * exception class and message go to the log as helmet.readiness_check_failed,
 * never to the body (WELD W11). Opening the connection, rather than running
 * "select 1", works on every driver, Oracle included, which would want
 * "from dual".
 *
 * This is readiness, not liveness: /up never asks, so a database blip takes
 * the instance out of rotation instead of restarting it. The connection's own
 * connect timeout applies; on MySQL, PDO::ATTR_TIMEOUT in the connection's
 * options keeps a host that drops packets from holding the probe.
 */
final class DatabaseReady
{
    public function __invoke(): bool
    {
        DB::connection()->getPdo();

        return true;
    }
}
