<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use Waynestate\Sourdough\Generator\ComposerSkeletonSource;
use Waynestate\Sourdough\Generator\DockerScaffold;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\LaravelOverlayGenerator;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\Generator\SailScaffold;
use Waynestate\Sourdough\Generator\SkeletonSource;

/**
 * Bakes one shape into a directory: the shape's generator, then the
 * --docker or --sail scaffold, then the manifest.
 *
 * `sourdough new` and `sourdough feed` both bake through here, so the loaf
 * feed compares an app against is exactly the loaf new would bake today
 * with the same answers. Same recipe, same oven, same crumb.
 *
 * The manifest is written last, after the scaffolds, so it lists the
 * Dockerfile, compose.yaml and the scaffolds' edits to composer.json, and
 * records the sail and docker answers that feed needs to bake them again.
 */
final class Baker
{
    /**
     * The files every shape seeds once and then leaves to the app. Both
     * generator families agree on the list; feed never touches them.
     *
     * @var list<string>
     */
    public const SEEDED_FILES = RawGenerator::SEEDED_FILES;

    public function __construct(
        private readonly ?SkeletonSource $skeletonSource = null,
    ) {}

    /**
     * Bake $permutation into $dir.
     *
     * @param array<string, string|bool> $answers the options to record in the manifest (the name is added here)
     * @param string|null                $version the Sourdough version to record; null means the installed package's
     *
     * @throws \InvalidArgumentException when the name is invalid or the target exists and is not empty
     * @throws \RuntimeException         when a template, the skeleton or a file cannot be read or written
     */
    public function bake(
        Permutation $permutation,
        string $dir,
        string $name,
        array $answers,
        PlaybookMode $playbook,
        bool $advisor,
        bool $sail,
        bool $docker,
        ?string $version = null,
    ): void {
        $generator = $this->generator($permutation);
        $generator->generate($dir, $name, $answers, $playbook, $advisor);

        $withVite = Permutation::LaravelBladeVue === $permutation || Permutation::LaravelInertia === $permutation;

        // The app's own Docker setup lands on the generated Laravel app
        // after the shape is baked: a two-target Dockerfile pinned by
        // digest, a compose.yaml (with a vite service for the Vue and
        // Inertia shapes), the dev-up/dev-down/dev-shell scripts and the
        // database settings in .env.example. Plan refuses it for raw first.
        if ($docker) {
            (new DockerScaffold())->apply($dir, $withVite);
        }

        // Sail lands on the generated Laravel app after the shape is
        // baked: composer.json gets laravel/sail, plus compose.yaml, the
        // .env.example lines and the README section.
        if ($sail) {
            (new SailScaffold())->apply($dir);
        }

        if ($sail) {
            $answers['sail'] = true;
        }
        if ($docker) {
            $answers['docker'] = true;
        }

        // Written again, after the scaffolds, so the record matches the dir.
        (new Manifest($permutation->value, array_merge(['name' => $name], $answers), self::SEEDED_FILES, $version))->write($dir);
    }

    private function generator(Permutation $permutation): RawGenerator|RawVueGenerator|LaravelOverlayGenerator
    {
        $skeleton = $this->skeletonSource ?? new ComposerSkeletonSource();

        return match ($permutation) {
            Permutation::Raw => new RawGenerator(),
            Permutation::RawVue => new RawVueGenerator(),
            Permutation::LaravelBlade => new LaravelBladeGenerator($skeleton),
            Permutation::LaravelBladeVue => new LaravelBladeVueGenerator($skeleton),
            Permutation::LaravelInertia => new LaravelInertiaGenerator($skeleton),
        };
    }
}
