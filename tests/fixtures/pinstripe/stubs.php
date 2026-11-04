<?php

declare(strict_types=1);

/*
 * Test-only stubs for PinstripeRawTest. A real install provides these
 * symbols from packages (HELMET, SESAME) or from ext-oci8; the php CLI
 * image the suite runs on has neither, so the test loads this file
 * through PHPStan --autoload-file. Signatures follow the real packages'
 * usage in the raw template, not their full surface.
 */

namespace Waynestate\Helmet {

    /** @return array{nonce: string} */
    function helmet_boot(array $options = []): array
    {
        return ['nonce' => 'stub'];
    }
}

namespace Waynestate\Sesame {

    class Identity
    {
        public string $username = '';
    }

    interface UserResolver
    {
        public function findOrImportUser(Identity $identity): mixed;
    }

    interface Session
    {
    }

    final class Config
    {
        private function __construct()
        {
        }

        public static function fromArray(array $config): self
        {
            return new self();
        }
    }

    interface Driver
    {
    }

    final class DriverFactory
    {
        public static function make(Config $config): Driver
        {
            throw new \RuntimeException('stub');
        }
    }

    final class Sesame
    {
        public function __construct(
            Driver $driver,
            UserResolver $userResolver,
            Session $sessionStore,
            Config $config,
        ) {
        }
    }
}

namespace Waynestate\Sesame\Session {

    // The real SESAME keeps the interface at Waynestate\Sesame\Session and
    // this class one level down, in a namespace of the same name.
    final class NativeSession implements \Waynestate\Sesame\Session
    {
    }
}

namespace {

    if (!defined('OCI_DEFAULT')) {
        define('OCI_DEFAULT', 0);
    }
    if (!defined('OCI_ASSOC')) {
        define('OCI_ASSOC', 1);
    }
    if (!defined('OCI_FETCHSTATEMENT_BY_ROW')) {
        define('OCI_FETCHSTATEMENT_BY_ROW', 1);
    }
    if (!defined('OCI_B_BLOB')) {
        define('OCI_B_BLOB', 11);
    }

    // Resource positions are typed in docblocks only: PHPStan reads a bare
    // "resource" in a native signature as a class name, and the real
    // ext-oci8 types these as resource|false, which the template code
    // guards. Docblock types keep the stubs loadable on any PHP.
    if (!function_exists('oci_connect')) {
        /** @return resource|false */
        function oci_connect(string $username, string $password, ?string $connection_string = null, string $charset = '', int $session_mode = 0)
        {
            return false;
        }
    }
    if (!function_exists('oci_parse')) {
        /**
         * @param resource $connection
         * @return resource|false
         */
        function oci_parse($connection, string $sqltext)
        {
            return false;
        }
    }
    if (!function_exists('oci_bind_by_name')) {
        /** @param resource $statement */
        function oci_bind_by_name($statement, string $parameter_name, mixed &$variable, int $size = -1, int $type = 11, int $is_null = 0): bool
        {
            return true;
        }
    }
    if (!function_exists('oci_execute')) {
        /** @param resource $statement */
        function oci_execute($statement, int $mode = 0): bool
        {
            return false;
        }
    }
    if (!function_exists('oci_fetch_all')) {
        /**
         * @param resource $statement
         * @param array<int|string, mixed> $out_table
         * @return int
         */
        function oci_fetch_all($statement, array &$out_table, int $offset = 0, ?int &$row_count = null, int $flags = 1)
        {
            return 0;
        }
    }
    if (!function_exists('oci_close')) {
        /** @param resource $connection */
        function oci_close($connection): bool
        {
            return true;
        }
    }
    if (!function_exists('oci_error')) {
        function oci_error(mixed $source = null): array|false
        {
            return false;
        }
    }

    if (!function_exists('sesame')) {
        function sesame(\Waynestate\Sesame\Sesame $sesame): void
        {
            $GLOBALS['__sourdough_test_sesame'] = $sesame;
        }
    }
    if (!function_exists('sesame_user')) {
        /** @return array<string, mixed>|null */
        function sesame_user(): ?array
        {
            return $GLOBALS['__sourdough_test_sesame_user'] ?? null;
        }
    }
    if (!function_exists('sesame_login')) {
        /** @return array<string, mixed> */
        function sesame_login(): array
        {
            return [];
        }
    }

    if (!function_exists('wsu_shell_start')) {
        function wsu_shell_start(array $options): string
        {
            return '';
        }
    }
    if (!function_exists('wsu_shell_end')) {
        function wsu_shell_end(): string
        {
            return '';
        }
    }
}
