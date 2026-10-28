<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\Manifest;

/**
 * The `sourdough manifest` command.
 *
 * `sourdough manifest --check` compares the app in the given directory
 * (default: the current directory) against its Sourdough manifest and lists
 * the managed files that changed or are missing. Seeded files are never
 * reported: the app owns them.
 *
 * Exit codes (matching PROCTOR): 0 clean, 1 changed or missing, 2 tool error.
 */
final class ManifestCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('manifest')
            ->setDescription('Check a generated app against its Sourdough manifest.')
            ->addArgument('dir', InputArgument::OPTIONAL, 'The app directory to check (default: the current directory).')
            ->addOption('check', null, InputOption::VALUE_NONE, 'List managed files that changed or are missing; exit 1 when anything changed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('check')) {
            $this->errorOutput($output)->writeln('Use `sourdough manifest --check` to check the app against its manifest.');

            return 2;
        }

        $dir = $input->getArgument('dir') ?: (getcwd() ?: '.');

        try {
            $result = Manifest::check($dir);
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());

            return 2;
        }

        if ([] === $result['changed'] && [] === $result['missing']) {
            $output->writeln('Clean: every managed file matches the manifest.');

            return 0;
        }

        foreach ($result['changed'] as $file) {
            $output->writeln(sprintf('changed  %s', $file));
        }
        foreach ($result['missing'] as $file) {
            $output->writeln(sprintf('missing  %s', $file));
        }

        return 1;
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
