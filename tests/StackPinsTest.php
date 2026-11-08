<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stack lines every shape bakes in, read straight from the templates:
 * the released HELMET 1.0, SESAME 0.6 and PROCTOR 0.8 lines in every
 * composer file that pulls them in, and Inertia 3 on both sides of the
 * Inertia shape. A pin that slips back
 * fails here before a bake ever tastes stale.
 */
final class StackPinsTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../templates';

    /**
     * Every template composer file that requires PROCTOR. The two islands
     * and Vue shapes inherit theirs (laravel-blade-vue layers over
     * laravel-blade, raw-vue over raw).
     *
     * @return array<string, array{string}>
     */
    public static function proctorComposerFiles(): array
    {
        return [
            'raw' => ['raw/composer.json'],
            'laravel-blade' => ['laravel-blade/composer.overlay.json'],
            'laravel-inertia' => ['laravel-inertia/composer.overlay.json'],
        ];
    }

    #[DataProvider('proctorComposerFiles')]
    public function testEveryShapePinsProctorZeroSeven(string $file): void
    {
        $composer = $this->readJson($file);

        // 0.7 brings `proctor gate`, the deploy gate Squire 0.6.0's
        // release.sh asks for; an older PROCTOR falls back to `check`.
        // 0.8 adds crlf-deploy-script, which fails a CRLF deploy script.
        self::assertSame('^0.8', $composer['require-dev']['waynestate/proctor'] ?? null, $file);
    }

    #[DataProvider('proctorComposerFiles')]
    public function testEveryShapePinsTheCurrentHelmetAndSesame(string $file): void
    {
        $composer = $this->readJson($file);

        self::assertSame('^1.0', $composer['require']['waynestate/helmet'] ?? null, $file);
        self::assertSame('^0.6', $composer['require']['waynestate/sesame'] ?? null, $file);
    }

    /**
     * PROCTOR 0.6 refuses --policy (and --today) in CI unless the job sets
     * PROCTOR_ALLOW_OVERRIDES=1, so an app's own script cannot pick the
     * policy it is graded on. With no option PROCTOR still finds CREED's
     * released policy in vendor/waynestate/creed on its own.
     */
    #[DataProvider('proctorComposerFiles')]
    public function testProctorScriptPassesNoPolicyOrDate(string $file): void
    {
        $composer = $this->readJson($file);
        $script = $composer['scripts']['proctor'] ?? null;

        self::assertSame('proctor check', $script, $file);
    }

    public function testInertiaShapeIsOnInertiaThreeBothSides(): void
    {
        $composer = $this->readJson('laravel-inertia/composer.overlay.json');
        $package = $this->readJson('laravel-inertia/package.json');

        self::assertSame('^3.0', $composer['require']['inertiajs/inertia-laravel'] ?? null);
        self::assertSame('^3.0', $package['dependencies']['@inertiajs/vue3'] ?? null);
    }

    public function testRootTemplateMarksHeadTagsTheInertiaThreeWay(): void
    {
        $view = (string) file_get_contents(self::TEMPLATES . '/laravel-inertia/resources/views/app.blade.php');

        self::assertStringContainsString('<title data-inertia>', $view);
        self::assertDoesNotMatchRegularExpression('/<title\s+inertia[\s>]/', $view);
    }

    /**
     * Inertia 3 no longer bundles Axios. The template does not use it; if a
     * page ever imports it, package.json must name it.
     */
    public function testNoAxiosImportWithoutTheDependency(): void
    {
        $package = $this->readJson('laravel-inertia/package.json');
        $declared = isset($package['dependencies']['axios']) || isset($package['devDependencies']['axios']);

        $importers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::TEMPLATES . '/laravel-inertia/resources/js',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (1 === preg_match('/from\s+[\'"]axios[\'"]|require\(\s*[\'"]axios[\'"]\s*\)/', (string) file_get_contents($file->getPathname()))) {
                $importers[] = $file->getFilename();
            }
        }

        self::assertTrue($declared || [] === $importers, 'axios is imported but not declared: ' . implode(', ', $importers));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::TEMPLATES . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $file);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
