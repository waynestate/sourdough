<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Generate a Laravel app from a permutation overlay: a fresh Laravel
 * skeleton from a SkeletonSource, the overlay copied over it, the stack's
 * composer packages merged into the app's composer.json, Squire's laravel
 * deploy files installed, Squire's Vue .npmrc installed when an overlay
 * ships a front end, Playbook and ADVISOR wired in, and the manifest
 * written. Subclasses pick the overlay dir and the manifest's template
 * name (laravel-blade, laravel-inertia, ...).
 *
 * A subclass may layer a second overlay dir over the first: the
 * laravel-blade-vue shape layers the islands front end (package.json,
 * vite.config.js, resources/js, the island views) over the laravel-blade
 * overlay. Each overlay's control files are applied when it ships them, in
 * copy order, so a layered overlay can stay silent where the first speaks.
 *
 * When an overlay ships a package.json (blade-vue and inertia do, plain
 * blade does not), the generator also installs Squire's Vue .npmrc, which
 * sets the one-day npm release cooldown (min-release-age=1); an app that
 * already has a .npmrc gets the line merged in, not overwritten.
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
 * a clash, scripts keys added, config deep-merged with the app keeping its
 * own keys), and env-additions.txt appends to the app's .env.example.
 *
 * The generator refuses an existing non-empty target dir and writes nothing
 * outside the target dir. A good starter rises on its own.
 */
