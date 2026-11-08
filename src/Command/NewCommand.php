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
use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Describe;
use Waynestate\Sourdough\DescribeException;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Part;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookAccess;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\Plan;

/**
 * The `sourdough new` command.
 *
 * Before it writes anything for a real app, the command checks that the
 * developer can read Playbook (a private package on git.wayne.edu): the
 * generated app requires it by default, so composer install would fail
 * otherwise. The check is skipped for --no-playbook, for non-interactive
 * inputs and in CI, where the app is still generated with the wiring.
 *
 * Exit codes (matching PROCTOR): 0 done, 1 refused (invalid flags, no
 * Playbook access, non-empty dir), 2 tool error.
 */
final class NewCommand extends Command
{
    public function __construct(
        private readonly ?Describe $describe = null,
        private readonly ?SkeletonSource $skeletonSource = null,
        private readonly ?PlaybookAccess $playbookAccess = null,
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
            ->addOption('inertia', null, InputOption::VALUE_NONE, 'Use Inertia/Vue (implies --laravel).')
            ->addOption('sail', null, InputOption::VALUE_NONE, 'Run the Laravel app on Sail for local work (compose.yaml; needs WSL2 on Windows).')
            ->addOption('docker', null, InputOption::VALUE_NONE, 'Generate a Dockerfile and compose.yaml for local work (native on every OS; amd64 and arm64).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and write nothing.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The app name (default: the basename of dir).')
            ->addOption('no-input', null, InputOption::VALUE_NONE, 'Never prompt. Sourdough has no prompts, so this is accepted and documented.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With --dry-run, print the plan as JSON.')
            ->addOption('describe', null, InputOption::VALUE_REQUIRED, 'Describe what the app does in a sentence; a model plans it. Needs the SOURDOUGH_LLM_* env vars.')
            ->addOption('plan-only', null, InputOption::VALUE_NONE, 'With --describe, print the plan as JSON and write nothing.')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'A ready-made plan file (JSON, or YAML ending in .yaml or .yml, matching resources/plan.schema.json)')
            ->addOption('playbook', null, InputOption::VALUE_NONE, 'Kept for compatibility: Playbook is now the default.')
            ->addOption('no-playbook', null, InputOption::VALUE_NONE, 'Skip the PLAYBOOK wiring. For CI and automated tests only; WSU apps need Playbook.')
            ->addOption('no-advisor', null, InputOption::VALUE_NONE, 'Skip the ADVISOR wiring: no waynestate/advisor in require-dev, no .mcp.json in .gitignore, no README section.');
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
        $noAdvisor = (bool) $input->getOption('no-advisor');

        if (null !== $describe && null !== $planFile) {
            $this->errorOutput($output)->writeln('--describe and --plan are mutually exclusive.');
            return 1;
        }

        if ($playbook && $noPlaybook) {
            $this->errorOutput($output)->writeln('--playbook and --no-playbook are mutually exclusive.');
            return 1;
        }

        // Playbook is the default now; --playbook stays so old scripts keep
        // working, and --no-playbook turns the wiring off entirely.
        $mode = PlaybookMode::Require;
        if ($noPlaybook) {
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
            return $this->runPlanFile($input, $output, (string) $planFile, $dir, $nameOption, $sail, $docker, $dryRun, $json, $mode, !$noAdvisor);
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

        return $this->generate($input, $output, AppPlan::forShape($permutation, $name), $dir, $sail, $docker, $dryRun, $json, $flags, $mode, !$noAdvisor);
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
        InputInterface $input,
        OutputInterface $output,
        string $planFile,
        string $dir,
        ?string $nameOption,
        bool $sail,
        bool $docker,
        bool $dryRun,
        bool $json,
        PlaybookMode $playbook,
        bool $advisor = true,
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

        return $this->generate($input, $output, $plan->withName($name), $dir, $sail, $docker, $dryRun, $json, $flags, $playbook, $advisor);
    }

