<?php

declare(strict_types=1);

use App\Oracle;
use App\OracleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OracleTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function fullEnv(): array
    {
        return [
            'ORACLE_USERNAME' => 'scott',
            'ORACLE_PASSWORD' => 'tiger',
            'ORACLE_TNS' => 'db.example.com:1521/wsu',
        ];
    }

    public function testFromEnvDoesNothingUntilUsed(): void
    {
        // No settings at all: construction is fine, the first query is
        // when anything is noticed.
        $oracle = Oracle::fromEnv([]);

        self::assertInstanceOf(Oracle::class, $oracle);
    }

    public function testBindsArePassedToTheConnector(): void
    {
        $calls = [];
        $connector = function (string $sql, array $binds) use (&$calls): array {
            $calls[] = [$sql, $binds];

            return [['id' => 1, 'name' => 'Widget']];
        };

        $rows = Oracle::fromEnv($this->fullEnv(), $connector)
            ->query('SELECT id, name FROM widgets WHERE id = :id', ['id' => 1]);

        self::assertSame([['id' => 1, 'name' => 'Widget']], $rows);
        self::assertSame([['SELECT id, name FROM widgets WHERE id = :id', [':id' => 1]]], $calls);
    }

    public function testBindNamesWithoutAColonAreNormalized(): void
    {
        $calls = [];
        $connector = function (string $sql, array $binds) use (&$calls): array {
            $calls[] = $binds;

            return [];
        };

        Oracle::fromEnv($this->fullEnv(), $connector)
            ->query('SELECT 1 FROM dual WHERE a = :a AND b = :b', ['a' => 1, 'b' => 2]);

        self::assertSame([':a' => 1, ':b' => 2], $calls[0]);
    }

    #[DataProvider('missingSettings')]
    public function testMissingSettingThrowsTheEmptySentence(string $name): void
    {
        $env = $this->fullEnv();
        // Without a TNS the host, port and service name stand in for it, so
        // fill all three and empty only the one under test.
        $env['ORACLE_TNS'] = '';
        $env['ORACLE_HOST'] = 'db.example.com';
        $env['ORACLE_PORT'] = '1521';
        $env['ORACLE_SERVICE_NAME'] = 'wsu';
        $env[$name] = '';

        $this->expectException(OracleException::class);
        $this->expectExceptionMessage($name . " is empty. Set it in the app's environment settings (on Citadel, the dashboard).");

        Oracle::fromEnv($env, fn() => [])
            ->query('SELECT 1 FROM dual');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingSettings(): iterable
    {
        yield 'username' => ['ORACLE_USERNAME'];
        yield 'password' => ['ORACLE_PASSWORD'];
        yield 'host without tns' => ['ORACLE_HOST'];
        yield 'port without tns' => ['ORACLE_PORT'];
        yield 'service without tns' => ['ORACLE_SERVICE_NAME'];
    }

    public function testDriverlessRunThrowsTheDriverSentence(): void
    {
        if (function_exists('oci_connect')) {
            self::markTestSkipped('This PHP has the OCI8 driver; the sentence is for PHPs without it.');
        }

        $this->expectException(OracleException::class);
        $this->expectExceptionMessage('Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.');

        Oracle::fromEnv($this->fullEnv())
            ->query('SELECT 1 FROM dual');
    }

    public function testNoValueAppearsInAnExceptionMessage(): void
    {
        $env = [
            'ORACLE_USERNAME' => 'secret-user-xyz',
            'ORACLE_PASSWORD' => 'secret-pass-xyz',
            'ORACLE_TNS' => '',
            'ORACLE_HOST' => 'secret-host-xyz',
            'ORACLE_PORT' => '1521',
            'ORACLE_SERVICE_NAME' => '',
        ];

        try {
            Oracle::fromEnv($env, fn() => [])
                ->query('SELECT 1 FROM dual');
            self::fail('Expected an OracleException');
        } catch (OracleException $e) {
            foreach ($env as $value) {
                if ('' === $value) {
                    continue;
                }
                self::assertStringNotContainsString($value, $e->getMessage(), $e->getMessage());
            }
        }
    }
}
