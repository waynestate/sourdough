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
use Waynestate\Sourdough\Feed\FeedException;
use Waynestate\Sourdough\Feed\FeedPlan;
use Waynestate\Sourdough\Feed\Git;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\ShapeSupport;

/**
 * The `sourdough check` command: the baker's poke test. One read-only run
 * that says whether an app is fed and whether its shape is still looked
 * after, so a pipeline (or a person) needs one command instead of three.
 *
 * It puts together what the other commands already know:
 * - `sourdough manifest --check`: the managed files the app changed or
 *   deleted. Reported, never a failure on its own: the app owns its edits
 *   and feed merges them.
 * - `sourdough feed --dry-run`: whether feeding would change files.
 * - the shape's support: its catalog status (resources/shapes.json) and the
 *   end of security support of the line it runs on (CREED's policy).
 *
 * It writes nothing, needs no clean tree and never calls assertReady().
 *
 * Exit codes (matching PROCTOR's 0/1/2): 0 fed and supported, 1 needs
 * attention (behind the template, a deprecated or retired shape, or a
 * framework line past its security support), 2 tool error (no manifest,
 * no git, a manifest this Sourdough cannot read).
 */
final class CheckCommand extends Command
{
    private const LABEL_WIDTH = 13;

    public function __construct(
        private readonly ?SkeletonSource $skeletonSource = null,
        private readonly ?Git $git = null,
        private readonly ?string $version = null,
        private readonly ?\DateTimeImmutable $today = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('check')
            ->setDescription('Check an app in one read-only run: is it fed, and is its shape supported?')
            ->addArgument('dir', InputArgument::OPTIONAL, 'The app directory to check (default: the current directory).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $input->getArgument('dir');
        $dir = is_string($dir) && '' !== $dir ? $dir : (getcwd() ?: '.');
        $json = (bool) $input->getOption('json');

        try {
            $drift = Manifest::check($dir);
            $plan = (new Feed($this->skeletonSource, $this->git, $this->version))->plan($dir);
        } catch (FeedException $e) {
            $this->errorOutput($output)->writeln($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $this->errorOutput($output)->writeln('sourdough check failed: ' . $e->getMessage());

            return 2;
        }

        [$permutation, $floor, $support] = $this->support($plan);
        $problems = $this->problems($plan, $floor, $support);
        $exit = [] === $problems ? 0 : 1;

        if ($json) {
            $data = [
                'dir' => $dir,
                'template' => $plan->template,
                'manifest' => ['changed' => $drift['changed'], 'missing' => $drift['missing']],
                'feed' => [
                    'from' => $plan->fromVersion,
                    'to' => $plan->toVersion,
                    'changes' => count($plan->steps),
                    'fresh' => !$plan->hasChanges(),
                ],
                'support' => null !== $permutation && null !== $floor && null !== $support
                    ? $support->toArray($permutation, $floor)
                    : null,
                'problems' => $problems,
                'exit' => $exit,
            ];
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $encoded) {
                $this->errorOutput($output)->writeln('Could not encode the check as JSON.');

                return 2;
            }
            $output->writeln($encoded);

            return $exit;
        }

        if (null !== $permutation && null !== $floor && null !== $support) {
            $output->writeln($this->label('Support:') . $support->line($permutation, $floor));
        }

        $output->writeln($this->label('Manifest:') . ([] === $drift['changed'] && [] === $drift['missing']
            ? 'clean: every managed file matches the manifest'
            : sprintf('%d changed, %d missing (the app owns these; feed merges them)', count($drift['changed']), count($drift['missing']))));

        $output->writeln($this->label('Feed:') . ($plan->hasChanges()
            ? sprintf('behind: Sourdough %s to %s, %d %s would change', $plan->fromVersion, $plan->toVersion, count($plan->steps), $this->files(count($plan->steps)))
            : sprintf('fresh: nothing to feed from Sourdough %s', $plan->toVersion)));

        if ([] === $problems) {
            $output->writeln(sprintf('OK: the %s app is fed and supported.', $plan->template));

            return 0;
        }

        $output->writeln('Needs attention:');
        foreach ($problems as $problem) {
            $output->writeln('  - ' . $problem);
        }

        return 1;
    }

    /**
     * What makes the check exit 1, in order: the app is behind the
     * template, its shape is deprecated or retired, or the line it runs on
     * is past its security support. A shape whose support could not be
     * worked out adds nothing: the check never fails for want of a fact.
     *
     * @return list<string>
     */
    private function problems(FeedPlan $plan, ?PhpFloor $floor, ?ShapeSupport $support): array
    {
        $problems = [];

        if ($plan->hasChanges()) {
            $count = count($plan->steps);
            $problems[] = sprintf(
                'behind: feeding to Sourdough %s would change %d %s; run sourdough feed --dry-run for the plan',
                $plan->toVersion,
                $count,
                $this->files($count),
            );
        }

        $notice = $support?->notice($plan->template);
        if (null !== $notice) {
            $problems[] = $notice;
        }

        if (null !== $floor) {
            [$label, $until] = null !== $floor->laravelMajor
                ? ['Laravel ' . $floor->laravelMajor, $floor->laravelSecurityUntil]
                : ['PHP ' . $floor->floor, $floor->floorSecurityUntil];
            $today = ($this->today ?? new \DateTimeImmutable('today'))->format('Y-m-d');
            if (null !== $until && $until < $today) {
                $problems[] = sprintf('past support: %s stopped getting security fixes on %s', $label, $until);
            }
        }

        return $problems;
    }

    /**
     * The permutation, PHP floor and shape catalog behind the Support line,
     * worked out as `sourdough feed` does. Anything that cannot be worked
     * out comes back null.
     *
     * @return array{0: Permutation|null, 1: PhpFloor|null, 2: ShapeSupport|null}
     */
    private function support(FeedPlan $plan): array
    {
        $permutation = Permutation::tryFrom($plan->template);
        if (null === $permutation) {
            return [null, null, null];
        }

        try {
            return [$permutation, PhpFloor::forPermutation($permutation), ShapeSupport::load()];
        } catch (\Throwable) {
            return [null, null, null];
        }
    }

    private function label(string $label): string
    {
        return str_pad($label, self::LABEL_WIDTH);
    }

    private function files(int $count): string
    {
        return 1 === $count ? 'file' : 'files';
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
