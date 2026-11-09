<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\Adopt\Adopt;
use Waynestate\Sourdough\Adopt\AdoptEntry;
use Waynestate\Sourdough\Adopt\AdoptException;
use Waynestate\Sourdough\Adopt\AdoptPlan;
use Waynestate\Sourdough\Adopt\AdoptStatus;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\Plan;
use Waynestate\Sourdough\ShapeSupport;

/**
 * The `sourdough adopt` command: write the manifest an existing app would
 * have if Sourdough had baked it, so `sourdough feed` can look after it
 * from then on. The engine is Adopt; this is the front of the shop: the
 * options, the refusals, and the report (design/sourdough-adopt-spec.md,
 * sections 1, 3 and 4).
 *
 * The checks run in the spec's order: the shape, the oven, the folder and
 * any record already in it, the name, then git (on PATH, a work tree, a
 * clean tree) and a folder adopt can write in. A dry run turns the git and
 * writable checks into notes, because only a real adopt writes.
 *
 * Exit codes (as `sourdough new`): 0 adopted, or a dry run printed its
 * plan; 1 refused (fix the command or the repo and run again); 2 tool error
 * (no git, a folder it cannot write, a failed bake). `yours` and `missing`
 * are normal outcomes: adopt is not a CI gate, `feed --dry-run` is.
 *
 * Every starter was once somebody's leftover dough. This one gets a name.
 */
final class AdoptCommand extends Command
{
    private const EXIT_REFUSED = 1;

    private const EXIT_TOOL = 2;

    /** Status column width: the longest status, "missing" and "seeded" padded to it. */
    private const STATUS_WIDTH = 7;

    /** The order the report lists statuses in: what needs a look first. */
    private const ORDER = [AdoptStatus::Yours, AdoptStatus::Seeded, AdoptStatus::Missing, AdoptStatus::Match];

    /** The summary's order, the spec's sample line. */
    private const SUMMARY = [AdoptStatus::Match, AdoptStatus::Yours, AdoptStatus::Seeded, AdoptStatus::Missing];

