<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\Plan;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The adopt engine: write the manifest an existing app would have if
 * Sourdough had baked it, so `sourdough feed` can take over from there
 * (ADR-0014, decision 4; the behavior is design/sourdough-adopt-spec.md).
 *
 * 1. Answer for the shape the way `sourdough new` would, plus
 *    `adopted: true`, which every later feed carries along.
 * 2. Bake that shape into a scratch dir through the same Baker `new` and
 *    feed use, so the loaf the app is compared with is today's loaf.
 * 3. Look at each path the bake wrote, and only those, in the app:
 *    - missing: no file there. Left out of the manifest, so the next feed
 *      adds it through its "new in the template" path;
 *    - seeded: a file the template seeds (README.md, .env.example) and the
 *      app has. The app's, as in every Sourdough app;
 *    - match: the app's file is the template's, line endings aside.
 *      Managed, so feed keeps it current;
 *    - yours: anything else. Never written, merged or reported, until the
 *      day the app's copy equals the template's (Feed::plan()).
 *    Every recorded hash is the template's, never the app's.
 * 4. Apply writes `.sourdough/manifest.json` and nothing else: no
 *    `.sourdough/base/` copies and no plan.yaml.
 *
 * The engine checks no git state: the command does that. It refuses only an
 * app that already has a manifest, because adopting over one would lose the
 * record feed stands on. Not every loaf came from our starter, but every
 * loaf can be fed.
 */
final class Adopt
{
    /**
     * The options plan() takes, as the command's flags name them.
     *
     * @var list<string>
     */
    public const OPTIONS = ['sail', 'docker', 'no-playbook', 'no-advisor'];

    private const DETAIL_MATCH = 'same as the template: Sourdough keeps it current';

    private const DETAIL_YOURS = 'differs from the template: yours, never overwritten';

    private const DETAIL_SEEDED = 'yours, as in every Sourdough app';

    private const DETAIL_MISSING = 'the next sourdough feed adds it';

    private readonly Baker $baker;

    /**
     * @param string|null $version the running Sourdough version to record; null means the installed package's
     */
    public function __construct(
        ?SkeletonSource $skeletonSource = null,
        private readonly ?string $version = null,
    ) {
        $this->baker = new Baker($skeletonSource);
    }

