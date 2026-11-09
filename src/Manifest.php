<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use Waynestate\Sourdough\Feed\Feed;

/**
 * The record Sourdough writes into every app it generates:
 * `.sourdough/manifest.json`.
 *
 * It is what `sourdough feed` compares the app against when it brings
 * template updates in. The manifest lists every generated
 * file with a sha256 hash and a class:
 *
 * - "managed": Sourdough owns the file and will update it.
 * - "seeded":  Sourdough wrote the file once; the app owns it now.
 * - "yours":   the app's own version of a template file (the coming
 *              `sourdough adopt` records one where the app's copy differs
 *              from the template's). Feed never touches it, and takes it
 *              over as managed once the app's copy equals the template's.
 *              Your starter, your rules.
 *
 * The JSON is pretty-printed with sorted keys and a trailing newline, and
 * paths use forward slashes on every OS, so two runs with the same answers
 * differ only in `generated_at`.
 */
final class Manifest
{
    public const SCHEMA = 1;

    /**
     * The manifest's path, relative to the app root (forward slashes).
     */
    public const FILE = '.sourdough/manifest.json';

    /**
     * Runtime-state paths that are never template files: Sourdough creates
     * them for the app's first run, and they hold the app's data once it
     * has migrated, so the manifest leaves them out and feed must never
     * treat them as template files.
     *
     * @var list<string>
     */
    private const RUNTIME_STATE_FILES = [
        'database/database.sqlite',
    ];

    /**
     * @param array<string, string|bool> $answers     each option the user chose
     * @param list<string>               $seededFiles relative paths (forward slashes) of the seeded files
     */
    public function __construct(
        private readonly string $template,
        private readonly array $answers,
        private readonly array $seededFiles,
        private readonly ?string $sourdoughVersion = null,
    ) {}

    /**
     * The Sourdough package version, or 'dev' when it cannot be determined
     * (running from a git checkout without a release tag).
     */
    public static function packageVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            $version = \Composer\InstalledVersions::getPrettyVersion('waynestate/sourdough');
            if (null !== $version && '' !== $version
                && !str_starts_with($version, 'dev-')
                && !str_contains($version, 'no-version-set')
            ) {
                return $version;
            }
        }

        return 'dev';
    }

    /**
     * Build the manifest for the app in $dir.
     *
     * @return array<string, mixed>
     */
    public function build(string $dir): array
    {
        $files = [];
        foreach ($this->walk($dir) as $relative) {
            $files[$relative] = [
                'class' => $this->isSeeded($relative) ? 'seeded' : 'managed',
                'sha256' => hash_file('sha256', $dir . '/' . $relative),
            ];
        }
        ksort($files);

        $answers = $this->answers;
        ksort($answers);

        return [
            'answers' => $answers,
            'files' => $files,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => self::SCHEMA,
            'sourdough_version' => $this->sourdoughVersion ?? self::packageVersion(),
            'template' => $this->template,
        ];
    }

    /**
     * Write `.sourdough/manifest.json` into $dir.
     *
     * @throws \RuntimeException when the manifest cannot be written
     */
    public function write(string $dir): void
    {
        self::writeData($dir, $this->build($dir));
    }

    /**
     * Write an already-built manifest array into $dir, in the same format
     * write() produces. `sourdough feed` uses it to move the app's record
     * to the new base.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException when the manifest cannot be encoded or written
     */
    public static function writeData(string $dir, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException('Could not encode the manifest as JSON.');
        }

        $dirPath = $dir . '/.sourdough';
        if (!is_dir($dirPath) && !mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dirPath));
        }

        $path = $dirPath . '/manifest.json';
        if (false === file_put_contents($path, $json . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Compare the app in $dir against its manifest.
     *
     * Seeded and yours files are never reported: the app owns them.
     *
     * @return array{changed: list<string>, missing: list<string>}
     *
     * @throws \RuntimeException when there is no manifest in $dir
     */
    public static function check(string $dir): array
    {
        $manifest = self::read($dir);

        $changed = [];
        $missing = [];
        foreach (self::files($manifest) as $relative => $info) {
            if ('managed' !== $info['class']) {
                continue;
            }

            $path = $dir . '/' . $relative;
            if (!is_file($path)) {
                $missing[] = $relative;
                continue;
            }

            // Line endings are forgiven, as feed forgives them: a checkout
            // whose .gitattributes turns a file to CRLF (the raw shapes'
            // *.ps1) has not drifted from the LF bytes Sourdough wrote.
            $content = file_get_contents($path);
            if (false === $content || !Feed::matches($content, $info['sha256'])) {
                $changed[] = $relative;
            }
        }

        sort($changed);
        sort($missing);

        return ['changed' => $changed, 'missing' => $missing];
    }

    /**
     * Read and decode the manifest in $dir.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when there is no manifest in $dir, or it is not a manifest
     */
    public static function read(string $dir): array
    {
        $manifestPath = $dir . '/' . self::FILE;
        if (!is_file($manifestPath)) {
            throw new \RuntimeException(sprintf('No Sourdough manifest found in "%s".', $dir));
        }

        $content = file_get_contents($manifestPath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $manifestPath));
        }

        try {
            $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('"%s" is not valid JSON: %s', $manifestPath, $e->getMessage()), 0, $e);
        }

        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException(sprintf('"%s" is not a Sourdough manifest: it has no files map.', $manifestPath));
        }

        $data = [];
        foreach ($manifest as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }

    /**
     * The files map of a decoded manifest, with every entry normalized to a
     * class and a hash. "seeded" and "yours" stay as they are; any other
     * class, or none, counts as managed, as it always has. An entry with no
     * usable hash is dropped.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, array{class: string, sha256: string}>
     */
    public static function files(array $manifest): array
    {
        $files = [];
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $relative => $info) {
            if (!is_array($info) || !is_string($info['sha256'] ?? null)) {
                continue;
            }
            $class = $info['class'] ?? 'managed';
            $files[(string) $relative] = [
                'class' => 'seeded' === $class || 'yours' === $class ? $class : 'managed',
                'sha256' => $info['sha256'],
            ];
        }

        return $files;
    }

    private function isSeeded(string $relative): bool
    {
        return in_array($relative, $this->seededFiles, true);
    }

    /**
     * @return list<string> relative paths (forward slashes) of every file in $dir
     */
    private function walk(string $dir): array
    {
        $files = [];
        $this->walkDir($dir, '', $files);
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function walkDir(string $base, string $prefix, array &$files): void
    {
        foreach (scandir($base) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || '.sourdough' === $entry) {
                continue;
            }

            $path = $base . '/' . $entry;
            $relative = '' === $prefix ? $entry : $prefix . '/' . $entry;

            if (is_dir($path)) {
                $this->walkDir($path, $relative, $files);
            } elseif (!in_array($relative, self::RUNTIME_STATE_FILES, true)) {
                $files[] = $relative;
            }
        }
    }
}