    public function __construct(
        private readonly ?SkeletonSource $skeletonSource = null,
        private readonly ?Git $git = null,
        private readonly ?string $version = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('adopt')
            ->setDescription('Write the Sourdough manifest for an app Sourdough did not make, so sourdough feed can keep it current.')
            ->addArgument('dir', InputArgument::OPTIONAL, 'The app\'s root folder (default: the current directory).')
            ->addOption('shape', null, InputOption::VALUE_REQUIRED, 'What the app already is: ' . self::shapeList(' or ') . '.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The name the template writes into files (default: the basename of dir).')
            ->addOption('sail', null, InputOption::VALUE_NONE, 'The app runs on Sail (Laravel shapes only).')
            ->addOption('docker', null, InputOption::VALUE_NONE, 'The app runs on Sourdough\'s Docker files (Laravel shapes only). Leave it off when the app has its own Docker setup.')
            ->addOption('no-playbook', null, InputOption::VALUE_NONE, 'The app does not require Playbook, as with sourdough new --no-playbook.')
            ->addOption('no-advisor', null, InputOption::VALUE_NONE, 'The app has no ADVISOR wiring, as with sourdough new --no-advisor.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the report and write nothing.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $given = $input->getArgument('dir');
        $given = is_string($given) && '' !== $given ? $given : (getcwd() ?: '.');
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');
        $sail = (bool) $input->getOption('sail');
        $docker = (bool) $input->getOption('docker');
        $shapeOption = $input->getOption('shape');
        $nameOption = $input->getOption('name');

        // 1 and 2: the shape, named by the person who knows the app.
        if (!is_string($shapeOption) || '' === $shapeOption) {
            $guess = self::guessShape($given);

            return $this->refuse($output, sprintf('--shape is required: %s.', self::shapeList(' or '))
                . (null === $guess ? '' : sprintf(' This app looks like %s (%s).', $guess[0]->value, $guess[1])));
        }
        $shape = Permutation::tryFrom($shapeOption);
        if (null === $shape) {
            $retired = ShapeSupport::load()->notice($shapeOption);

            return $this->refuse($output, sprintf('Unknown shape "%s". The five shapes are: %s.', $shapeOption, self::shapeList(', '))
                . (null === $retired ? '' : ' ' . $retired));
        }

        // 3: the oven, with new's own words.
        try {
            Plan::forPermutation($shape, $given, $sail, $docker);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($output, $e->getMessage());
        }

        // 4 to 7: the folder, and any record already in it.
        try {
            $dir = Adopt::target($given);
        } catch (AdoptException $e) {
            return $this->refuse($output, $e->getMessage());
        }

        // 8: the name the template writes into files.
        $name = is_string($nameOption) && '' !== $nameOption ? $nameOption : basename($dir);
        $problem = self::nameProblem($name);
        if (null !== $problem) {
            return $this->refuse($output, $problem . ' Pass --name "<a valid name>".');
        }

        // 9 to 12: git and the folder. A dry run writes nothing, so it only says.
        [$notes, $refusal] = $this->readiness($dir, $dryRun);
        if (null !== $refusal) {
            $this->errorOutput($output)->writeln($refusal[0]);

            return $refusal[1];
        }

        $options = array_filter([
            'sail' => $sail,
            'docker' => $docker,
            'no-playbook' => (bool) $input->getOption('no-playbook'),
            'no-advisor' => (bool) $input->getOption('no-advisor'),
        ]);

        $adopt = new Adopt($this->skeletonSource, $this->version);

        // 13: the fresh bake adopt compares the app with.
        try {
            $plan = $adopt->plan($dir, $shape, $name, $options);
        } catch (AdoptException|\InvalidArgumentException $e) {
            return $this->refuse($output, $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln(sprintf(
                'sourdough adopt failed: could not bake a fresh %s app to compare with: %s.%s',
                $shape->value,
                rtrim($e->getMessage(), '.'),
                self::isLaravel($shape) ? ' The Laravel shapes need Composer and the network (or a warm Composer cache).' : '',
            ));

            return self::EXIT_TOOL;
        }

        // 14: the one write.
        if (!$dryRun) {
            try {
                self::quietly(static fn() => $adopt->apply($plan));
            } catch (AdoptException $e) {
                return $this->refuse($output, $e->getMessage());
            } catch (\RuntimeException $e) {
                $this->errorOutput($output)->writeln('sourdough adopt failed: ' . $e->getMessage());

                return self::EXIT_TOOL;
            }
        }

        $notes = array_merge($notes, $plan->notes);

        if ($json) {
            $data = $plan->toArray($dryRun);
            $data['exit'] = 0;
            $data['notes'] = $notes;
            ksort($data, SORT_STRING);
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $encoded) {
                $this->errorOutput($output)->writeln('Could not encode the report as JSON.');

                return self::EXIT_TOOL;
            }
            $output->writeln($encoded);

            return 0;
        }

        $this->report($output, $plan, $notes, $dryRun);

        return 0;
    }

    /**
     * Refusals 9 to 12 on a real adopt; on a dry run, the notes that say a
     * real one would refuse.
     *
     * @return array{list<string>, array{string, int}|null} the dry run's notes, and the refusal (message, exit code) that stops a real adopt
     */
    private function readiness(string $dir, bool $dryRun): array
    {
        $git = $this->git ?? new Git();
        $notes = [];

        if (!$git->available()) {
            if (!$dryRun) {
                return [[], [
                    'sourdough adopt needs git on PATH (or SOURDOUGH_GIT set to it): adopt checks the tree, and feed, which comes next, merges with git.',
                    self::EXIT_TOOL,
                ]];
            }
            $notes[] = 'git is not available: a real adopt would fail.';
        } else {
            $problem = $git->workTreeProblem($dir);
            if (null !== $problem) {
                if (!$dryRun) {
                    return [[], [sprintf(
                        'sourdough adopt only runs inside a git repository, so the adoption is one commit you can review and every later feed can be undone. "%s": %s. Start one with: git init, git add -A, git commit -m "Before Sourdough".',
                        $dir,
                        rtrim($problem, '.'),
                    ), self::EXIT_REFUSED]];
                }
                $notes[] = 'Not inside a git repository: a real adopt would refuse.';
            } else {
                try {
                    $status = $git->status($dir);
                } catch (\RuntimeException $e) {
                    return [[], ['sourdough adopt failed: ' . $e->getMessage(), self::EXIT_TOOL]];
                }
                if ([] !== $status) {
                    if (!$dryRun) {
                        $shown = array_slice($status, 0, 10);
                        if (count($status) > 10) {
                            $shown[] = sprintf('... and %d more', count($status) - 10);
                        }

                        return [[], [
                            "Commit or stash your changes first: sourdough adopt compares your committed app with the template, and the adoption should be a commit of its own.\n  "
                            . implode("\n  ", $shown),
                            self::EXIT_REFUSED,
                        ]];
                    }
                    $notes[] = 'Uncommitted changes: a real adopt would refuse until the tree is clean.';
                }
            }
        }

        $record = $dir . '/.sourdough';
        if (!is_writable($dir) || (is_dir($record) && !is_writable($record))) {
            if (!$dryRun) {
                return [[], [sprintf(
                    'Cannot write "%s": the folder is not writable. adopt writes only that file; check the folder\'s permissions.',
                    $dir . '/' . Manifest::FILE,
                ), self::EXIT_TOOL]];
            }
            $notes[] = 'The folder is not writable: a real adopt would fail.';
        }

        return [$notes, null];
    }

    /**
     * The text report: a header, every line worth a look, the notes, the
     * summary, and what to do next.
     *
     * @param list<string> $notes the dry run's notes, then the plan's
     */
    private function report(OutputInterface $output, AdoptPlan $plan, array $notes, bool $dryRun): void
    {
        $output->writeln(sprintf(
            '%s "%s" as a %s app (%s) with Sourdough %s%s',
            $dryRun ? 'Would adopt' : 'Adopting',
            $plan->name,
            $plan->shape->value,
            $plan->shape->label(),
            $plan->version,
            $dryRun ? ' (dry run: nothing written)' : '.',
        ));
        $total = count($plan->entries);
        $output->writeln(sprintf(
            'Compared your app with a fresh %s bake: %d template %s.',
            $plan->shape->value,
            $total,
            1 === $total ? 'file' : 'files',
        ));

        $nameNotes = array_values(array_filter($notes, Adopt::isNameNote(...)));
        $otherNotes = array_values(array_filter($notes, static fn(string $note): bool => !Adopt::isNameNote($note)));

        $rows = self::rows($plan->entries, $output->isVerbose());
        if ([] !== $rows || [] !== $otherNotes) {
            $output->writeln('');
            $width = 0;
            foreach ($rows as [, $path]) {
                $width = max($width, strlen($path));
            }
            foreach ($rows as [$status, $path, $detail]) {
                $output->writeln(sprintf('  %s  %s  %s', str_pad($status, self::STATUS_WIDTH), str_pad($path, $width), $detail));
            }
            foreach ($otherNotes as $note) {
                $output->writeln('note: ' . $note);
            }
        }

        $parts = [];
        foreach (self::SUMMARY as $status) {
            $parts[] = sprintf('%d %s', $plan->count($status), $status->value);
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '%s %s.%s',
            $dryRun ? 'Plan:' : 'Adopted:',
            implode(', ', $parts),
            $dryRun ? '' : ' Wrote ' . Manifest::FILE . '.',
        ));
        foreach ($nameNotes as $note) {
            $output->writeln('note: ' . $note);
        }
        $output->writeln('Files the template does not ship are not recorded: Sourdough never reads or touches them.');

        if ($dryRun) {
            $output->writeln('Run sourdough adopt without --dry-run to write the manifest.');

            return;
        }

        $missing = $plan->count(AdoptStatus::Missing);
        $output->writeln(sprintf('Next: git add .sourdough && git commit -m "Adopt with Sourdough %s"', $plan->version));
        $output->writeln(0 === $missing
            ? '      then sourdough feed --dry-run shows the app is fresh.'
            : sprintf('      then sourdough feed --dry-run lists the %d %s the first feed adds.', $missing, 1 === $missing ? 'file' : 'files'));
    }