    /**
     * Work out what adopting $dir as $shape named $name would record. Writes
     * nothing in the app.
     *
     * @param array<string, bool> $options any of OPTIONS set to true: the oven (sail or docker), no-playbook, no-advisor
     *
     * @throws AdoptException            when the dir is not there or already has a manifest
     * @throws \InvalidArgumentException when an option is unknown, the oven does not fit the shape, or the name is invalid
     * @throws \RuntimeException         when the bake fails or a file cannot be read
     */
    public function plan(string $dir, Permutation $shape, string $name, array $options = []): AdoptPlan
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown adopt option "%s"; the options are: %s.',
                implode('", "', $unknown),
                implode(', ', self::OPTIONS),
            ));
        }

        $dir = self::resolveDir($dir);
        self::assertNotAdopted($dir);

        $sail = true === ($options['sail'] ?? false);
        $docker = true === ($options['docker'] ?? false);
        $advisor = true !== ($options['no-advisor'] ?? false);
        $playbook = true === ($options['no-playbook'] ?? false) ? PlaybookMode::None : PlaybookMode::Require;

        // The same refusals `new` makes for an oven on a raw shape, or both ovens.
        Plan::forPermutation($shape, $dir, $sail, $docker, $advisor);

        // The answers `new` records for the same choices, so every later
        // feed bakes the same loaf. Baker adds name, sail and docker.
        $answers = $shape->flags();
        if (PlaybookMode::None === $playbook) {
            $answers['no-playbook'] = true;
        } else {
            $answers['playbook'] = true;
        }
        if (!$advisor) {
            $answers['no-advisor'] = true;
        }
        $answers['adopted'] = true;

        $version = $this->version ?? Manifest::packageVersion();
        $baked = $this->bakeFresh($shape, $name, $answers, $playbook, $advisor, $sail, $docker, $version);

        $entries = [];
        $files = [];
        $template = Manifest::files($baked);
        ksort($template, SORT_STRING);
        foreach ($template as $path => $info) {
            $status = $this->classify($dir . '/' . $path, $info['class'], $info['sha256']);
            $entries[] = new AdoptEntry($path, $status, self::detail($status));

            $class = $status->manifestClass();
            if (null !== $class) {
                $files[$path] = ['class' => $class, 'sha256' => $info['sha256']];
            }
        }

        $manifest = [
            'answers' => $baked['answers'] ?? [],
            'files' => $files,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => $baked['schema'] ?? Manifest::SCHEMA,
            'sourdough_version' => $baked['sourdough_version'] ?? $version,
            'template' => $baked['template'] ?? $shape->value,
        ];

        return new AdoptPlan($dir, $shape, $name, $version, $entries, $manifest);
    }

    /**
     * Write $plan's manifest into its app: `.sourdough/manifest.json`, the
     * only file adopt ever writes.
     *
     * @throws AdoptException    when the app gained a manifest since the plan
     * @throws \RuntimeException when the manifest cannot be written
     */
    public function apply(AdoptPlan $plan): void
    {
        self::assertNotAdopted($plan->dir);

        Manifest::writeData($plan->dir, $plan->manifest);
    }

    /**
     * The status of one template path in the app. The first rule that
     * applies wins: missing, seeded, match, yours.
     */
    private function classify(string $appPath, string $class, string $sha256): AdoptStatus
    {
        if (!is_file($appPath)) {
            return AdoptStatus::Missing;
        }

        if ('seeded' === $class) {
            return AdoptStatus::Seeded;
        }

        $content = file_get_contents($appPath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $appPath));
        }

        return Feed::matches($content, $sha256) ? AdoptStatus::Match : AdoptStatus::Yours;
    }

    private static function detail(AdoptStatus $status): string
    {
        return match ($status) {
            AdoptStatus::Match => self::DETAIL_MATCH,
            AdoptStatus::Yours => self::DETAIL_YOURS,
            AdoptStatus::Seeded => self::DETAIL_SEEDED,
            AdoptStatus::Missing => self::DETAIL_MISSING,
        };
    }

    /**
     * Bake $shape into a scratch dir and read its manifest back. The scratch
     * dir is gone when this returns, whatever happened.
     *
     * @param array<string, string|bool> $answers
     *
     * @return array<string, mixed>
     */
    private function bakeFresh(
        Permutation $shape,
        string $name,
        array $answers,
        PlaybookMode $playbook,
        bool $advisor,
        bool $sail,
        bool $docker,
        string $version,
    ): array {
        $scratch = sys_get_temp_dir() . '/sourdough-adopt-' . bin2hex(random_bytes(8));
        $bakeDir = $scratch . '/app';
        try {
            if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $scratch));
            }

            $this->baker->bake($shape, $bakeDir, $name, $answers, $playbook, $advisor, $sail, $docker, $version);

            return Manifest::read($bakeDir);
        } finally {
            self::removeDir($scratch);
        }
    }

    /**
     * $dir resolved, with forward slashes and no trailing slash.
     *
     * @throws AdoptException when it is not there or is a file
     */
    private static function resolveDir(string $dir): string
    {
        $resolved = '' === $dir ? false : realpath($dir);
        if (false === $resolved) {
            throw new AdoptException(sprintf('The app directory "%s" does not exist.', $dir));
        }
        if (!is_dir($resolved)) {
            throw new AdoptException(sprintf('"%s" is a file, not a directory: pass the app\'s root folder.', $dir));
        }

        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    /**
     * @throws AdoptException when $dir already has a Sourdough manifest
     */
    private static function assertNotAdopted(string $dir): void
    {
        if (file_exists($dir . '/' . Manifest::FILE)) {
            throw new AdoptException(sprintf(
                '"%s" already has a Sourdough manifest: Sourdough made or adopted this app. Bring it up to date with sourdough feed. To adopt it again with another --shape or --name, delete .sourdough/, commit, and run adopt again.',
                $dir,
            ));
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
