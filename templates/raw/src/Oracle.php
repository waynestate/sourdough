<?php

declare(strict_types=1);

namespace App;

/**
 * A small Oracle helper for apps that do not run on a framework.
 *
 * Values reach Oracle only through query()'s $binds; there is no method
 * that accepts a value to concatenate into SQL. Every failure this helper
 * understands comes back as an OracleException whose message is one of
 * the plain sentences, never a setting's value.
 *
 * The helper is dormant until used: fromEnv() only reads the settings,
 * and the first query() is when anything is checked. On a PHP without the
 * OCI8 driver that first query throws the driver sentence instead of a
 * fatal error, so a generated app boots and passes its tests anywhere.
 */
final class Oracle
{
    /**
     * The sentence for a PHP without the OCI8 driver.
     */
    private const DRIVER_MISSING = 'Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.';

    /**
     * The sentence for a permission failure; %d is the error code.
     */
    private const PERMISSION = 'The login worked, but this account lacks permission on that table or view (ORA-%d). Ask the data owner for the grant.';

    /**
     * The sentence for a driver error this helper does not explain.
     */
    private const UNKNOWN = 'Oracle failed in a way this helper does not explain. Ask the data owner to look at the database side.';

    /**
     * The Oracle error codes this helper explains, by the number
     * oci_error() reports.
     *
     * @var array<int, string>
     */
    private const SENTENCES = [
        12154 => 'Oracle could not find the connect name in ORACLE_TNS. Use host:port/service or a full descriptor.',
        12514 => 'The Oracle listener answered but that service is not available. Check ORACLE_SERVICE_NAME; the database may be in maintenance.',
        12541 => 'There is no Oracle listener at that address. Check the host and port; the database may be down.',
        1017 => 'Oracle refused the username or password. Check ORACLE_USERNAME and ORACLE_PASSWORD.',
    ];

    /**
     * The setting names, in the order query() checks them.
     *
     * @var list<string>
     */
    private const SETTINGS = ['ORACLE_USERNAME', 'ORACLE_PASSWORD', 'ORACLE_TNS', 'ORACLE_HOST', 'ORACLE_PORT', 'ORACLE_SERVICE_NAME'];

    /** @var array<string, string> */
    private array $settings;

    /** @var \Closure(string, array): array */
    private \Closure $connector;

    /**
     * Read the ORACLE_* settings from $env (for example $_ENV). Nothing
     * connects and nothing is validated until the first query().
     *
     * @param array<string, mixed> $env
     * @param (callable(string, array): array)|null $connector runs the SQL; null uses the OCI8 driver
     */
    public static function fromEnv(array $env, ?callable $connector = null): self
    {
        $settings = [];
        foreach (self::SETTINGS as $name) {
            $value = $env[$name] ?? '';
            $settings[$name] = is_string($value) ? $value : '';
        }

        return new self($settings, $connector);
    }

    /**
     * Run $sql with named binds and return the rows, each row an array of
     * column name to value. Bind names may carry a leading colon or not.
     *
     * @param array<string, mixed> $binds
     * @return list<array<string, mixed>>
     *
     * @throws OracleException when a setting is empty or the driver is missing
     */
    public function query(string $sql, array $binds = []): array
    {
        $missing = $this->firstEmptySetting();
        if (null !== $missing) {
            throw new OracleException($missing . " is empty. Set it in the app's environment settings (on Citadel, the dashboard).");
        }

        $normalized = [];
        foreach ($binds as $name => $value) {
            $key = is_string($name) && str_starts_with($name, ':') ? $name : ':' . (string) $name;
            $normalized[$key] = $value;
        }

        return ($this->connector)($sql, $normalized);
    }

    /**
     * @param array<string, string> $settings
     * @param (callable(string, array): array)|null $connector
     */
    private function __construct(array $settings, ?callable $connector)
    {
        $this->settings = $settings;
        $this->connector = $connector !== null
            ? \Closure::fromCallable($connector)
            : fn (string $sql, array $binds): array => $this->runWithOci8($sql, $binds);
    }

    /**
     * The first setting query() needs that is empty, or null. A non-empty
     * ORACLE_TNS stands in for the host, port and service name.
     */
    private function firstEmptySetting(): ?string
    {
        foreach (['ORACLE_USERNAME', 'ORACLE_PASSWORD'] as $name) {
            if ('' === $this->settings[$name]) {
                return $name;
            }
        }

        if ('' !== $this->settings['ORACLE_TNS']) {
            return null;
        }

        foreach (['ORACLE_HOST', 'ORACLE_PORT', 'ORACLE_SERVICE_NAME'] as $name) {
            if ('' === $this->settings[$name]) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The real work, on the OCI8 driver. Every call is guarded, so a PHP
     * without the driver throws the driver sentence, not a fatal error.
     *
     * @param array<string, mixed> $binds
     * @return list<array<string, mixed>>
     */
    private function runWithOci8(string $sql, array $binds): array
    {
        if (!function_exists('oci_connect')) {
            throw new OracleException(self::DRIVER_MISSING);
        }

        $connection = oci_connect($this->settings['ORACLE_USERNAME'], $this->settings['ORACLE_PASSWORD'], $this->descriptor());
        if (false === $connection) {
            throw $this->failure(oci_error($connection));
        }

        try {
            $statement = oci_parse($connection, $sql);
            if (false === $statement) {
            throw $this->failure(oci_error());
            }

            // oci_bind_by_name binds by reference: bind each array slot, never the
            // loop variable, or every placeholder ends up with the last value.
            foreach (array_keys($binds) as $name) {
                oci_bind_by_name($statement, $name, $binds[$name]);
            }

            if (!oci_execute($statement)) {
                throw $this->failure(oci_error($statement));
            }

            $rows = [];
            $rowCount = 0;
            oci_fetch_all($statement, $rows, 0, $rowCount, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC);

            return $rows;
        } finally {
            oci_close($connection);
        }
    }

    /**
     * The connect descriptor: ORACLE_TNS as given, or Easy Connect built
     * from the host, port and service name.
     */
    private function descriptor(): string
    {
        if ('' !== $this->settings['ORACLE_TNS']) {
            return $this->settings['ORACLE_TNS'];
        }

        return $this->settings['ORACLE_HOST'] . ':' . $this->settings['ORACLE_PORT'] . '/' . $this->settings['ORACLE_SERVICE_NAME'];
    }

    /**
     * The exception for a driver error: the plain sentence when this
     * helper knows the code, a fixed sentence when it does not. The
     * driver's own text is never echoed, because it can carry the connect
     * descriptor.
     *
     * @param array<string, mixed> $error the array oci_error() returns
     */
    private function failure(array $error): \Throwable
    {
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

        if (null === $code) {
            $message = (string) ($error['message'] ?? '');
            if (preg_match('/ORA-(\d+)/', $message, $matches)) {
                $code = (int) $matches[1];
            }
        }

        if (null !== $code && isset(self::SENTENCES[$code])) {
            return new OracleException(self::SENTENCES[$code]);
        }

        if (null !== $code && (1031 === $code || 942 === $code)) {
            return new OracleException(sprintf(self::PERMISSION, $code));
        }

        return new OracleException(self::UNKNOWN);
    }
}
