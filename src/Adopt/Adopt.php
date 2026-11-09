<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Adopt;

use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Feed\Feed;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\Plan;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

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
 * The edge cases (section 6 of the spec), checked before any content:
 * - a template path that is a symlink, or sits under a symlinked folder, is
 *   yours even when the link points nowhere, so feed never writes through a
 *   link; a file whose realpath() leaves the app root (a Windows junction
 *   is_link() does not see) is yours too;
 * - a file whose name differs in case only (readme.md for README.md on a
 *   case-insensitive disk) is yours, or feed would write over its name;
 * - a file too big to match, even after a CRLF expansion, is yours without
 *   being read, so a stray dump at a template path costs nothing.
 * A yours file that lacks the name the template writes into it says so,
 * and three of those add a note: the --name is probably wrong. A vendored
 * Squire kit older or newer than the one Sourdough bakes, and a deprecated
 * shape, add notes too.
 *
 * The engine checks no git state: the command does that. It refuses an app
 * that already has a manifest, because adopting over one would lose the
 * record feed stands on, and one with a `.sourdough/base/` left from a
 * manifest that is gone. Not every loaf came from our starter, but every
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

    private const DETAIL_SYMLINK = 'a symlink: Sourdough never writes through links';

    private const DETAIL_CASE = 'named %s here; the template calls it %s';

    private const DETAIL_NAME = 'differs; the template writes the app name here as "%s", and your file does not have it: if the app goes by another name, delete .sourdough/ and adopt again with --name';

    private const NOTE_NAME = '%d files differ where the template writes the app name; if the app goes by another name, delete .sourdough/ and adopt again with --name.';

    private const NOTE_KIT_OLDER = '.squire.json says Squire\'s kit %s; this Sourdough bakes kit %s. Run vendor/bin/squire update and commit: the next feed takes over each kit file that then matches.';

    private const NOTE_KIT_NEWER = '.squire.json says Squire\'s kit %s, newer than the kit this Sourdough bakes (%s); kit files that differ stay yours until Sourdough catches up, and the feed after that takes them over.';

    /** How many name hints it takes before the name itself looks wrong. */
    private const NAME_NOTE_AT = 3;

    /** The file `squire init` and `squire update` write into an app. */
    private const SQUIRE_RECORD = '.squire.json';

    /** The checked copy of Squire's kit that every bake installs. */
    private const SQUIRE_KIT = __DIR__ . '/../../templates/squire/manifest.json';

    private readonly Baker $baker;

    /**
     * @param string|null       $version the running Sourdough version to record; null means the installed package's
     * @param ShapeSupport|null $shapes  the shape catalog, for the deprecation note; null means resources/shapes.json
     */
    public function __construct(
        ?SkeletonSource $skeletonSource = null,
        private readonly ?string $version = null,
        private readonly ?ShapeSupport $shapes = null,
    ) {
        $this->baker = new Baker($skeletonSource);
    }

    /**
     * Work out what adopting $dir as $shape named $name would record. Writes
     * nothing in the app.
     *
     * @param array<string, bool> $options any of OPTIONS set to true: the oven (sail or docker), no-playbook, no-advisor
     *
     * @throws AdoptException            when the dir is not there, already has a manifest, or has a `.sourdough/base/` left over
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
        self::assertAdoptable($dir);

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
        [$baked, $contents] = $this->bakeFresh($shape, $name, $answers, $playbook, $advisor, $sail, $docker, $version);

        $notes = [];
        $deprecation = ($this->shapes ?? ShapeSupport::load())->notice($shape->value);
        if (null !== $deprecation) {
            $notes[] = $deprecation;
        }
        $kit = self::kitNote($dir);
        if (null !== $kit) {
            $notes[] = $kit;
        }

        $names = array_values(array_unique(array_filter([$name, RawGenerator::slugify($name)], static fn(string $n): bool => '' !== $n)));
        $listings = [];
        $hinted = 0;
        $entries = [];
        $files = [];
        $template = Manifest::files($baked);
        ksort($template, SORT_STRING);
        foreach ($template as $path => $info) {
            $entry = $this->classify($dir, $path, $info['class'], $info['sha256'], $contents[$path], $names, $listings);
            $entries[] = $entry;
            if (AdoptStatus::Yours === $entry->status && str_starts_with($entry->detail, (string) strstr(self::DETAIL_NAME, '"', true))) {
                ++$hinted;
            }

            $class = $entry->status->manifestClass();
            if (null !== $class) {
                $files[$path] = ['class' => $class, 'sha256' => $info['sha256']];
            }
        }
        if ($hinted >= self::NAME_NOTE_AT) {
            $notes[] = sprintf(self::NOTE_NAME, $hinted);
        }

        $manifest = [
            'answers' => $baked['answers'] ?? [],
            'files' => $files,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => $baked['schema'] ?? Manifest::SCHEMA,
            'sourdough_version' => $baked['sourdough_version'] ?? $version,
            'template' => $baked['template'] ?? $shape->value,
        ];

        return new AdoptPlan($dir, $shape, $name, $version, $entries, $manifest, $notes);
    }

    /**
     * Write $plan's manifest into its app: `.sourdough/manifest.json`, the
     * only file adopt ever writes.
     *
     * @throws AdoptException    when the app gained a manifest or a `.sourdough/base/` since the plan
     * @throws \RuntimeException when the manifest cannot be written
     */
    public function apply(AdoptPlan $plan): void
    {
        self::assertAdoptable($plan->dir);

        Manifest::writeData($plan->dir, $plan->manifest);
    }

    /**
     * What the app has at one template path. The first rule that applies
     * wins: a link (checked first, so a link to nowhere is not "missing"),
     * missing, a junction out of the app, another case, seeded, too big to
     * match, match, and yours.
     *
     * @param list<string>                $names    the app name and its slug, never empty strings
     * @param array<string, list<string>> $listings scandir() results by dir, shared across paths
     */
    private function classify(string $dir, string $path, string $class, string $sha256, string $template, array $names, array &$listings): AdoptEntry
    {
        $appPath = $dir . '/' . $path;

        if (self::throughLink($dir, $path)) {
            return new AdoptEntry($path, AdoptStatus::Yours, self::DETAIL_SYMLINK);
        }

        if (!is_file($appPath)) {
            return new AdoptEntry($path, AdoptStatus::Missing, self::DETAIL_MISSING);
        }

        if (!self::insideRoot($dir, $appPath)) {
            return new AdoptEntry($path, AdoptStatus::Yours, self::DETAIL_SYMLINK);
        }

        $actual = self::otherCase($dir, $path, $listings);
        if (null !== $actual) {
            return new AdoptEntry($path, AdoptStatus::Yours, sprintf(self::DETAIL_CASE, $actual, $path));
        }

        if ('seeded' === $class) {
            return new AdoptEntry($path, AdoptStatus::Seeded, self::DETAIL_SEEDED);
        }

        // CRLF at most doubles a file, so anything past twice the template
        // (plus slack) cannot match: yours, and never read. Measure twice,
        // knead once.
        $size = filesize($appPath);
        if (false !== $size && $size > 2 * strlen($template) + 1024) {
            return new AdoptEntry($path, AdoptStatus::Yours, self::DETAIL_YOURS);
        }

        $content = file_get_contents($appPath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $appPath));
        }

        if (Feed::matches($content, $sha256)) {
            return new AdoptEntry($path, AdoptStatus::Match, self::DETAIL_MATCH);
        }

        // The template writes the name here and the app's file has none of
        // it: the --name may be what differs, not the file.
        foreach ($names as $needle) {
            if (str_contains($template, $needle)) {
                $has = array_filter($names, static fn(string $n): bool => str_contains($content, $n));
                if ([] === $has) {
                    return new AdoptEntry($path, AdoptStatus::Yours, sprintf(self::DETAIL_NAME, $needle));
                }
                break;
            }
        }

        return new AdoptEntry($path, AdoptStatus::Yours, self::DETAIL_YOURS);
    }

    /**
     * Whether $path, or any folder on the way to it from the app root, is a
     * symlink. The root itself may be one: adopt works on its resolved path.
     */
    private static function throughLink(string $dir, string $path): bool
    {
        $at = $dir;
        foreach (explode('/', $path) as $segment) {
            $at .= '/' . $segment;
            if (is_link($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the real path of $file is under the app root. is_link() does
     * not see every Windows junction; realpath() goes through them.
     */
    private static function insideRoot(string $dir, string $file): bool
    {
        $real = realpath($file);
        if (false === $real) {
            return false;
        }
        $real = str_replace('\\', '/', $real);
        $root = $dir . '/';

        return '\\' === DIRECTORY_SEPARATOR
            ? 0 === strncasecmp($real, $root, strlen($root))
            : str_starts_with($real, $root);
    }

    /**
     * $path as the disk spells it, when that differs from the template's
     * spelling in case only; null when every segment is spelled exactly.
     * Only a case-insensitive disk ever lets the two differ here.
     *
     * @param array<string, list<string>> $listings
     */
    private static function otherCase(string $dir, string $path, array &$listings): ?string
    {
        $at = $dir;
        $actual = [];
        $differs = false;
        foreach (explode('/', $path) as $segment) {
            if (!isset($listings[$at])) {
                $listings[$at] = array_values(array_diff(scandir($at) ?: [], ['.', '..']));
            }
            $found = $segment;
            if (!in_array($segment, $listings[$at], true)) {
                foreach ($listings[$at] as $entry) {
                    if (0 === strcasecmp($entry, $segment)) {
                        $found = $entry;
                        $differs = true;
                        break;
                    }
                }
            }
            $actual[] = $found;
            $at .= '/' . $found;
        }

        return $differs ? implode('/', $actual) : null;
    }

    /**
     * The note for a vendored Squire kit that is not the one Sourdough
     * bakes, from the version `.squire.json` records. A kit copied by hand
     * has no record, so no note; neither has a record we cannot read.
     */
    private static function kitNote(string $dir): ?string
    {
        $theirs = self::jsonVersion($dir . '/' . self::SQUIRE_RECORD);
        $ours = self::jsonVersion(self::SQUIRE_KIT);
        if (null === $theirs || null === $ours) {
            return null;
        }

        return match (version_compare(ltrim($theirs, 'v'), ltrim($ours, 'v'))) {
            -1 => sprintf(self::NOTE_KIT_OLDER, $theirs, $ours),
            1 => sprintf(self::NOTE_KIT_NEWER, $theirs, $ours),
            default => null,
        };
    }

    /**
     * The "version" of a JSON file when it looks like a release (1.2.3,
     * v1.2, 0.10.0-beta1); null for no file, bad JSON, or "dev-main".
     */
    private static function jsonVersion(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = false === $raw ? null : json_decode($raw, true);
        $version = is_array($data) ? ($data['version'] ?? null) : null;

        return is_string($version) && 1 === preg_match('/^v?\d+(\.\d+)*([-+.]?[0-9A-Za-z.]+)?$/', $version) ? $version : null;
    }

    /**
     * Bake $shape into a scratch dir and read its manifest and every file it
     * lists back. The scratch dir is gone when this returns, whatever
     * happened.
     *
     * @param array<string, string|bool> $answers
     *
     * @return array{array<string, mixed>, array<string, string>} the manifest, and each listed path's contents
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

            $manifest = Manifest::read($bakeDir);
            $contents = [];
            foreach (array_keys(Manifest::files($manifest)) as $path) {
                $content = is_file($bakeDir . '/' . $path) ? file_get_contents($bakeDir . '/' . $path) : false;
                if (false === $content) {
                    throw new \RuntimeException(sprintf('The fresh bake lists "%s" but has no such file.', $path));
                }
                $contents[$path] = $content;
            }

            return [$manifest, $contents];
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
     * @throws AdoptException when $dir already has a Sourdough manifest, or a `.sourdough/base/` left from one
     */
    private static function assertAdoptable(string $dir): void
    {
        if (file_exists($dir . '/' . Manifest::FILE)) {
            throw new AdoptException(sprintf(
                '"%s" already has a Sourdough manifest: Sourdough made or adopted this app. Bring it up to date with sourdough feed. To adopt it again with another --shape or --name, delete .sourdough/, commit, and run adopt again.',
                $dir,
            ));
        }

        // Base copies with no manifest belong to a record that is gone; a
        // later feed would merge against them as if they were still true.
        $base = $dir . '/' . Feed::BASE_DIR;
        if (file_exists($base) || is_link($base)) {
            throw new AdoptException(sprintf(
                '"%s/" is left from a manifest that is gone. Delete .sourdough/, commit, and run adopt again.',
                $base,
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
