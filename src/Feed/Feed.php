<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

use Waynestate\Sourdough\Baker;
use Waynestate\Sourdough\Generator\DockerScaffold;
use Waynestate\Sourdough\Generator\SailScaffold;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

/**
 * `sourdough feed`: bring template updates into an app without clobbering
 * what the app has changed.
 *
 * The update model is Copier's and cruft's (ADR-0014, decision 4), built on
 * plain git instead of a Python runtime:
 *
 * 1. Read the app's manifest: the shape, the answers, and a sha256 for
 *    every file Sourdough wrote (the base).
 * 2. Bake the same shape with the same answers, with this Sourdough, into a
 *    scratch dir (the new loaf), through the same Baker `sourdough new` uses.
 * 3. Compare file by file. A file the template did not change is left
 *    alone. A file the app never touched takes the template's new version.
 *    A file both changed is three-way merged by `git merge-file`, with the
 *    base version found by its hash: first in `.sourdough/base/` (kept by
 *    the previous feed), then anywhere in the app's git history. Conflicts
 *    get git's markers in place; a merge with no base marks both versions;
 *    binary files are never merged (the template's copy lands next to the
 *    app's as `<file>.sourdough-new`).
 * 4. Seeded files (README.md, .env.example) are the app's: never touched.
 * 5. Move the manifest to the new base, and keep the template's version of
 *    every managed file the app has changed in `.sourdough/base/`, so the
 *    next feed has its base even when git history does not.
 *
 * Nothing is written until the whole plan is worked out, and a real feed
 * runs only on a clean git tree, so `git diff` shows exactly what it did and
 * `git restore` undoes it. A starter that is fed on schedule never goes flat.
 */
final class Feed
{
    /**
     * Where feed keeps the template's version of managed files the app has
     * changed, relative to the app root. Each copy is "<path>.base", so a
     * kept .gitignore or .gitattributes never acts on the folder it sits in
     * and no linter mistakes a kept PHP file for app code.
     */
    public const BASE_DIR = '.sourdough/base';

    /**
     * The suffix of the template's copy of a binary file feed could not
     * merge, written next to the app's own.
     */
    public const SIDECAR_SUFFIX = '.sourdough-new';

    private readonly Git $git;

    private readonly Baker $baker;

    /**
     * @param string|null $version the running Sourdough version to record; null means the installed package's
     */
    public function __construct(
        ?SkeletonSource $skeletonSource = null,
        ?Git $git = null,
        private readonly ?string $version = null,
    ) {
        $this->git = $git ?? new Git();
        $this->baker = new Baker($skeletonSource);
    }

    /**
     * Refuse unless a real feed may write into $appDir: git runs, the dir
     * is inside a work tree, and nothing under it is uncommitted.
     *
     * @throws FeedException when it may not
     */
    public function assertReady(string $appDir): void
    {
        if (!$this->git->available()) {
            throw new FeedException('sourdough feed needs git on PATH (or SOURDOUGH_GIT set to it): git does the merging.');
        }

        $problem = $this->git->workTreeProblem($appDir);
        if (null !== $problem) {
            throw new FeedException(sprintf(
                'sourdough feed only runs inside a git repository, so every change it makes can be reviewed with git diff and undone with git restore. "%s": %s',
                $appDir,
                $problem,
            ));
        }

        $status = $this->git->status($appDir);
        if ([] !== $status) {
            $shown = array_slice($status, 0, 10);
            if (count($status) > 10) {
                $shown[] = sprintf('... and %d more', count($status) - 10);
            }

            throw new FeedException(
                "Commit or stash your changes first: sourdough feed only runs on a clean git tree, so git diff shows exactly what it did.\n  "
                . implode("\n  ", $shown),
            );
        }
    }