    /**
     * Bake $appPlan into $dir, then save the plan there as
     * `.sourdough/plan.yaml` (ADR-0014, decision 3).
     *
     * @param array<string, bool> $flags the raw/laravel/vue/inertia flags
     */
    private function generate(
        InputInterface $input,
        OutputInterface $output,
        AppPlan $appPlan,
        string $dir,
        bool $sail,
        bool $docker,
        bool $dryRun,
        bool $json,
        array $flags,
        PlaybookMode $playbook,
        bool $advisor = true,
    ): int {
        $permutation = $appPlan->permutation;
        $name = $appPlan->name;

        try {
            $plan = Plan::forPermutation($permutation, $dir, $sail, $docker, $advisor);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        if ($dryRun) {
            // A dry run still checks access, so the developer learns about a
            // missing Playbook read before they commit to the real run. It
            // never refuses: the plan is printed either way.
            $access = $this->detectPlaybook($input, $playbook);

            if ($json) {
                $encoded = json_encode([
                    'permutation' => $permutation->value,
                    'label' => $permutation->label(),
                    'dir' => $dir,
                    'playbook' => [
                        'mode' => $playbook->value,
                        'access' => $access['status'],
                        'via' => $access['via'],
                    ],
                    'steps' => $plan->steps,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (false === $encoded) {
                    $this->errorOutput($output)->writeln('Could not encode the plan as JSON.');
                    return 2;
                }
                $output->writeln($encoded);
            } else {
                $this->printPlan($output, $plan);
                $output->writeln(sprintf('Playbook: %s (access: %s)', $playbook->value, $access['status']));
                if ('no-access' === $access['status']) {
                    $this->errorOutput($output)->writeln('No Playbook access: a real run would stop until you can read the package.');
                }
            }

            return 0;
        }

        // All five shapes bake, so there is no "not available" exit here any
        // more. The name is validated before the access check so a bad name never
        // triggers a network call.
        try {
            RawGenerator::assertValidName($name);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        }

        $access = $this->detectPlaybook($input, $playbook);

        if (PlaybookMode::Require === $playbook && 'no-access' === $access['status']) {
            $error = $this->errorOutput($output);
            $error->writeln('You need read access to Playbook (a private package on git.wayne.edu) to make a Wayne State app.');
            foreach (PlaybookAccess::howTo() as $line) {
                $error->writeln($line);
            }

            return 1;
        }

        $this->printPlan($output, $plan);

        $answers = $flags;
        if (PlaybookMode::Require === $playbook) {
            $answers['playbook'] = true;
        }
        if (PlaybookMode::None === $playbook) {
            $answers['no-playbook'] = true;
        }
        if (!$advisor) {
            $answers['no-advisor'] = true;
        }

        try {
            // The shape, then --docker or --sail (a raw bake with either
            // never reaches here; Plan refuses it first), then the manifest,
            // which records sail and docker so `sourdough feed` can bake the
            // same loaf again. Feed bakes through the same Baker.
            (new Baker($this->skeletonSource))->bake($permutation, $dir, $name, $answers, $playbook, $advisor, $sail, $docker);

            // The plan goes in the jar too, next to the manifest but not in
            // it: the app's record of what it asked for. Feed never bakes it
            // again, so feed never rewrites it.
            $appPlan->write($dir);
        } catch (\InvalidArgumentException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 1;
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());
            return 2;
        }

        if (PlaybookMode::Require === $playbook && 'offline' === $access['status']) {
            $this->errorOutput($output)->writeln('Could not reach git.wayne.edu to check your Playbook access; composer install will need it.');
        }

        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln(sprintf('  cd %s', $dir));
        $output->writeln('  composer install');
        if ($advisor) {
            $output->writeln('  php vendor/bin/advisor install --client=claude --write');
        }
        if ($docker) {
            $output->writeln('  cp .env.example .env');
            $output->writeln('  composer dev-up');
            $output->writeln('  docker compose exec app php artisan key:generate');
            $output->writeln('  docker compose exec app php artisan migrate');
            if (Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation) {
                $output->writeln('  (Vite runs as its own service on port 5173)');
            }
        } elseif (Permutation::LaravelBlade === $permutation) {
            if ($sail) {
                $output->writeln('  cp .env.example .env');
                $output->writeln('  ./vendor/bin/sail up -d');
                $output->writeln('  ./vendor/bin/sail artisan key:generate');
                $output->writeln('  ./vendor/bin/sail artisan migrate');
                $output->writeln('  (Windows: run sail from WSL2, or use --docker)');
            } else {
                $output->writeln('  cp .env.example .env');
                $output->writeln('  php artisan key:generate');
                $output->writeln('  php artisan migrate');
                $output->writeln('  php artisan serve');
            }
        } elseif (Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation) {
            if ($sail) {
                $output->writeln('  cp .env.example .env');
                $output->writeln('  ./vendor/bin/sail up -d');
                $output->writeln('  ./vendor/bin/sail artisan key:generate');
                $output->writeln('  ./vendor/bin/sail artisan migrate');
                $output->writeln('  ./vendor/bin/sail npm install');
                $output->writeln('  ./vendor/bin/sail npm run dev');
                $output->writeln('  (Windows: run sail from WSL2, or use --docker)');
            } else {
                $output->writeln('  cp .env.example .env');
                $output->writeln('  php artisan key:generate');
                $output->writeln('  php artisan migrate');
                $output->writeln('  npm install');
                $output->writeln('  npm run build');
                $output->writeln('  php artisan serve');
            }
        } elseif (Permutation::RawVue === $permutation) {
            // The same composer serve as raw, after Vite has built the Vue
            // app once; npm run dev in a second terminal adds hot reload.
            $output->writeln('  npm install');
            $output->writeln('  npm run build');
            $output->writeln('  composer serve');
            $output->writeln('  (npm run dev in a second terminal for hot reload)');
        } else {
            // composer serve sets APP_ENV=local for the raw app, which has no
            // .env loader; a plain php -S would boot as production.
            $output->writeln('  composer serve');
        }

        if (PlaybookMode::Suggest === $playbook) {
            $output->writeln('');
            $output->writeln('WSU values: see "WSU values (PLAYBOOK)" in README.md.');
        } elseif (PlaybookMode::Require === $playbook) {
            $output->writeln('');
            $output->writeln('WSU values: Playbook installs with composer install. Check it any time with: composer playbook');
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

    private function playbookService(): PlaybookAccess
    {
        return $this->playbookAccess ?? new PlaybookAccess();
    }

    /**
     * Ask whether the developer can read Playbook, per the mode and the
     * input. None never asks; a non-interactive input or a CI environment
     * skips the network check and reports "skipped", so automated runs stay
     * hermetic.
     *
     * @return array{status: string, via: string|null}
     */
    private function detectPlaybook(InputInterface $input, PlaybookMode $mode): array
    {
        if (PlaybookMode::None === $mode || !$input->isInteractive()) {
            return ['status' => 'skipped', 'via' => null];
        }

        // getenv() returns array<string, string>, which is all isCi needs.
        $env = getenv();
        if (PlaybookAccess::isCi($env)) {
            return ['status' => 'skipped', 'via' => null];
        }

        $result = $this->playbookService()->detect();

        return ['status' => $result['status'], 'via' => $result['via']];
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