    /**
     * The report's lines, as [status, path, detail]: yours, seeded, then
     * missing (and match with -v), each sorted by path. Without -v, a
     * top-level folder whose every template file is missing folds into one
     * line, and match lines are left out.
     *
     * @param list<AdoptEntry> $entries sorted by path
     *
     * @return list<array{string, string, string}>
     */
    private static function rows(array $entries, bool $verbose): array
    {
        $folded = [];
        if (!$verbose) {
            $byFolder = [];
            foreach ($entries as $entry) {
                $slash = strpos($entry->path, '/');
                if (false !== $slash) {
                    $byFolder[substr($entry->path, 0, $slash + 1)][] = $entry->status;
                }
            }
            foreach ($byFolder as $folder => $statuses) {
                if ([] === array_filter($statuses, static fn(AdoptStatus $s): bool => AdoptStatus::Missing !== $s)) {
                    $folded[$folder] = count($statuses);
                }
            }
        }

        $rows = [];
        foreach (self::ORDER as $status) {
            if (AdoptStatus::Match === $status && !$verbose) {
                continue;
            }
            $shown = [];
            foreach ($entries as $entry) {
                if ($status !== $entry->status) {
                    continue;
                }
                $slash = strpos($entry->path, '/');
                $folder = false === $slash ? null : substr($entry->path, 0, $slash + 1);
                if (null !== $folder && isset($folded[$folder])) {
                    if (!isset($shown[$folder])) {
                        $shown[$folder] = true;
                        $count = $folded[$folder];
                        $rows[] = [
                            $status->value,
                            sprintf('%s (%d %s)', $folder, $count, 1 === $count ? 'file' : 'files'),
                            1 === $count ? $entry->detail : 'the next sourdough feed adds them',
                        ];
                    }

                    continue;
                }
                $rows[] = [$status->value, $entry->path, $entry->detail];
            }
        }

        return $rows;
    }

