<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\GeneratorSupport;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Deprecations are failures (BEST_PRACTICES 4.13), in Sourdough's own suite
 * and in every app it bakes. The raw template writes the gate into its
 * phpunit.xml.dist; the Laravel shapes get it on the skeleton's phpunit.xml
 * at bake time, plus a TestCase that hands deprecations back to PHPUnit,
 * because Laravel's error handler drops them while testing (proved on a real
 * bake on PHP 8.5: without the hand-back, an implicitly nullable parameter
 * in app/ passes the suite; with it, the suite fails). Dough that only
 * rises on the counter is not proofed.
 */
final class DeprecationGateTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testSourdoughsOwnSuiteFailsOnDeprecations(): void
    {
        self::assertGate((string) file_get_contents(dirname(__DIR__) . '/phpunit.xml.dist'), 'phpunit.xml.dist');
    }

    public function testTheRawTemplateFailsOnDeprecations(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__) . '/templates/raw/phpunit.xml.dist');
        self::assertGate($xml, 'templates/raw/phpunit.xml.dist');

        // The source is the app's own code, never the fetched WayneUI file.
        self::assertStringContainsString('<directory>src</directory>', $xml);
        self::assertStringContainsString('<directory>public</directory>', $xml);
        self::assertStringContainsString('<file>public/wayne-ui.php</file>', $xml);
    }

    /** @return array<string, array{class-string<LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator>}> */
    public static function laravelShapes(): array
    {
        return [
            'blade' => [LaravelBladeGenerator::class],
            'blade-vue' => [LaravelBladeVueGenerator::class],
            'inertia' => [LaravelInertiaGenerator::class],
        ];
    }

    /**
     * @param class-string<LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator> $generator
     */
    #[DataProvider('laravelShapes')]
    public function testEveryLaravelShapeBakesTheGateAndTheHandBack(string $generator): void
    {
        $target = $this->makeTempDir();
        (new $generator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        $xml = (string) file_get_contents($target . '/phpunit.xml');
        self::assertGate($xml, 'the baked phpunit.xml');
        // The skeleton's own settings come through untouched.
        self::assertStringContainsString('<env name="DB_DATABASE" value=":memory:"/>', $xml);
        self::assertStringContainsString("<directory>app</directory>", $xml);

        $testCase = (string) file_get_contents($target . '/tests/TestCase.php');
        self::assertStringContainsString('use ReportsDeprecationsToPhpUnit;', $testCase);
        self::assertMatchesRegularExpression('/parent::setUp\(\);\s+\$this->reportDeprecationsToPhpUnit\(\);/', $testCase, 'the hand-back starts right after the app boots');

        $trait = (string) file_get_contents($target . '/tests/Support/ReportsDeprecationsToPhpUnit.php');
        self::assertStringContainsString('ErrorHandler::instance()($level, $message, $file, $line);', $trait);
        self::assertStringContainsString('$level === E_DEPRECATED || $level === E_USER_DEPRECATED', $trait);

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('managed', $manifest['files']['phpunit.xml']['class'] ?? null, 'feed brings the gate to apps baked earlier');
    }

    public function testBothLaravelOverlaysShipTheSameHandBack(): void
    {
        $templates = dirname(__DIR__) . '/templates';
        self::assertFileEquals(
            $templates . '/laravel-blade/tests/Support/ReportsDeprecationsToPhpUnit.php',
            $templates . '/laravel-inertia/tests/Support/ReportsDeprecationsToPhpUnit.php',
        );
    }

    public function testTheGateKeepsTheSkeletonsLayoutAndRunsOnce(): void
    {
        $target = $this->makeTempDir();
        copy(__DIR__ . '/fixtures/laravel-skeleton/phpunit.xml', $target . '/phpunit.xml');

        $support = new GeneratorSupport();
        $support->applyDeprecationGate($target);
        $once = (string) file_get_contents($target . '/phpunit.xml');

        // Each new attribute sits on its own line under the skeleton's, and
        // the ">" that closed the tag on its own line still does.
        self::assertStringContainsString("         colors=\"true\"\n         failOnDeprecation=\"true\"\n", $once);
        self::assertStringContainsString("         displayDetailsOnPhpunitDeprecations=\"true\"\n>\n", $once);
        self::assertStringContainsString('<source ignoreIndirectDeprecations="true">', $once);
        self::assertNotFalse(simplexml_load_string($once), 'still well-formed XML');

        $support->applyDeprecationGate($target);
        self::assertSame($once, (string) file_get_contents($target . '/phpunit.xml'), 'a second pass changes nothing');
    }

    public function testAnAttributeTheAppSetKeepsItsValue(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/phpunit.xml', "<?xml version=\"1.0\"?>\n<phpunit bootstrap=\"vendor/autoload.php\" failOnDeprecation=\"false\">\n    <source ignoreIndirectDeprecations=\"false\"/>\n</phpunit>\n");

        (new GeneratorSupport())->applyDeprecationGate($target);
        $xml = (string) file_get_contents($target . '/phpunit.xml');

        self::assertStringContainsString('failOnDeprecation="false"', $xml);
        self::assertSame(1, substr_count($xml, 'failOnDeprecation='));
        self::assertStringContainsString('failOnPhpunitDeprecation="true"', $xml);
        self::assertStringContainsString('<source ignoreIndirectDeprecations="false"/>', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testATargetWithoutPhpunitXmlIsSkipped(): void
    {
        $target = $this->makeTempDir();

        (new GeneratorSupport())->applyDeprecationGate($target);

        self::assertFileDoesNotExist($target . '/phpunit.xml');
    }

    public function testAFileWithoutAPhpunitElementIsRefused(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/phpunit.xml', "<?xml version=\"1.0\"?>\n<config/>\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no <phpunit> element');
        (new GeneratorSupport())->applyDeprecationGate($target);
    }

    /** The root element fails on PHP and PHPUnit deprecations and names the test; the source skips indirect ones. */
    private static function assertGate(string $xml, string $label): void
    {
        $doc = simplexml_load_string($xml);
        self::assertNotFalse($doc, "$label is well-formed XML");
        foreach (GeneratorSupport::DEPRECATION_GATE as $name => $value) {
            self::assertSame($value, (string) $doc[$name], "$label sets $name");
        }
        self::assertSame('true', (string) $doc->source['ignoreIndirectDeprecations'], "$label ignores indirect deprecations only");
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
