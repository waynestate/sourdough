<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Laravel shapes carry Pinstripe's extras, the same ones WELD carries:
 * a `composer lint:gitlab` script that writes Pint's findings as a GitLab
 * Code Quality report, the composer-ci job that runs it in after_script and
 * keeps the report as a codequality artifact, the report in .gitignore, and
 * the app's own tests/Unit/PinstripeWiringTest.php, which holds pint.json
 * to Pinstripe's laravel.json plus the recorded skips. Both Laravel overlays
 * (blade-vue takes laravel-blade's) wear the same suit; this test checks the
 * tailoring before a single loaf is baked, and the smoke gate runs the
 * app's own test on a real bake.
 */
final class PinstripeLaravelTest extends TestCase
{
    private const SCRIPT = 'pint --test --format=gitlab > pint-codequality.json';

    /** @return iterable<string, array{string}> */
    public static function overlays(): iterable
    {
        yield 'laravel-blade' => ['laravel-blade'];
        yield 'laravel-inertia' => ['laravel-inertia'];
    }

    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        yield 'laravel-blade' => ['laravel-blade'];
        yield 'laravel-blade-vue' => ['laravel-blade-vue'];
        yield 'laravel-inertia' => ['laravel-inertia'];
    }

    #[DataProvider('overlays')]
    public function testTheOverlayAddsTheCodeQualityScript(string $overlay): void
    {
        $composer = self::json(self::templates() . '/' . $overlay . '/composer.overlay.json');

        self::assertIsArray($composer['scripts'] ?? null);
        self::assertSame(self::SCRIPT, $composer['scripts']['lint:gitlab'] ?? null);
        self::assertIsArray($composer['scripts-descriptions'] ?? null);
        self::assertArrayHasKey('lint:gitlab', $composer['scripts-descriptions']);
        self::assertSame('^0.3', $composer['require-dev']['waynestate/pinstripe'] ?? null);
    }

    #[DataProvider('overlays')]
    public function testTheReportStaysOutOfGit(string $overlay): void
    {
        $lines = file(self::templates() . '/' . $overlay . '/gitignore-additions.txt', FILE_IGNORE_NEW_LINES) ?: [];

        self::assertContains('/pint-codequality.json', array_map('trim', $lines));
    }

    #[DataProvider('pipelines')]
    public function testComposerCiKeepsTheCodeQualityReport(string $shape): void
    {
        $ci = Yaml::parseFile(self::templates() . '/' . $shape . '/.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $job = $ci['composer-ci'] ?? null;
        self::assertIsArray($job, $shape . ': composer-ci job');

        self::assertSame(['test -x vendor/bin/pint && composer lint:gitlab || true'], $job['after_script'] ?? null);
        self::assertSame(
            ['when' => 'always', 'expire_in' => '7 days', 'reports' => ['codequality' => 'pint-codequality.json']],
            $job['artifacts'] ?? null,
        );
    }

    public function testBothOverlaysShipTheSameWiringTest(): void
    {
        self::assertFileEquals(
            self::templates() . '/laravel-blade/tests/Unit/PinstripeWiringTest.php',
            self::templates() . '/laravel-inertia/tests/Unit/PinstripeWiringTest.php',
        );
    }

    /**
     * The skips the wiring test allows are exactly the overlay pint.json's
     * extras over Pinstripe's laravel.json, so a bake starts green and a new
     * skip needs a reason written down in both places.
     */
    #[DataProvider('overlays')]
    public function testTheWiringTestRecordsThePintJsonSkips(string $overlay): void
    {
        $preset = dirname(__DIR__) . '/vendor/waynestate/pinstripe/pint/laravel.json';
        if (!is_file($preset)) {
            self::markTestSkipped('Pinstripe is not installed; run composer install.');
        }

        $pinstripe = self::json($preset);
        $pint = self::json(self::templates() . '/' . $overlay . '/pint.json');
        $test = (string) file_get_contents(self::templates() . '/' . $overlay . '/tests/Unit/PinstripeWiringTest.php');

        self::assertSame(
            self::keys($test, 'PINSTRIPE_EXTRA_EXCLUDES'),
            self::extras($pint['exclude'] ?? [], $pinstripe['exclude'] ?? []),
        );
        self::assertSame(
            self::keys($test, 'PINSTRIPE_EXTRA_NOT_PATHS'),
            self::extras($pint['notPath'] ?? [], $pinstripe['notPath'] ?? []),
        );
    }

    /**
     * The keys of one `const NAME = ['key' => 'reason', ...];` block, sorted.
     *
     * @return list<string>
     */
    private static function keys(string $php, string $const): array
    {
        self::assertSame(1, preg_match('/^const ' . $const . ' = \[\n(.*?)^\];/ms', $php, $block), $const);
        preg_match_all("/^    '([^']+)' => '[^']+',$/m", $block[1], $m);
        $keys = $m[1];
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function extras(mixed $ours, mixed $theirs): array
    {
        self::assertIsArray($ours);
        self::assertIsArray($theirs);
        $extras = array_values(array_map('strval', array_diff($ours, $theirs)));
        sort($extras);

        return $extras;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function templates(): string
    {
        return dirname(__DIR__) . '/templates';
    }
}
