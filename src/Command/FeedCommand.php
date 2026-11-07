<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Feed\FeedAction;
use Waynestate\Sourdough\Feed\FeedException;
use Waynestate\Sourdough\Feed\FeedPlan;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\SkeletonSource;

/**
 * The `sourdough feed` command: bring template updates into an app made
 * with `sourdough new`, without clobbering what the app has changed. See
 * Feed for how.
 *
 * Exit codes (matching PROCTOR's 0/1/2):
 * - a feed:    0 fed cleanly (or nothing to feed), 1 fed with conflicts to
 *              resolve, 2 tool error (no manifest, no git, a dirty tree,
 *              leftovers from the last feed, a newer manifest, a failed bake)
 * - --dry-run: 0 the app is fresh, 1 feeding would change files, 2 tool
 *              error. That makes the dry run a CI gate for "the app has
 *              fallen behind the template".
 */
final class FeedCommand extends Command
{
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
            ->setName('feed')
            ->setDescription('Bring Sourdough template updates into an app without clobbering what it has changed.')
            ->addArgument('dir', InputArgument::OPTIONAL, 'The app directory to feed (default: the current directory).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and write nothing; exit 1 when feeding would change files.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan or the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $input->getArgument('dir');
        $dir = is_string($dir) && '' !== $dir ? $dir : (getcwd() ?: '.');
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');

        $feed = new Feed($this->skeletonSource, $this->git, $this->version);

        try {
            if (!$dryRun) {
                $feed->assertReady($dir);
            }
            $plan = $feed->plan($dir);
            if (!$dryRun) {
                $feed->apply($plan);
            }
        } catch (FeedException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln('sourdough feed failed: ' . $e->getMessage());

            return 2;
        }

        $exit = $this->exitCode($plan, $dryRun);

        if ($json) {
            $data = $plan->toArray($dryRun) + ['exit' => $exit];
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $encoded) {
                $this->errorOutput($output)->writeln('Could not encode the plan as JSON.');

                return 2;
            }
            $output->writeln($encoded);

            return $exit;
        }

        $this->report($output, $plan, $dryRun);

        return $exit;
    }

    private function exitCode(FeedPlan $plan, bool $dryRun): int
    {
        if ($dryRun) {
            return $plan->hasChanges() ? 1 : 0;
        }

        return $plan->conflicts() > 0 ? 1 : 0;
    }

    private function report(OutputInterface $output, FeedPlan $plan, bool $dryRun): void
    {
        foreach ($plan->notes as $note) {
            $output->writeln('note: ' . $note);
        }

        if (!$plan->hasChanges()) {
            $output->writeln(sprintf(
                'Fresh: the app already has everything Sourdough %s bakes into a %s app. Nothing to feed.',
                $plan->toVersion,
                $plan->template,
            ));

            return;
        }

        $output->writeln(sprintf(
            '%s %s app: Sourdough %s to %s%s',
            $dryRun ? 'Would feed the' : 'Feeding the',
            $plan->template,
            $plan->fromVersion,
            $plan->toVersion,
            $dryRun ? ' (dry run: nothing written)' : '',
        ));

        $width = 0;
        foreach (FeedAction::cases() as $action) {
            $width = max($width, strlen($action->value));
        }
        foreach ($plan->steps as $step) {
            $output->writeln(sprintf('  %s  %s (%s)', str_pad($step->action->value, $width), $step->path, $step->detail));
        }

        if ([] !== $plan->bases) {
            $output->writeln(sprintf(
                '%s the template\'s version of %d changed %s in %s/, so the next feed can merge from it.',
                $dryRun ? 'Would keep' : 'Kept',
                count($plan->bases),
                1 === count($plan->bases) ? 'file' : 'files',
                Feed::BASE_DIR,
            ));
        }

        $parts = [];
        foreach (FeedAction::cases() as $action) {
            $count = $plan->count($action);
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $action->value);
            }
        }
        $output->writeln(($dryRun ? 'Plan: ' : 'Fed: ') . implode(', ', $parts) . '.');

        if ($dryRun) {
            $output->writeln('Run sourdough feed without --dry-run to apply it.');

            return;
        }

        if ($plan->conflicts() > 0) {
            $output->writeln('Next: resolve the conflicts (git diff --check lists leftover markers), run your checks, then commit.');
        } else {
            $output->writeln('Next: review with git status and git diff, run your checks, then commit.');
        }
        $output->writeln('To undo the whole feed: git restore . && git clean -fd (the tree was clean before it ran).');
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
