<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Manifest;

/**
 * Copies the raw template into a target dir, replacing {{app_name}} and
 * {{app_slug}} in file contents and file names.
 *
 * The template dir is a constructor argument so tests can point it at a
 * fixture; by default it is the real templates/raw dir.
 *
 * The generator refuses an existing non-empty target dir and writes nothing
 * outside the target dir.
 *
 * After copying the template, the generator installs the raw deploy files
 * from the checked copy of SQUIRE's templates (templates/squire/). Those
 * files are copied as-is (SQUIRE owns them, so Sourdough does not rewrite
 * them), and their executable bits come from SQUIRE's manifest.
 *
 * Finally the generator writes `.sourdough/manifest.json` so a later
 * `sourdough feed` knows what was generated, including the SQUIRE files.
 */
final class RawGenerator
{
    /**
     * The files Sourdough seeds once and then leaves to the app. Everything
     * else in the template is managed: `sourdough feed` (a later release)
     * will update it.
     *
     * - .env.example: the app's environment config. The developer fills in
     *   real values per environment, so Sourdough must not clobber them.
     * - README.md: the app's own documentation. The developer rewrites it
     *   for their app, so overwriting it would destroy their work.
     */
    public const SEEDED_FILES = [
        '.env.example',
        'README.md',
    ];

    /**
     * The app name allow-list: a letter or number first, then letters,
     * numbers, spaces, dots, dashes and underscores, at most 63 characters.
     *
     * The allowed alphabet needs no escaping in JSON, single-quoted PHP
     * strings, Markdown or INI, so the generator may paste the name into
     * template contents unescaped.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,62}$/';

    public function __construct(
        private readonly string $templateDir = __DIR__ . '/../../templates/raw',
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
    ) {}

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers each option the user chose, recorded in the manifest
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN, the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the template is missing or a file cannot be read or written
     */
    public function generate(string $targetDir, string $name, array $answers = []): void
    {
        self::assertValidName($name);

        $slug = self::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name,
            ));
        }

        if (!is_dir($this->templateDir)) {
            throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $this->templateDir));
        }

        $this->refuseNonEmptyTarget($targetDir);

        $this->copyDir($this->templateDir, $targetDir, $name, $slug);
        $this->installSquireFiles($targetDir);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest('raw', $answers, self::SEEDED_FILES))->write($targetDir);
    }

    /**
     * Throw when $name is not safe to paste into generated file names and
     * contents. Called before generate() touches the disk.
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN
     */
    public static function assertValidName(string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'The app name must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters.',
            );
        }
    }

    /**
     * Lower-case the name and keep only [a-z0-9-], collapsing runs of other
     * characters into a single dash.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function refuseNonEmptyTarget(string $targetDir): void
    {
        if (is_file($targetDir)) {
            throw new \InvalidArgumentException(sprintf('The target "%s" is a file, not a directory.', $targetDir));
        }

        if (!is_dir($targetDir)) {
            return;
        }

        foreach (scandir($targetDir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                throw new \InvalidArgumentException(sprintf(
                    'The target directory "%s" already exists and is not empty.',
                    $targetDir,
                ));
            }
        }
    }

    private function copyDir(string $from, string $to, string $name, string $slug): void
    {
        if (!is_dir($to)) {
            if (!mkdir($to, 0777, true) && !is_dir($to)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $to));
            }
        }

        $replacements = ['{{app_name}}' => $name, '{{app_slug}}' => $slug];

        foreach (scandir($from) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $fromPath = $from . DIRECTORY_SEPARATOR . $item;
            $toPath = $to . DIRECTORY_SEPARATOR . strtr($item, $replacements);

            if (is_dir($fromPath)) {
                $this->copyDir($fromPath, $toPath, $name, $slug);
                continue;
            }

            $content = file_get_contents($fromPath);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $fromPath));
            }

            if (false === file_put_contents($toPath, strtr($content, $replacements))) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $toPath));
            }
        }
    }

    /**
     * Install the raw deploy files from the checked copy of SQUIRE's
     * templates into the target dir.
     *
     * The file list and target paths come from the copy's catalog.json (the
     * "raw" kind); the executable bit for each file comes from its mode in
     * the copy's manifest.json. The files are copied as-is: SQUIRE owns
     * them, so Sourdough does not rewrite their contents.
     *
     * @throws \RuntimeException when the copy is missing or a file cannot be read or written
     */
    private function installSquireFiles(string $targetDir): void
    {
        $catalog = $this->readJson($this->squireDir . '/catalog.json');
        if (!isset($catalog['raw']) || !is_array($catalog['raw'])) {
            throw new \RuntimeException('The SQUIRE copy has no "raw" kind in its catalog.');
        }

        $manifest = $this->readJson($this->squireDir . '/manifest.json');
        $modes = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

        foreach ($catalog['raw'] as $entry) {
            $path = (string) $entry['path'];
            $source = $this->squireDir . '/raw/' . $path;
            $target = $targetDir . '/' . $path;

            if (!is_file($source)) {
                throw new \RuntimeException(sprintf('The SQUIRE template file "%s" does not exist.', $source));
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dir));
            }

            $content = file_get_contents($source);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $source));
            }
            if (false === file_put_contents($target, $content)) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $target));
            }

            // The mode is an octal string ("644", "755"). chmod is a no-op for
            // the executable bit on Windows, where the bit is not tracked; the
            // mode is still recorded in the manifest, so this is best-effort.
            $mode = (string) ($modes['raw/' . $path]['mode'] ?? '644');
            chmod($target, intval($mode, 8));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('The SQUIRE copy file "%s" does not exist.', $path));
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