    /**
     * Work out what feeding $appDir would do. Writes nothing in the app.
     *
     * @throws FeedException     when feed cannot run on this app
     * @throws \RuntimeException when the bake or git fails
     */
    public function plan(string $appDir): FeedPlan
    {
        $appDir = rtrim(str_replace('\\', '/', $appDir), '/');
        if ('' === $appDir || !is_dir($appDir)) {
            throw new FeedException(sprintf('The app directory "%s" does not exist.', $appDir));
        }

        try {
            $old = Manifest::read($appDir);
        } catch (\RuntimeException $e) {
            throw new FeedException($e->getMessage() . ' sourdough feed needs the manifest sourdough new writes; an app Sourdough did not make needs adopting first: sourdough adopt <dir> --shape <shape>.', 0, $e);
        }

        $oldFiles = Manifest::files($old);
        foreach (array_keys($oldFiles) as $path) {
            if (!self::safePath($path)) {
                throw new FeedException(sprintf('The manifest lists an unsafe path, "%s"; refusing to feed.', $path));
            }
        }

        $template = is_string($old['template'] ?? null) ? $old['template'] : '';
        $permutation = Permutation::tryFrom($template);
        if (null === $permutation) {
            // A retired shape says where to go (docs/guides/support.md).
            $retired = ShapeSupport::load()->notice($template);
            throw new FeedException(sprintf('The manifest names the template "%s", which this Sourdough does not bake.', $template) . (null === $retired ? '' : ' ' . $retired));
        }

        $answers = [];
        foreach (is_array($old['answers'] ?? null) ? $old['answers'] : [] as $key => $value) {
            if (is_string($value) || is_bool($value)) {
                $answers[(string) $key] = $value;
            }
        }
        $name = $answers['name'] ?? null;
        if (!is_string($name) || '' === $name) {
            throw new FeedException('The manifest records no app name in its answers; refusing to feed.');
        }

        $from = is_string($old['sourdough_version'] ?? null) ? $old['sourdough_version'] : 'dev';
        $to = $this->version ?? Manifest::packageVersion();
        if (self::isNewer($from, $to)) {
            throw new FeedException(sprintf(
                'This app was fed by Sourdough %s, newer than this one (%s). Update Sourdough first: a starter only rises forward.',
                $from,
                $to,
            ));
        }

        if (!$this->git->available()) {
            throw new FeedException('sourdough feed needs git on PATH (or SOURDOUGH_GIT set to it): git does the merging.');
        }

        $notes = [];

        // A deprecated shape says so on every feed until the release that
        // removes it, so the app's sourdough-feed CI job shows the notice in
        // every pipeline (the sunset path in docs/guides/support.md).
        $deprecation = ShapeSupport::load()->notice($permutation->value);
        if (null !== $deprecation) {
            $notes[] = $deprecation;
        }

        // Sourdough 0.1.6 baked --sail and --docker but wrote the manifest
        // before the oven's scaffold ran, so its answers never say which oven
        // the app has. Without one, feed would bake the plain shape and leave
        // the oven's files behind for good. Read it from the files the
        // scaffold leaves instead: no oven means no fire, but the app knows.
        $oven = null;
        if ('0.1.6' === $from && !isset($answers['sail']) && !isset($answers['docker'])
            && Permutation::Raw !== $permutation && Permutation::RawVue !== $permutation
        ) {
            $oven = self::ovenFromFiles($appDir);
            if (null !== $oven) {
                $answers[$oven] = true;
                $notes[] = sprintf(
                    'Sourdough 0.1.6 did not record the oven in the manifest; this app has the --%s files, so feed bakes it with --%s.',
                    $oven,
                    $oven,
                );
            }
        }

        $inRepo = null === $this->git->workTreeProblem($appDir);
        if (!$inRepo) {
            $notes[] = 'Not inside a git repository: a real feed would refuse, and no base can come from history.';
        } elseif ([] !== $this->git->status($appDir)) {
            $notes[] = 'Uncommitted changes: a real feed would refuse until the tree is clean.';
        }

        $leftovers = $this->leftovers($appDir, array_keys($oldFiles));
        if ([] !== $leftovers) {
            throw new FeedException(
                "Finish the last feed first: these files still hold its conflicts. Resolve them (git diff --check lists leftover markers), delete any .sourdough-new copies, and commit.\n  "
                . implode("\n  ", $leftovers),
            );
        }

        [$newManifest, $newContents, $executable] = $this->bakeFresh($permutation, $name, $answers, $from, $to);
        $newFiles = Manifest::files($newManifest);

        $steps = [];
        $bases = [];
        // Yours files that now match the template: managed from this feed on.
        $takenOver = [];
        $paths = array_unique(array_merge(array_keys($oldFiles), array_keys($newFiles)));
        sort($paths);

        foreach ($paths as $path) {
            $o = $oldFiles[$path] ?? null;
            $n = $newFiles[$path] ?? null;
            $appPath = $appDir . '/' . $path;
            $app = is_file($appPath) ? file_get_contents($appPath) : null;
            if (false === $app) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $appPath));
            }

            // Seeded once, the app's for good: README.md and .env.example are
            // never touched. A seeded file new to the template is written only
            // where the app has nothing.
            if ('seeded' === ($o['class'] ?? null) || 'seeded' === ($n['class'] ?? null)) {
                if (null === $o && null !== $n && null === $app) {
                    $steps[] = new FeedStep($path, FeedAction::Add, 'new seeded file: yours from now on', $newContents[$path], $executable[$path]);
                }
                continue;
            }

            // Yours (an adopted app's own version of a template file): never
            // written, merged, removed or reported, so the sourdough-feed CI
            // job stays green release after release. The day the app's copy
            // equals the template's, feed records it and manages it from
            // then on. Not every loaf has to come from our starter.
            if ('yours' === ($o['class'] ?? null)) {
                if (null !== $n && null !== $app && self::matches($app, $n['sha256'])) {
                    $steps[] = new FeedStep($path, FeedAction::Record, 'your file now matches the template\'s: Sourdough manages it from here');
                    $takenOver[$path] = true;
                }
                continue;
            }

            $step = null;
            $final = $app;

            if (null !== $o && null !== $n) {
                if ($o['sha256'] === $n['sha256']) {
                    // The template did not change it. Whatever the app did stands.
                } elseif (null === $app) {
                    $step = new FeedStep($path, FeedAction::Keep, 'you deleted it, so the template\'s update is not applied');
                } elseif (self::matches($app, $o['sha256'])) {
                    $step = new FeedStep($path, FeedAction::Update, 'you had not changed it', self::likeEol($newContents[$path], $app), $executable[$path]);
                } elseif (self::matches($app, $n['sha256'])) {
                    // The app already has the template's new version: nothing
                    // to write, but the manifest must learn it, or
                    // sourdough manifest --check reports the file changed.
                    $step = new FeedStep($path, FeedAction::Record, 'you already have the template\'s new version: the manifest records it');
                } else {
                    $noBaseDetail = 'no base version to merge from (not in .sourdough/base/ or git history): your version and the template\'s are both marked';
                    $base = $this->resolveBase($appDir, $path, $o['sha256'], $inRepo);
                    $unedited = false;
                    if (null === $base && '0.1.6' === $from && null !== $oven && 'composer.json' === $path) {
                        // Sourdough 0.1.6 hashed composer.json before the oven's
                        // scaffold edited it, so the recorded hash names a
                        // version no checkout ever held: rebuild the scaffold's
                        // output from the nearest version whose pre-scaffold
                        // data the hash verifies.
                        $base = $this->ovenBase($appDir, $path, $o['sha256'], $app, $oven, $permutation, $inRepo);
                        if (null === $base) {
                            $noBaseDetail = self::noBaseDetail($oven);
                        } else {
                            $unedited = self::matches($base, hash('sha256', self::toLf($app)));
                        }
                    }
                    if ($unedited) {
                        $step = new FeedStep($path, FeedAction::Update, 'you had not changed it', self::likeEol($newContents[$path], $app), $executable[$path]);
                    } else {
                        $step = $this->mergeStep($path, $app, $base, $newContents[$path], $from, $to, $noBaseDetail);
                    }
                }
            } elseif (null !== $o) {
                // The template dropped it.
                if (null === $app) {
                    // Already gone.
                } elseif (self::matches($app, $o['sha256'])) {
                    $step = new FeedStep($path, FeedAction::Remove, 'the template dropped it and you had not changed it');
                } else {
                    $step = new FeedStep($path, FeedAction::Keep, 'the template dropped it, but you changed it: it is yours now');
                }
            } elseif (null !== $n) {
                // New in the template.
                if (null === $app) {
                    $step = new FeedStep($path, FeedAction::Add, 'new in the template', $newContents[$path], $executable[$path]);
                } elseif (self::matches($app, $n['sha256'])) {
                    $step = new FeedStep($path, FeedAction::Record, 'new in the template, and you already have the same file: the manifest records it');
                } else {
                    $step = $this->mergeStep($path, $app, null, $newContents[$path], $from, $to, 'new in the template, but you already have a file here: your version and the template\'s are both marked');
                }
            }

            if (null !== $step) {
                $steps[] = $step;
                if (FeedAction::Remove === $step->action) {
                    $final = null;
                } elseif (null !== $step->content) {
                    $final = $step->content;
                }
            }

            // Keep the template's version of a managed file the app has
            // changed: it is the base the next feed merges from.
            if (null !== $n && null !== $final && !self::matches($final, $n['sha256']) && !self::isBinary($newContents[$path])) {
                $bases[$path] = $newContents[$path];
            }
        }

        // Once seeded, always the app's, even if a later template calls it managed.
        // Yours stays yours until the app's copy matches the template's (a
        // record step above); a path the template dropped simply leaves the
        // manifest, and one the template now seeds is seeded.
        if (is_array($newManifest['files'] ?? null)) {
            $files = $newManifest['files'];
            foreach ($oldFiles as $path => $info) {
                if (!is_array($files[$path] ?? null)) {
                    continue;
                }
                if ('seeded' === $info['class']) {
                    $files[$path]['class'] = 'seeded';
                } elseif ('yours' === $info['class'] && !isset($takenOver[$path]) && 'seeded' !== ($newFiles[$path]['class'] ?? null)) {
                    $files[$path]['class'] = 'yours';
                }
            }
            $newManifest['files'] = $files;
        }

        return new FeedPlan($appDir, $permutation->value, $from, $to, $steps, $bases, $newManifest, $notes);
    }

    /**
     * Write $plan into its app: the files, the base copies and the manifest.
     * A plan with no steps writes nothing. Call assertReady() first.
     *
     * @throws \RuntimeException when a file cannot be written or removed
     */
    public function apply(FeedPlan $plan): void
    {
        if (!$plan->hasChanges()) {
            return;
        }

        $dir = $plan->appDir;

        foreach ($plan->steps as $step) {
            $path = $dir . '/' . $step->path;

            if (FeedAction::Remove === $step->action) {
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException(sprintf('Could not remove "%s".', $path));
                }
                $this->pruneEmptyDirs($dir, dirname($step->path));
                continue;
            }

            if (null !== $step->content) {
                self::writeFile($path, $step->content);
                if ($step->executable) {
                    @chmod($path, 0755);
                }
            }

            if (null !== $step->sidecar) {
                self::writeFile($path . self::SIDECAR_SUFFIX, $step->sidecar);
            }
        }

        $baseDir = $dir . '/' . self::BASE_DIR;
        self::removeDir($baseDir);
        foreach ($plan->bases as $path => $content) {
            self::writeFile($baseDir . '/' . $path . '.base', $content);
        }

        Manifest::writeData($dir, $plan->manifest);
    }

    /**
     * Bake the app's shape again, with its recorded answers, into a scratch
     * dir, and read the loaf back: the manifest, every file's contents, and
     * which files are executable.
     *
     * @param array<string, string|bool> $answers
     *
     * @return array{array<string, mixed>, array<string, string>, array<string, bool>}
     *
     * @throws FeedException     when the answers make no sense for the shape
     * @throws \RuntimeException when the bake fails
     */
    private function bakeFresh(Permutation $permutation, string $name, array $answers, string $from, string $version): array
    {
        // An app that recorded neither playbook nor no-playbook was baked
        // before Playbook was required: 0.1.3 to 0.1.5 suggested it, and
        // 0.1.0 to 0.1.2 had no Playbook wiring at all. Feed keeps what the
        // app was baked with; it never turns on the private requirement.
        $playbook = self::isNewer('0.1.3', $from) ? PlaybookMode::None : PlaybookMode::Suggest;
        if (true === ($answers['no-playbook'] ?? false)) {
            $playbook = PlaybookMode::None;
        } elseif (true === ($answers['playbook'] ?? false)) {
            $playbook = PlaybookMode::Require;
        }
        $advisor = true !== ($answers['no-advisor'] ?? false);
        $sail = true === ($answers['sail'] ?? false);
        $docker = true === ($answers['docker'] ?? false);

        if (($sail || $docker) && (Permutation::Raw === $permutation || Permutation::RawVue === $permutation)) {
            throw new FeedException('The manifest records --sail or --docker for a raw shape, which Sourdough never bakes; refusing to feed.');
        }

        unset($answers['name'], $answers['sail'], $answers['docker']);

        $scratch = sys_get_temp_dir() . '/sourdough-feed-' . bin2hex(random_bytes(8));
        $bakeDir = $scratch . '/app';
        try {
            if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $scratch));
            }

            try {
                $this->baker->bake($permutation, $bakeDir, $name, $answers, $playbook, $advisor, $sail, $docker, $version);
            } catch (\InvalidArgumentException $e) {
                throw new FeedException('Could not bake the new loaf: ' . $e->getMessage(), 0, $e);
            }

            $manifest = Manifest::read($bakeDir);
            $contents = [];
            $executable = [];
            foreach (array_keys(Manifest::files($manifest)) as $path) {
                $file = $bakeDir . '/' . $path;
                $content = file_get_contents($file);
                if (false === $content) {
                    throw new \RuntimeException(sprintf('Could not read "%s".', $file));
                }
                $contents[$path] = $content;
                $executable[$path] = 0 !== ((fileperms($file) ?: 0) & 0111);
            }
        } finally {
            self::removeDir($scratch);
        }

        return [$manifest, $contents, $executable];
    }

    /**
     * Both the app and the template changed $path: merge, or mark.
     */
    private function mergeStep(string $path, string $app, ?string $base, string $new, string $from, string $to, string $noBaseDetail): FeedStep
    {
        if (self::isBinary($app) || self::isBinary($new) || (null !== $base && self::isBinary($base))) {
            return new FeedStep(
                $path,
                FeedAction::Conflict,
                sprintf('binary, so it cannot be merged: the template\'s version is in %s%s', $path, self::SIDECAR_SUFFIX),
                null,
                false,
                $new,
            );
        }

        $theirsLabel = Git::THEIRS_PREFIX . $to;
        $ours = self::toLf($app);
        $theirs = self::toLf($new);

        if (null === $base) {
            $marked = $this->markBoth($ours, $theirs, $theirsLabel);

            return new FeedStep($path, FeedAction::Conflict, $noBaseDetail, self::likeEol($marked, $app));
        }

        $merged = $this->git->mergeFile($ours, self::toLf($base), $theirs, Git::THEIRS_PREFIX . $from, $theirsLabel);
        $content = self::likeEol($merged['content'], $app);

        if (0 === $merged['conflicts']) {
            return new FeedStep($path, FeedAction::Merge, 'your edits kept, the template\'s merged in', $content);
        }

        return new FeedStep($path, FeedAction::Conflict, sprintf(
            '%d conflicting %s: you and the template changed the same lines; resolve the markers',
            $merged['conflicts'],
            1 === $merged['conflicts'] ? 'hunk' : 'hunks',
        ), $content);
    }

    /**
     * A two-way merge for a file with no base: every place the app's
     * version and the template's differ becomes a conflict block, with the
     * same markers a three-way conflict has. Where they agree, the text
     * stands once. git diff finds the places.
     */
    private function markBoth(string $ours, string $theirs, string $theirsLabel): string
    {
        $a = self::lines($ours);
        $b = self::lines($theirs);

        $out = '';
        $ai = 0;
        foreach ($this->git->diffHunks($ours, $theirs) as [$aStart, $aCount, $bStart, $bCount]) {
            $aFrom = 0 === $aCount ? $aStart : $aStart - 1;
            $bFrom = 0 === $bCount ? $bStart : $bStart - 1;

            $out .= implode('', array_slice($a, $ai, $aFrom - $ai));
            $out .= '<<<<<<< ' . Git::OURS_LABEL . "\n";
            $out .= self::withNewline(implode('', array_slice($a, $aFrom, $aCount)));
            $out .= "=======\n";
            $out .= self::withNewline(implode('', array_slice($b, $bFrom, $bCount)));
            $out .= '>>>>>>> ' . $theirsLabel . "\n";
            $ai = $aFrom + $aCount;
        }

        return $out . implode('', array_slice($a, $ai));
    }

    /**
     * The base version of $path: the content whose sha256 the old manifest
     * recorded. First the copy the last feed kept, then any version in the
     * app's git history. Null when neither has it.
     */
    private function resolveBase(string $appDir, string $path, string $sha256, bool $inRepo): ?string
    {
        $kept = $appDir . '/' . self::BASE_DIR . '/' . $path . '.base';
        if (is_file($kept)) {
            $content = file_get_contents($kept);
            if (false !== $content && self::matches($content, $sha256)) {
                return $content;
            }
        }

        if ($inRepo) {
            foreach ($this->git->historyVersions($appDir, $path) as $version) {
                if (self::matches($version, $sha256)) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * The base composer.json for a Sourdough 0.1.6 oven app. 0.1.6 hashed
     * the file before the oven's scaffold edited it, so the recorded hash
     * names a version no checkout ever held. Rebuild the scaffold's output
     * from the nearest version whose pre-scaffold data the hash verifies:
     * the app's file first, then its git history, newest first. Null when
     * nothing verifies: the conflict stands, with a message that says why.
     */
    private function ovenBase(string $appDir, string $path, string $sha256, string $app, string $oven, Permutation $permutation, bool $inRepo): ?string
    {
        $scaffold = 'sail' === $oven ? new SailScaffold() : new DockerScaffold();

        $candidates = [$app];
        if ($inRepo) {
            foreach ($this->git->historyVersions($appDir, $path) as $version) {
                $candidates[] = $version;
            }
        }

        foreach ($candidates as $candidate) {
            $data = $scaffold->undoComposer(self::toLf($candidate));
            if (null === $data || !self::preScaffoldHashMatches($data, $sha256, $permutation)) {
                continue;
            }
            $base = $scaffold->redoComposer($data);
            if (null !== $base) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Whether the pre-scaffold composer data $data is the version Sourdough
     * 0.1.6 hashed: the recorded sha256 carried by its bytes, under the
     * encodings and require-dev orders the bake pipeline can produce.
     *
     * @param array<string, mixed> $data
     */
    private static function preScaffoldHashMatches(array $data, string $sha256, Permutation $permutation): bool
    {
        $orders = [$data, self::pipelineRequireDevOrder($data, $permutation)];
        $flags = [
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ];

        foreach ($orders as $order) {
            foreach ($flags as $flag) {
                $encoded = json_encode($order, $flag);
                if (false !== $encoded && hash('sha256', $encoded . "\n") === $sha256) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * $data with require-dev in the order the bake pipeline produces: the
     * packages the skeleton ships, alphabetical, then the overlay's, in the
     * overlay's file order. A composer.json the app never ran composer over
     * carries that order.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function pipelineRequireDevOrder(array $data, Permutation $permutation): array
    {
        $dev = $data['require-dev'] ?? [];
        if (!is_array($dev) || [] === $dev) {
            return $data;
        }

        $overlay = self::overlayRequireDevKeys($permutation);
        $own = [];
        $shared = [];
        foreach ($dev as $package => $constraint) {
            if (in_array($package, $overlay, true)) {
                $shared[$package] = $constraint;
            } else {
                $own[$package] = $constraint;
            }
        }
        ksort($own, SORT_STRING);

        $ordered = $own;
        foreach ($overlay as $package) {
            if (isset($shared[$package])) {
                $ordered[$package] = $shared[$package];
            }
        }
        $data['require-dev'] = $ordered;

        return $data;
    }

    /**
     * The require-dev keys the template's composer overlay adds, in the
     * overlay's file order, for $permutation's template dir.
     *
     * @return list<string>
     */
    private static function overlayRequireDevKeys(Permutation $permutation): array
    {
        $dir = match ($permutation) {
            Permutation::LaravelBlade, Permutation::LaravelBladeVue => 'laravel-blade',
            Permutation::LaravelInertia => 'laravel-inertia',
            default => null,
        };
        if (null === $dir) {
            return [];
        }

        $path = __DIR__ . '/../../templates/' . $dir . '/composer.overlay.json';
        $overlay = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $dev = is_array($overlay) ? ($overlay['require-dev'] ?? null) : null;

        return is_array($dev) ? array_keys($dev) : [];
    }

    /**
     * The no-base message for a 0.1.6 oven composer.json feed could not
     * rebuild: the recorded hash predates the scaffold, and neither the
     * app's file nor its git history verifies it.
     */
    private static function noBaseDetail(string $oven): string
    {
        return sprintf(
            'Sourdough 0.1.6 hashed composer.json before the --%s scaffold edited it, and feed cannot rebuild the base from the app\'s file or its git history: your version and the template\'s are both marked. To take the template\'s version as an update instead, set composer.json\'s sha256 in .sourdough/manifest.json to the sha256 of your current composer.json, commit, and feed again.',
            $oven,
        );
    }

    /**
     * Files the last feed left marked: a managed file with a feed conflict
     * marker in it, or a .sourdough-new copy next to one.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function leftovers(string $appDir, array $paths): array
    {
        $found = [];
        foreach ($paths as $path) {
            $file = $appDir . '/' . $path;
            if (is_file($file . self::SIDECAR_SUFFIX)) {
                $found[] = $path . self::SIDECAR_SUFFIX;
            }
            if (is_file($file)) {
                $content = (string) file_get_contents($file);
                if (1 === preg_match('/^>>>>>>> ' . preg_quote(Git::THEIRS_PREFIX, '/') . '/m', $content)) {
                    $found[] = $path;
                }
            }
        }

        return $found;
    }

    /**
     * Whether $content is the file the manifest hashed. Line endings are
     * forgiven: a checkout with CRLF endings still matches the LF bytes
     * Sourdough wrote, and the other way round.
     */
    public static function matches(string $content, string $sha256): bool
    {
        if (hash('sha256', $content) === $sha256) {
            return true;
        }

        $lf = self::toLf($content);

        return hash('sha256', $lf) === $sha256 || hash('sha256', str_replace("\n", "\r\n", $lf)) === $sha256;
    }

    /**
     * The oven a Laravel app was baked with, read from the files its
     * scaffold writes: "docker" for a Dockerfile, a compose.yaml and the
     * dev-up script; "sail" for laravel/sail in require-dev and a
     * compose.yaml that builds from Sail's runtimes; null for neither. Only
     * for manifests that never recorded the oven (Sourdough 0.1.6).
     */
    public static function ovenFromFiles(string $appDir): ?string
    {
        $json = is_file($appDir . '/composer.json') ? file_get_contents($appDir . '/composer.json') : false;
        $composer = is_string($json) ? json_decode($json, true) : null;
        $compose = is_file($appDir . '/compose.yaml') ? file_get_contents($appDir . '/compose.yaml') : false;
        if (!is_array($composer) || !is_string($compose)) {
            return null;
        }

        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        if (is_file($appDir . '/Dockerfile') && array_key_exists('dev-up', $scripts)) {
            return 'docker';
        }

        $requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        if (array_key_exists('laravel/sail', $requireDev) && str_contains($compose, 'vendor/laravel/sail/runtimes/')) {
            return 'sail';
        }

        return null;
    }

    /**
     * Whether $a is a newer release than $b. A "dev" or otherwise
     * unreadable version is never newer or older than anything.
     */
    public static function isNewer(string $a, string $b): bool
    {
        $a = ltrim($a, 'vV');
        $b = ltrim($b, 'vV');
        if (1 !== preg_match('/^\d+(\.\d+)*/', $a) || 1 !== preg_match('/^\d+(\.\d+)*/', $b)) {
            return false;
        }

        return version_compare($a, $b, '>');
    }

    /**
     * A manifest path feed may write: relative, forward slashes, no "..",
     * nothing inside .git or .sourdough.
     */
    public static function safePath(string $path): bool
    {
        return '' !== $path
            && !str_contains($path, "\0")
            && !str_contains($path, '\\')
            && !str_starts_with($path, '/')
            && 1 !== preg_match('#^[A-Za-z]:#', $path)
            && 1 !== preg_match('#(^|/)\.\.?(/|$)#', $path)
            && 1 !== preg_match('#^\.(git|sourdough)(/|$)#', $path);
    }

    /**
     * Git's own test for a binary file: a NUL byte in the first 8000 bytes.
     */
    private static function isBinary(string $content): bool
    {
        return str_contains(substr($content, 0, 8000), "\0");
    }

    private static function toLf(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }

    /**
     * $content with the line endings $like uses: CRLF when every line of
     * $like ends in CRLF, as it is otherwise.
     */
    private static function likeEol(string $content, string $like): string
    {
        $crlf = substr_count($like, "\r\n");
        if ($crlf > 0 && $crlf === substr_count($like, "\n")) {
            return str_replace("\n", "\r\n", self::toLf($content));
        }

        return $content;
    }

    /**
     * @return list<string> the lines of $content, each with its newline
     */
    private static function lines(string $content): array
    {
        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function withNewline(string $text): string
    {
        return '' === $text || str_ends_with($text, "\n") ? $text : $text . "\n";
    }

    private static function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dir));
        }
        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Remove the now-empty folders a removed file leaves behind, up to (not
     * including) the app root.
     */
    private function pruneEmptyDirs(string $root, string $relativeDir): void
    {
        while ('.' !== $relativeDir && '' !== $relativeDir) {
            $dir = $root . '/' . $relativeDir;
            if (!is_dir($dir) || [] !== array_diff(scandir($dir) ?: [], ['.', '..'])) {
                return;
            }
            @rmdir($dir);
            $relativeDir = dirname($relativeDir);
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
