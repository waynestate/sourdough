<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Check the Oracle settings and connection, in plain language.
 *
 * The check itself is the oracle-check.php that Squire ships with the
 * deploy files; this command only finds it, runs it with the app's
 * current environment, and shows what it says.
 */
class OracleCheck extends Command
{
    protected $signature = 'oracle:check';

    protected $description = 'Check the Oracle settings and connection, in plain language';

    public function handle(): int
    {
        // A reversible default: where the kit lives is the user's decision.
        // Squire's Laravel layout ships it at the app root, WELD keeps it
        // under deploy/. Look at the root first, then deploy/.
        $script = null;
        foreach ([base_path('oracle-check.php'), base_path('deploy/oracle-check.php')] as $candidate) {
            if (is_file($candidate)) {
                $script = $candidate;

                break;
            }
        }

        if (null === $script) {
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
