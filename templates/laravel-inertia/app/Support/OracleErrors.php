<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turn Oracle failures into plain sentences.
 *
 * describe() reads only the shape of a throwable: whether the OCI8 driver
 * is missing, or which Oracle error code it carries. It never returns any
 * part of the original message, so a DSN, username or password cannot leak
 * into a log line or an error page.
 */
final class OracleErrors
{
    /**
     * The sentence the driver-missing failure earns.
     */
    private const DRIVER_MISSING = 'Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.';

    /**
     * The sentence a permission failure earns; %d is the error code.
     */
    private const PERMISSION = 'The login worked, but this account lacks permission on that table or view (ORA-%d). Ask the data owner for the grant.';

    /**
     * The Oracle error codes Sourdough knows how to explain.
     *
     * @var array<int, string>
     */
    private const SENTENCES = [
        12154 => 'Oracle could not find the connect name in ORACLE_TNS. Use host:port/service or a full descriptor.',
        12514 => 'The Oracle listener answered but that service is not available. Check ORACLE_SERVICE_NAME; the database may be in maintenance.',
        12541 => 'There is no Oracle listener at that address. Check the host and port; the database may be down.',
        1017 => 'Oracle refused the username or password. Check ORACLE_USERNAME and ORACLE_PASSWORD.',
    ];

    private function __construct() {}

    /**
     * The plain sentence for this failure, or null when it is not an Oracle
     * failure this class knows.
     */
    public static function describe(\Throwable $e): ?string
    {
        $message = $e->getMessage();

        if ($e instanceof \Error && self::mentionsMissingDriver($message)) {
            return self::DRIVER_MISSING;
        }

        $code = self::oraCode($message);

        if ($code === null) {
            return null;
        }

        if (isset(self::SENTENCES[$code])) {
            return self::SENTENCES[$code];
        }

        if ($code === 1031 || $code === 942) {
            return sprintf(self::PERMISSION, $code);
        }

        return null;
    }

    /**
     * True when the message says the OCI8 driver is not loaded: the
     * prototype's "Undefined constant ...OCI_DEFAULT", or an undefined
     * oci_* function or constant.
     */
    private static function mentionsMissingDriver(string $message): bool
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'oci_default')) {
            return true;
        }

        return (bool) preg_match('/undefined\s+(?:function|constant)/i', $message)
            && str_contains($lower, 'oci');
    }

    /**
     * The first Oracle error code the message carries, or null: Oracle
     * writes it as ORA-<code>, padding short codes with zeros (ORA-00942).
     */
    private static function oraCode(string $message): ?int
    {
        if (preg_match_all('/ORA-(\d+)/', $message, $matches) < 1) {
            return null;
        }

        foreach ($matches[1] as $digits) {
            $code = (int) $digits;

            if (isset(self::SENTENCES[$code]) || $code === 1031 || $code === 942) {
                return $code;
            }
        }

        return null;
    }
}