abstract class LaravelOverlayGenerator
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
        private readonly string $templateDir,
        private readonly SkeletonSource $skeleton = new ComposerSkeletonSource(),
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
        private readonly ?string $secondaryDir = null,
    ) {
        $this->support = new GeneratorSupport($squireDir);
    }

    /**
     * The overlay dirs in copy order: the primary overlay, then the
     * secondary one when the subclass layered one on top.
     *
     * @return list<string>
     */
    private function overlayDirs(): array
    {
        $dirs = [$this->templateDir];
        if (null !== $this->secondaryDir) {
            $dirs[] = $this->secondaryDir;
        }

        return $dirs;
    }

    /**
     * True when any overlay ships a package.json: the blade-vue and inertia
     * overlays do, plain laravel-blade does not. Checked on the overlay
     * dirs, never on the target: a fresh Laravel skeleton always carries a
     * package.json of its own, so the target can never answer the question.
     */
    private function shipsFrontEnd(): bool
    {
        foreach ($this->overlayDirs() as $overlayDir) {
            if (is_file($overlayDir . '/package.json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The template name recorded in the manifest (the permutation's overlay
     * dir name).
     */
    abstract protected function templateName(): string;

    /**
     * Drop skeleton files this shape does not use. Called after the overlay
     * copy and before the manifest is written, so the manifest never lists
     * a file that is gone. The default keeps everything: most shapes use
     * the skeleton's resources/js/app.js and its views as-is.
     */
    protected function removeSkeletonLeftovers(string $targetDir): void {}

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers  each option the user chose, recorded in the manifest
     * @param PlaybookMode               $playbook how the app wires Playbook (see GeneratorSupport::applyPlaybook)
     * @param bool                       $advisor  wire ADVISOR into the app (the default) or remove the wiring (--no-advisor)
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN, the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the skeleton or the template is missing or a file cannot be read, parsed or written
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Require, bool $advisor = true): void
    {
        RawGenerator::assertValidName($name);

        $slug = RawGenerator::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name,
            ));
        }

        foreach ($this->overlayDirs() as $overlayDir) {
            if (!is_dir($overlayDir)) {
                throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $overlayDir));
            }
        }

        $this->support->refuseNonEmptyTarget($targetDir);
        $this->skeleton->create($targetDir);

        foreach ($this->overlayDirs() as $overlayDir) {
            $this->copyOverlay($targetDir, $name, $slug, $overlayDir);
        }

        $this->removeSkeletonLeftovers($targetDir);

        foreach ($this->overlayDirs() as $overlayDir) {
            $this->mergeComposerOverlay($targetDir, $overlayDir);
            $this->appendEnvAdditions($targetDir, $overlayDir);
        }

        $this->createSqliteDatabase($targetDir);

        $this->applyAppName($targetDir, $name, $slug);

        $this->support->installSquireFiles($targetDir, 'laravel');
        if ($this->shipsFrontEnd()) {
            $this->support->installNpmrc($targetDir);
        }
        $this->support->applyPlaybook($targetDir, $playbook);
        $this->support->applyAdvisor($targetDir, $advisor);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest($this->templateName(), $answers, self::SEEDED_FILES))->write($targetDir);
    }

    /**
     * Copy one overlay tree over the skeleton, replacing files, with
     * {{app_name}} and {{app_slug}} replaced in contents and names. The two
     * control files (composer.overlay.json and env-additions.txt) stay out
     * of the app; they are applied by the next two steps.
     *
     * @throws \RuntimeException when a file cannot be read or written
     */
    private function copyOverlay(string $targetDir, string $name, string $slug, string $overlayDir): void
    {
        $replacements = ['{{app_name}}' => $name, '{{app_slug}}' => $slug];

        foreach (scandir($overlayDir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            if ('composer.overlay.json' === $item || 'env-additions.txt' === $item) {
                continue;
            }

            $fromPath = $overlayDir . DIRECTORY_SEPARATOR . $item;

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
     * Merge one overlay's composer.overlay.json into the app's
     * composer.json: the require and require-dev entries are added (the
     * overlay wins a clash), the scripts keys are added, and config is
     * deep-merged so the app keeps its own config (a platform.php pin,
     * plugin allowances) while the overlay wins a clash on the same key.
     * Written pretty-printed with unescaped slashes and a trailing newline.
     * An overlay that ships no control file is skipped.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    private function mergeComposerOverlay(string $targetDir, string $overlayDir): void
    {
        $overlayPath = $overlayDir . '/composer.overlay.json';
        if (!is_file($overlayPath)) {
            return;
        }

        $overlay = $this->readJson($overlayPath);

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

        $addedConfig = $overlay['config'] ?? [];
        if (is_array($addedConfig) && [] !== $addedConfig) {
            $existingConfig = $composer['config'] ?? [];
            if (!is_array($existingConfig)) {
                throw new \RuntimeException(sprintf('"%s" has no usable config section.', $composerPath));
            }
            // Deep-merge: the app keeps its own config, the overlay wins a
            // clash on the same key (for example platform.ext-oci8 next to a
            // platform.php pin).
            $composer['config'] = array_replace_recursive($existingConfig, $addedConfig);
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('Could not encode "%s".', $composerPath));
        }
        if (false === file_put_contents($composerPath, $encoded . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $composerPath));
        }
    }

    /**
     * Append one overlay's env-additions.txt to the app's .env.example,
     * separated from the skeleton's lines by a blank line. An overlay that
     * ships no control file is skipped.
     *
     * @throws \RuntimeException when a file cannot be read or written
     */
    private function appendEnvAdditions(string $targetDir, string $overlayDir): void
    {
        $additionsPath = $overlayDir . '/env-additions.txt';
        if (!is_file($additionsPath)) {
            return;
        }

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
     * Create an empty database/database.sqlite when the baked app will use
     * sqlite. ComposerSkeletonSource runs create-project with --no-scripts,
     * so Laravel's post-create-project-cmd never runs; this recreates the
     * one file its touch() script makes, because without it the printed
     * php artisan migrate stops to ask whether to create the database.
     * Laravel's own installer leaves the file in place, and its .gitignore
     * already keeps *.sqlite out of git.
     *
     * @throws \RuntimeException when the file cannot be created
     */
    private function createSqliteDatabase(string $targetDir): void
    {
        $envPath = $targetDir . '/.env.example';
        if (!is_file($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $envPath));
        }

        // A commented line such as # DB_CONNECTION=sqlite does not count.
        $usesSqlite = false;
        foreach (explode("\n", $content) as $line) {
            if ('DB_CONNECTION=sqlite' === trim($line)) {
                $usesSqlite = true;
                break;
            }
        }
        if (!$usesSqlite) {
            return;
        }

        $databaseDir = $targetDir . '/database';
        if (!is_dir($databaseDir)) {
            return;
        }

        $dbPath = $databaseDir . '/database.sqlite';
        if (file_exists($dbPath)) {
            return;
        }

        if (false === touch($dbPath)) {
            throw new \RuntimeException(sprintf('Could not create "%s".', $dbPath));
        }
    }

    /**
     * Apply the app name to the places a fresh Laravel skeleton still says
     * laravel/laravel and Laravel, where WELD applies it by hand:
     * composer.json's name becomes app/<slug> (an application is not a
     * published package) and its description names the app; .env.example's
     * APP_NAME line becomes the name, quoted when it carries a space; and
     * config/app.php's default follows it. A missing file or a pattern that
     * does not match is skipped; the bake never fails on it.
     *
     * @throws \RuntimeException when a file cannot be written
     */
    private function applyAppName(string $targetDir, string $name, string $slug): void
    {
        $composerPath = $targetDir . '/composer.json';
        if (is_file($composerPath)) {
            $content = file_get_contents($composerPath);
            if (false !== $content) {
                try {
                    $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $composer = null;
                }
                if (is_array($composer)) {
                    // An application is not a published package: its name is
                    // app/<slug>, not a vendor namespace.
                    $composer['name'] = 'app/' . $slug;
                    $composer['description'] = sprintf('%s, a Wayne State app baked from Sourdough.', $name);
                    $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if (false !== $encoded && false === file_put_contents($composerPath, $encoded . "\n")) {
                        throw new \RuntimeException(sprintf('Could not write "%s".', $composerPath));
                    }
                }
            }
        }

        $envPath = $targetDir . '/.env.example';
        if (is_file($envPath)) {
            $content = file_get_contents($envPath);
            if (false !== $content) {
                $value = str_contains($name, ' ') ? '"' . $name . '"' : $name;
                $updated = preg_replace('/^APP_NAME=Laravel$/m', 'APP_NAME=' . $value, $content, 1);
                if (null !== $updated && $updated !== $content && false === file_put_contents($envPath, $updated)) {
                    throw new \RuntimeException(sprintf('Could not write "%s".', $envPath));
                }
            }
        }

        $configPath = $targetDir . '/config/app.php';
        if (is_file($configPath)) {
            $content = file_get_contents($configPath);
            if (false !== $content) {
                $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
                $updated = str_replace(
                    "'name' => env('APP_NAME', 'Laravel')",
                    "'name' => env('APP_NAME', '" . $escaped . "')",
                    $content,
                );
                if ($updated !== $content && false === file_put_contents($configPath, $updated)) {
                    throw new \RuntimeException(sprintf('Could not write "%s".', $configPath));
                }
            }
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
