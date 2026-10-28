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
 * After copying, the generator writes `.sourdough/manifest.json` so a later
 * `sourdough feed` knows what was generated.
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

    public function __construct(
        private readonly string $templateDir = __DIR__ . '/../../templates/raw',
    ) {
    }

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers each option the user chose, recorded in the manifest
     *
     * @throws \InvalidArgumentException when the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the template is missing or a file cannot be read or written
     */
    public function generate(string $targetDir, string $name, array $answers = []): void
    {
        $slug = self::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name
            ));
        }

        if (!is_dir($this->templateDir)) {
            throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $this->templateDir));
        }

        $this->refuseNonEmptyTarget($targetDir);

        $this->copyDir($this->templateDir, $targetDir, $name, $slug);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest('raw', $answers, self::SEEDED_FILES))->write($targetDir);
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
                    $targetDir
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
}
