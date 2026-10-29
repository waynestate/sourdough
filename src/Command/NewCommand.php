<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\AppPlan;
use Waynestate\Sourdough\Describe;
use Waynestate\Sourdough\DescribeException;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Part;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\Plan;

/**
 * The `sourdough new` command.
 *
 * Exit codes (matching PROCTOR): 0 done, 1 refused (invalid flags, non-empty
 * dir, not available in this release), 2 tool error.
 */
final class NewCommand extends Command
{
    public function __construct(
        private readonly ?Describe $describe = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Make a new Wayne State app with the stack already wired in.')
            ->addArgument('dir', InputArgument::OPTIONAL, 'The directory to create the app in. Not needed with --describe.')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Raw PHP with WayneUI (the default).')
            ->addOption('laravel', null, InputOption::VALUE_NONE, 'Laravel + Blade.')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Add a Vue front end (SPA over JSON, or islands with --laravel).')
            ->addOption('inertia', null, InputOption::VALUE_NONE, 'Use Inertia/Vue (requires --laravel).')
            ->addOption('sail', null, InputOption::VALUE_NONE, 'Use Sail for the local runtime (Laravel paths only).')
            ->addOption('docker', null, InputOption::VALUE_NONE, 'Use Docker for the local runtime (Laravel paths only).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and write nothing.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The app name (default: the basename of dir).')
            ->addOption('no-input', null, InputOption::VALUE_NONE, 'Never prompt. Sourdough 0.1 has no prompts, so this is accepted and documented.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With --dry-run, print the plan as JSON.')
            ->addOption('describe', null, InputOption::VALUE_REQUIRED, 'Describe what the app does in a sentence; a model plans it. Needs the SOURDOUGH_LLM_* env vars.')
            ->addOption('plan-only', null, InputOption::VALUE_NONE, 'With --describe, print the plan as JSON and write nothing.')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'A ready-made plan file (JSON matching resources/plan.schema.json)')
            ->addOption('playbook', null, InputOption::VALUE_NONE, 'Require waynestate/playbook in the generated app: adds the VCS repository and the package to require.')
            ->addOption('no-playbook', null, InputOption::VALUE_NONE, 'Skip the PLAYBOOK wiring: no suggest, no .env.example block, no README section.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $input->getArgument('dir');
        $raw = (bool) $input->getOption('raw');
        $laravel = (bool) $input->getOption('laravel');
        $vue = (bool) $input->getOption('vue');
        $inertia = (bool) $input->getOption('inertia');
        $sail = (bool) $input->getOption('sail');
        $docker = (bool) $input->getOption('docker');
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');
        $nameOption = $input->getOption('name');
        $describe = $input->getOption('describe');
        $planOnly = (bool) $input->getOption('plan-only');
        $planFile = $input->getOption('plan');
        $playbook = (bool) $input->getOption('playbook');
        $noPlaybook = (bool) $input->getOption('no-playbook');

        if (null !== $describe && null !== $planFile) {
            $this->errorOutput($output)->writeln('--describe and --plan are mutually exclusive.');
            return 1;
        }

        if ($playbook && $noPlaybook) {
            $this->errorOutput($output)->writeln('--playbook and --no-playbook are mutually exclusive.');
            return 1;
        }

        $mode = PlaybookMode::Suggest;
        if ($playbook) {
            $mode = PlaybookMode::Require;
        } elseif ($noPlaybook) {
            $mode = PlaybookMode::None;
        }

        if ($planOnly && null === $describe) {
            $this->errorOutput($output)->writeln('--plan-only is only available with --describe.');
            return 1;
        }

        if ($json && !$dryRun) {
            $this->errorOutput($output)->writeln('--json is only available with --dry-run.');
            return 2;
        }

        if (null !== $describe) {
            return $this->runDescribe($output, (string) $describe, $planOnly);
        }

        if (null === $dir || '' === $dir) {
            $this->errorOutput($output)->writeln('The dir argument is required unless you use --describe.');
            return 1;
        }

        $name = null !== $nameOption && '' !== $nameOption ? (string) $nameOption : basename($dir);

        if (null !== $planFile) {
            return $this->runPlanFile($output, (string) $planFile, $dir, $nameOption, $sail, $docker, $dryRun, $json, $mode);
        }

        try {
            $permutation = Permutation::fromFlags($raw, $laravel, $vue, $inertia);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        $flags = [];
        if ($raw) {
            $flags['raw'] = true;
        }
        if ($laravel) {
            $flags['laravel'] = true;
        }
        if ($vue) {
            $flags['vue'] = true;
        }
        if ($inertia) {
            $flags['inertia'] = true;
        }

        return $this->generate($output, $permutation, $name, $dir, $sail, $docker, $dryRun, $json, $flags, $mode);
    }

    private function runDescribe(OutputInterface $output, string $sentence, bool $planOnly): int
    {
        try {
            $plan = $this->describeService()->describe($sentence);
        } catch (DescribeException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $this->errorOutput($output)->writeln('The model could not be reached: ' . $e->getMessage());
            return 2;
        }

        if ($planOnly) {
            return $this->printAppPlanJson($output, $plan);
        }

        $this->printAppPlan($output, $plan);
        $output->writeln('');
        $output->writeln('Generation from a plan is not built yet. Save this plan and pass it with --plan=<file>.');

        return 0;
    }

    private function runPlanFile(
        OutputInterface $output,
        string $planFile,
        string $dir,
        ?string $nameOption,
        bool $sail,
        bool $docker,
        bool $dryRun,
        bool $json,
        PlaybookMode $playbook,
    ): int {
        try {
            $plan = $this->describeService()->fromFile($planFile);
        } catch (DescribeException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        // The plan carries the app name; an explicit --name still wins.
        $name = null !== $nameOption && '' !== $nameOption ? (string) $nameOption : $plan->name;
        $flags = $this->flagsForPermutation($plan->permutation);

        return $this->generate($output, $plan->permutation, $name, $dir, $sail, $docker, $dryRun, $json, $flags, $playbook);
    }

    /**
     * @param array<string, bool> $flags the raw/laravel/vue/inertia flags
     */
    private function generate(
        OutputInterface $output,
        Permutation $permutation,
        string $name,
        string $dir,
        bool $sail,
        bool $docker,
        bool $dryRun,
        bool $json,
        array $flags,
        PlaybookMode $playbook,
    ): int {
        try {
            $plan = Plan::forPermutation($permutation, $dir, $sail, $docker);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        if ($dryRun) {
            if ($json) {
                $encoded = json_encode([
                    'permutation' => $permutation->value,
                    'label' => $permutation->label(),
                    'dir' => $dir,
                    'steps' => $plan->steps,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (false === $encoded) {
                    $this->errorOutput($output)->writeln('Could not encode the plan as JSON.');
                    return 2;
                }
                $output->writeln($encoded);
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

        $answers = $flags;
        if ($sail) {
            $answers['sail'] = true;
        }
        if ($docker) {
            $answers['docker'] = true;
        }
        if (PlaybookMode::Require === $playbook) {
            $answers['playbook'] = true;
        }
        if (PlaybookMode::None === $playbook) {
            $answers['no-playbook'] = true;
        }

        try {
            (new RawGenerator())->generate($dir, $name, $answers, $playbook);
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

        if (PlaybookMode::Suggest === $playbook) {
            $output->writeln('');
            $output->writeln('WSU values: see "WSU values (PLAYBOOK)" in README.md, or re-run with --playbook.');
        }

        return 0;
    }

    /**
     * The raw/laravel/vue/inertia flags implied by a permutation.
     *
     * @return array<string, bool>
     */
    private function flagsForPermutation(Permutation $permutation): array
    {
        $flags = [];
        if (Permutation::Raw === $permutation || Permutation::RawVue === $permutation) {
            $flags['raw'] = true;
        }
        if (Permutation::LaravelBlade === $permutation || Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation) {
            $flags['laravel'] = true;
        }
        if (Permutation::RawVue === $permutation || Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation) {
            $flags['vue'] = true;
        }
        if (Permutation::LaravelInertia === $permutation) {
            $flags['inertia'] = true;
        }

        return $flags;
    }

    private function printAppPlan(OutputInterface $output, AppPlan $plan): void
    {
        $output->writeln(sprintf('Permutation: %s', $plan->permutation->label()));
        $output->writeln(sprintf('Name:        %s', $plan->name));
        $output->writeln(sprintf('Parts:       %s', implode(', ', array_map(static fn(Part $part): string => $part->value, $plan->parts))));

        if ($plan->models !== []) {
            $output->writeln('Models:');
            foreach ($plan->models as $model) {
                $fields = array_map(static fn(array $field): string => $field['name'] . ' (' . $field['type'] . ')', $model['fields']);
                $output->writeln(sprintf('  - %s: %s', $model['name'], implode(', ', $fields)));
            }
        }

        if ($plan->pages !== []) {
            $output->writeln(sprintf('Pages:       %s', implode(', ', $plan->pages)));
        }
    }

    private function printAppPlanJson(OutputInterface $output, AppPlan $plan): int
    {
        $encoded = json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            $this->errorOutput($output)->writeln('Could not encode the plan as JSON.');
            return 2;
        }
        $output->writeln($encoded);

        return 0;
    }

    private function describeService(): Describe
    {
        return $this->describe ?? new Describe();
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
