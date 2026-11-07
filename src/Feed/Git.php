<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Feed;

/**
 * The git that `sourdough feed` stands on.
 *
 * Feed does not merge text itself. It hands the three versions of a file to
 * `git merge-file` (git's own three-way merge, the engine behind every
 * `git merge`), asks `git diff --no-index` where two versions differ, reads
 * old versions of a file out of the app's history with `git log` and
 * `git cat-file`, and checks the tree is clean with `git status`. Git is
 * already on every developer's machine that has a repository, on Windows,
 * macOS and Linux alike, so feed needs nothing new.
 *
 * Every command runs through proc_open as an argument array, never through
 * a shell string. The binary is "git" on PATH, overridable with the
 * SOURDOUGH_GIT environment variable.
 */
final class Git
{
    /**
     * The label on the template's side of every conflict feed writes. The
     * marker line `>>>>>>> sourdough <version>` is how feed finds leftovers
     * from an earlier feed that were never resolved.
     */
    public const THEIRS_PREFIX = 'sourdough ';

    /**
     * The label on the app's side of every conflict.
     */
    public const OURS_LABEL = 'app';

    private readonly string $binary;

    public function __construct(?string $binary = null)
    {
        if (null === $binary) {
            $fromEnv = getenv('SOURDOUGH_GIT');
            $binary = false !== $fromEnv && '' !== $fromEnv ? $fromEnv : 'git';
        }
        $this->binary = $binary;
    }

    /**
     * Whether git runs at all.
     */
    public function available(): bool
    {
        try {
            return 0 === $this->run(['--version'])[0];
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Null when $dir is inside a git work tree; otherwise why not, in git's
     * own words when it gave any.
     */
    public function workTreeProblem(string $dir): ?string
    {
        [$code, $out, $err] = $this->run(['rev-parse', '--is-inside-work-tree'], $dir);
        if (0 === $code && 'true' === trim($out)) {
            return null;
        }

        $err = trim($err);

        return '' !== $err ? $err : 'not a git repository';
    }

    /**
     * `git status --porcelain` for $dir and everything under it, untracked
     * files included. An empty list is a clean tree.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when git status fails
     */
    public function status(string $dir): array
    {
        [$code, $out, $err] = $this->run(['status', '--porcelain', '--untracked-files=all', '--', '.'], $dir);
        if (0 !== $code) {
            throw new \RuntimeException(sprintf('git status failed in "%s": %s', $dir, trim($err)));
        }

        return array_values(array_filter(explode("\n", str_replace("\r", '', $out)), static fn(string $line): bool => '' !== $line));
    }

    /**
     * Every version of $path the app's history knows, newest first: the
     * blob of each commit, on any branch, that wrote the path. Capped, so a
     * file edited a thousand times costs a thousand-line log and no more.
     *
     * @return list<string> blob contents
     */
    public function historyVersions(string $dir, string $path, int $limit = 50): array
    {
        [$code, $out] = $this->run(
            ['--literal-pathspecs', 'log', '--all', '--format=', '--raw', '--no-abbrev', '--no-renames', '--', $path],
            $dir,
        );
        if (0 !== $code) {
            return [];
        }

        preg_match_all('/^:\d+ \d+ [0-9a-f]+ ([0-9a-f]+) /m', $out, $matches);

        $versions = [];
        foreach (array_unique($matches[1]) as $blob) {
            if ('' === trim($blob, '0')) {
                continue; // a deletion: no blob to read
            }
            [$catCode, $content] = $this->run(['cat-file', 'blob', $blob], $dir);
            if (0 === $catCode) {
                $versions[] = $content;
            }
            if (count($versions) >= $limit) {
                break;
            }
        }

        return $versions;
    }

    /**
     * Three-way merge with `git merge-file`: the changes from $base to $theirs
     * applied on top of $ours. Conflicting hunks come back with git's
     * markers, labelled "app" and "sourdough <version>".
     *
     * @return array{content: string, conflicts: int}
     *
     * @throws \RuntimeException when git merge-file fails
     */
    public function mergeFile(string $ours, string $base, string $theirs, string $baseLabel, string $theirsLabel): array
    {
        $dir = $this->scratchDir();
        try {
            $this->put($dir . '/ours', $ours);
            $this->put($dir . '/base', $base);
            $this->put($dir . '/theirs', $theirs);

            [$code, $out, $err] = $this->run([
                'merge-file', '-p',
                '-L', self::OURS_LABEL,
                '-L', $baseLabel,
                '-L', $theirsLabel,
                $dir . '/ours', $dir . '/base', $dir . '/theirs',
            ], $dir);
        } finally {
            $this->removeScratch($dir);
        }

        // merge-file exits with the number of conflicts (capped at 127), or
        // a negative number (255 here) when it could not merge at all.
        if ($code < 0 || $code > 127) {
            throw new \RuntimeException(sprintf('git merge-file failed: %s', trim($err)));
        }

        return ['content' => $out, 'conflicts' => $code];
    }

    /**
     * Where $a and $b differ, from `git diff --no-index -U0`: one
     * [aStart, aCount, bStart, bCount] per hunk, in the diff's 1-based line
     * numbers (a count of 0 means "insert after line start").
     *
     * @return list<array{int, int, int, int}>
     *
     * @throws \RuntimeException when git diff fails
     */
    public function diffHunks(string $a, string $b): array
    {
        $dir = $this->scratchDir();
        try {
            $this->put($dir . '/a', $a);
            $this->put($dir . '/b', $b);

            [$code, $out, $err] = $this->run([
                '-c', 'core.autocrlf=false',
                'diff', '--no-index', '--no-color', '--no-ext-diff', '--no-textconv', '--histogram', '-U0',
                '--', $dir . '/a', $dir . '/b',
            ], $dir);
        } finally {
            $this->removeScratch($dir);
        }

        if (0 === $code) {
            return [];
        }
        if (1 !== $code) {
            throw new \RuntimeException(sprintf('git diff --no-index failed: %s', trim($err)));
        }

        preg_match_all('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/m', $out, $matches, PREG_SET_ORDER);

        $hunks = [];
        foreach ($matches as $match) {
            $hunks[] = [
                (int) $match[1],
                '' !== $match[2] ? (int) $match[2] : 1,
                (int) $match[3],
                '' !== ($match[4] ?? '') ? (int) $match[4] : 1,
            ];
        }

        return $hunks;
    }

    /**
     * Run git with $args in $cwd.
     *
     * @param list<string> $args
     *
     * @return array{int, string, string} exit code, stdout, stderr
     *
     * @throws \RuntimeException when the process cannot start
     */
    public function run(array $args, ?string $cwd = null): array
    {
        // stderr goes to a file, so a chatty stderr can never fill its pipe
        // and stall git while stdout is still being read.
        $errFile = tempnam(sys_get_temp_dir(), 'sourdough-git-');
        if (false === $errFile) {
            throw new \RuntimeException('Could not create a temporary file for git.');
        }

        $pipes = [];
        $process = @proc_open(
            array_merge([$this->binary], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            @unlink($errFile);

            throw new \RuntimeException(sprintf('Could not start "%s".', $this->binary));
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);

        return [$code, false === $out ? '' : $out, $err];
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-git-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dir));
        }

        return $dir;
    }

    private function put(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    private function removeScratch(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
