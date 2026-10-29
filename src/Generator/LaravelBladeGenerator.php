<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Generate a Laravel + Blade app: a fresh Laravel skeleton from a
 * SkeletonSource, the laravel-blade overlay copied over it, the stack's
 * composer packages merged into the app's composer.json, Squire's laravel
 * deploy files installed, Playbook wired in, and the manifest written.
 *
 * The skeleton source defaults to ComposerSkeletonSource, which runs
 * `composer create-project laravel/laravel`; tests use FixtureSkeletonSource
 * with the checked-in fixture, so they never touch the network.
 *
 * The overlay replaces the skeleton's files where it ships its own (the
 * routes, the config, the views) and adds the rest, with {{app_name}} and
 * {{app_slug}} replaced as in the raw template. Its two control files are
 * applied, not copied: composer.overlay.json merges into the app's
 * composer.json (require and require-dev entries added, the overlay winning
 * a clash, scripts keys added), and env-additions.txt appends to the app's
 * .env.example.
 *
 * The generator refuses an existing non-empty target dir and writes nothing
 * outside the target dir. A good starter rises on its own.
 */
final class LaravelBladeGenerator
{
    /**
     * The files Sourdough seeds once and then leaves to the app, the same
     * rule as the raw template: .env.example holds the developer's values
     * per environment, and README.md becomes the app's own documentation.
     *
     * @var list<string>
     */
    public const SEEDED_FILES = [
        '.env.example',
        'README.md',
    ];

    private readonly GeneratorSupport $support;

    public function __construct(
        private readonly SkeletonSource $skeleton = new ComposerSkeletonSource(),
        private readonly string $templateDir = __DIR__ . '/../../templates/laravel-blade',
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        $this->support = new GeneratorSupport($squireDir);
    }

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers  each option the user chose, recorded in the manifest
     * @param PlaybookMode               $playbook how the app wires Playbook (see GeneratorSupport::applyPlaybook)
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN, the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the skeleton or the template is missing or a file cannot be read, parsed or written
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Suggest): void
    {
        RawGenerator::assertValidName($name);

        $slug = RawGenerator::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name,
            ));
        }

        if (!is_dir($this->templateDir)) {
            throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $this->templateDir));
        }

        $this->support->refuseNonEmptyTarget($targetDir);
        $this->skeleton->create($targetDir);

        $this->copyOverlay($targetDir, $name, $slug);
        $this->mergeComposerOverlay($targetDir);
        $this->appendEnvAdditions($targetDir);

        $this->support->installSquireFiles($targetDir, 'laravel');
        $this->support->applyPlaybook($targetDir, $playbook);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest('laravel-blade', $answers, self::SEEDED_FILES))->write($targetDir);
    }

    /**
     * Copy the overlay tree over the skeleton, replacing files, with
     * {{app_name}} and {{app_slug}} replaced in contents and names. The two
     * control files (composer.overlay.json and env-additions.txt) stay out
     * of the app; they are applied by the next two steps.
     *
     * @throws \RuntimeException when a file cannot be read or written
     */
    private function copyOverlay(string $targetDir, string $name, string $slug): void
    {
        $replacements = ['{{app_name}}' => $name, '{{app_slug}}' => $slug];

        foreach (scandir($this->templateDir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            if ('composer.overlay.json' === $item || 'env-additions.txt' === $item) {
                continue;
            }

            $fromPath = $this->templateDir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fromPath)) {
                // copyDir copies the contents of its first argument into its
                // second, so the destination keeps the source dir's name.
                $this->support->copyDir($fromPath, $targetDir . DIRECTORY_SEPARATOR . strtr($item, $replacements), $name, $slug);
                continue;
            }

            $content = file_get_contents($fromPath);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $fromPath));
            }

            $toPath = $targetDir . DIRECTORY_SEPARATOR . strtr($item, $replacements);
            if (false === file_put_contents($toPath, strtr($content, $replacements))) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $toPath));
            }
        }
    }

    /**
     * Merge composer.overlay.json into the app's composer.json: the
     * require and require-dev entries are added (the overlay wins a clash),
     * and the scripts keys are added. Written pretty-printed with unescaped
     * slashes and a trailing newline.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    private function mergeComposerOverlay(string $targetDir): void
    {
        $overlay = $this->readJson($this->templateDir . '/composer.overlay.json');

        $composerPath = $targetDir . '/composer.json';
        $content = file_get_contents($composerPath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $composerPath));
        }
        $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($composer)) {
            throw new \RuntimeException(sprintf('"%s" is not a composer.json object.', $composerPath));
        }

        foreach (['require', 'require-dev'] as $section) {
            $existing = $composer[$section] ?? [];
            if (!is_array($existing)) {
                throw new \RuntimeException(sprintf('"%s" has no usable %s section.', $composerPath, $section));
            }
            $added = $overlay[$section] ?? [];
            if (!is_array($added)) {
                throw new \RuntimeException(sprintf('composer.overlay.json has no usable %s section.', $section));
            }
            // The overlay wins a clash: its entries come last.
            $composer[$section] = array_merge($existing, $added);
        }

        $scripts = $composer['scripts'] ?? [];
        if (!is_array($scripts)) {
            throw new \RuntimeException(sprintf('"%s" has no usable scripts section.', $composerPath));
        }
        $addedScripts = $overlay['scripts'] ?? [];
        if (!is_array($addedScripts)) {
            throw new \RuntimeException('composer.overlay.json has no usable scripts section.');
        }
        $composer['scripts'] = array_merge($scripts, $addedScripts);

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('Could not encode "%s".', $composerPath));
        }
        if (false === file_put_contents($composerPath, $encoded . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $composerPath));
        }
    }

    /**
     * Append env-additions.txt to the app's .env.example, separated from
     * the skeleton's lines by a blank line.
     *
     * @throws \RuntimeException when a file cannot be read or written
     */
    private function appendEnvAdditions(string $targetDir): void
    {
        $additionsPath = $this->templateDir . '/env-additions.txt';
        $additions = file_get_contents($additionsPath);
        if (false === $additions) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $additionsPath));
        }

        $envPath = $targetDir . '/.env.example';
        $existing = file_get_contents($envPath);
        if (false === $existing) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $envPath));
        }

        if ('' !== $existing && !str_ends_with($existing, "\n")) {
            $existing .= "\n";
        }

        if (false === file_put_contents($envPath, $existing . "\n" . $additions)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $envPath));
        }
    }

    /**
     * Read a JSON file and return it as an object-shaped array.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the file is unreadable or not a JSON object
     */
    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('"%s" is not a JSON object.', $path));
        }

        return $decoded;
    }
}