    /**
     * A hint for refusal 1, never a choice: the shape the app's files point
     * at, and the evidence. Null when the folder is not there to look in.
     *
     * @return array{Permutation, string}|null
     */
    private static function guessShape(string $dir): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }

        $composer = self::readJson($dir . '/composer.json');
        $package = self::readJson($dir . '/package.json');
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $vue = false;
        foreach (['dependencies', 'devDependencies'] as $key) {
            if (is_array($package[$key] ?? null) && array_key_exists('vue', $package[$key])) {
                $vue = true;
            }
        }

        if (is_file($dir . '/artisan')) {
            return match (true) {
                array_key_exists('inertiajs/inertia-laravel', $require) => [Permutation::LaravelInertia, 'it has artisan and inertiajs/inertia-laravel'],
                $vue => [Permutation::LaravelBladeVue, 'it has artisan and vue in package.json'],
                default => [Permutation::LaravelBlade, 'it has artisan, and neither inertiajs/inertia-laravel nor vue'],
            };
        }

        return $vue
            ? [Permutation::RawVue, 'it has vue in package.json and no artisan']
            : [Permutation::Raw, 'it has no artisan and no vue in package.json'];
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * The generator's own words when $name cannot be baked, or null.
     */
    private static function nameProblem(string $name): ?string
    {
        try {
            RawGenerator::assertValidName($name);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        if ('' === RawGenerator::slugify($name)) {
            return sprintf('The app name "%s" does not produce a valid slug: use letters, numbers and dashes.', $name);
        }

        return null;
    }

    private static function isLaravel(Permutation $shape): bool
    {
        return Permutation::Raw !== $shape && Permutation::RawVue !== $shape;
    }

    private static function shapeList(string $last): string
    {
        $values = array_map(static fn(Permutation $p): string => $p->value, Permutation::cases());
        $tail = array_pop($values);

        return implode(', ', $values) . $last . $tail;
    }

    /**
     * Run $write with PHP warnings held back: a failed mkdir() or
     * file_put_contents() warns before Manifest throws, and the exception
     * already says what went wrong, once.
     */
    private static function quietly(callable $write): void
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $write();
        } finally {
            restore_error_handler();
        }
    }

    private function refuse(OutputInterface $output, string $message): int
    {
        $this->errorOutput($output)->writeln($message);

        return self::EXIT_REFUSED;
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
