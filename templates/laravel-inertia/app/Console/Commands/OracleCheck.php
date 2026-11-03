<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Check the Oracle settings and connection, in plain language.
 *
 * The check itself lives in deploy/oracle-check.php, which Squire ships
 * with the deploy files; this command only runs it with the app's current
 * environment and shows what it says.
 */
class OracleCheck extends Command
{
    protected $signature = 'oracle:check';

    protected $description = 'Check the Oracle settings and connection, in plain language';

    public function handle(): int
    {
        $script = base_path('deploy/oracle-check.php');

        if (! is_file($script)) {
            $this->line('The Oracle check file is not here yet. Run "vendor/bin/squire update" to get it.');

            return self::SUCCESS;
        }

        $process = new Process([PHP_BINARY, $script], base_path());
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        $exitCode = $process->getExitCode();

        return $exitCode === null ? self::FAILURE : $exitCode;
    }
}
