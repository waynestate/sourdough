<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\Plan;

/**
 * The `sourdough new` command.
 *
 * Exit codes (matching PROCTOR): 0 done, 1 refused (invalid flags, non-empty
 * dir, not available in this release), 2 tool error.
 */
final class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Make a new Wayne State app with the stack already wired in.')
            ->addArgument('dir', InputArgument::REQUIRED, 'The directory to create the app in.')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Raw PHP with WayneUI (the default).')
            ->addOption('laravel', null, InputOption::VALUE_NONE, 'Laravel + Blade.')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Add a Vue front end (SPA over JSON, or islands with --laravel).')
            ->addOption('inertia', null, InputOption::VALUE_NONE, 'Use Inertia/Vue (requires --laravel).')
            ->addOption('sail', null, InputOption::VALUE_NONE, 'Use Sail for the local runtime (Laravel paths only).')
            ->addOption('docker', null, InputOption::VALUE_NONE, 'Use Docker for the local runtime (Laravel paths only).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and write nothing.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The app name (default: the basename of dir).')
            ->addOption('no-input', null, InputOption::VALUE_NONE, 'Never prompt. Sourdough 0.1 has no prompts, so this is accepted and documented.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With --dry-run, print the plan as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = (string) $input->getArgument('dir');
        $raw = (bool) $input->getOption('raw');
        $laravel = (bool) $input->getOption('laravel');
        $vue = (bool) $input->getOption('vue');
        $inertia = (bool) $input->getOption('inertia');
        $sail = (bool) $input->getOption('sail');
        $docker = (bool) $input->getOption('docker');
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');
        $nameOption = $input->getOption('name');

        if ($json && !$dryRun) {
            $this->errorOutput($output)->writeln('--json is only available with --dry-run.');
            return 2;
        }

        $name = null !== $nameOption && '' !== $nameOption ? (string) $nameOption : basename($dir);

        try {
            $permutation = Permutation::fromFlags($raw, $laravel, $vue, $inertia);
            $plan = Plan::forPermutation($permutation, $dir, $sail, $docker);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        if ($dryRun) {
            if ($json) {
                $output->writeln(json_encode([
                    'permutation' => $permutation->value,
                    'label' => $permutation->label(),
                    'dir' => $dir,
                    'steps' => $plan->steps,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->printPlan($output, $plan);
            }

            return 0;
        }

        if (Permutation::Raw !== $permutation) {
            $this->printPlan($output, $plan);
            $this->errorOutput($output)->writeln('not available in this release');
            return 1;
        }

        $this->printPlan($output, $plan);

        try {
            (new RawGenerator())->generate($dir, $name);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 2;
        }

        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln(sprintf('  cd %s', $dir));
        $output->writeln('  composer install');
        $output->writeln('  php -S localhost:8000 -t public');

        return 0;
    }

    private function printPlan(OutputInterface $output, Plan $plan): void
    {
        $output->writeln(sprintf('Permutation: %s', $plan->permutation->label()));
        $output->writeln(sprintf('Target:      %s', $plan->dir));
        $output->writeln('Plan:');
        foreach ($plan->steps as $step) {
            $output->writeln(sprintf('  - %s', $step));
        }
    }

    /**
     * Errors go to stderr in the real CLI (ConsoleOutput) but to the single
     * output under CommandTester (StreamOutput), which has no error stream.
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
