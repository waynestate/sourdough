<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

final class RawVueGeneratorTest extends TestCase
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

    public function testLayersTheVueOverlayOverTheRawTemplate(): void
    {
        $target = $this->generate();

        // The raw template still lands under the overlay...
        foreach (['composer.json', 'phpunit.xml.dist', 'pint.json', 'src/Oracle.php', 'tests/SmokeTest.php', '.env.example'] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // ...and the Vue SPA layers over it.
        foreach ([
            'package.json',
            'vite.config.js',
            'resources/js/app.js',
            'resources/js/App.vue',
            'resources/css/app.css',
            'src/Vite.php',
            'tests/ViteTest.php',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }

        // Squire's deploy files arrive as for raw, but the app's own front
        // controller wins over Squire's appMayOwn copy.
        self::assertFileExists($target . '/scripts/release.sh');
        self::assertStringContainsString('/api/me', (string) file_get_contents($target . '/public/index.php'));

        // No placeholder survives.
        foreach ($this->allFiles($target) as $file) {
            self::assertStringNotContainsString('{{app_name}}', (string) file_get_contents($file), $file);
            self::assertStringNotContainsString('{{app_slug}}', (string) file_get_contents($file), $file);
        }
    }

    public function testTheFrontControllerKeepsTheRawBootAndServesJson(): void
    {
        $index = (string) file_get_contents($this->generate() . '/public/index.php');

        // The raw shape's HELMET and SESAME boot, unchanged in substance.
        self::assertStringContainsString('helmet_boot([', $index);
        self::assertStringContainsString("'driver' => 'fake'", $index);
        self::assertStringContainsString("'driver' => 'cas'", $index);
        self::assertStringContainsString('SESAME_CAS_URL', $index);

        // The Vite dev server joins the CSP only through the helper, and
        // production never asks it.
        self::assertStringContainsString('$vite->withDevServer(SecurityHeaders::defaultCspPolicy())', $index);
        self::assertStringContainsString("new Vite(__DIR__, \$appEnv !== 'production')", $index);

        // The API answers 401 instead of redirecting a fetch() to CAS.
        self::assertStringContainsString('http_response_code(401)', $index);
        self::assertStringContainsString("<meta property=\"csp-nonce\" nonce=\"<?= \$nonce ?>\">", $index);
        self::assertStringContainsString('data-app-name="My Cool App"', $index);
    }

    public function testWiresWayneUiFromThePinnedVuePackageAndTheLayeredDist(): void
    {
        $target = $this->generate();
        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('my-cool-app', $package['name']);
        // The same pins as the Inertia template's front end.
        self::assertSame('2.0.8', $package['dependencies']['@waynestate/wayne-ui-css']);
        self::assertSame('2.0.8', $package['dependencies']['@waynestate/wayne-ui-vue']);
        self::assertSame('^3.5.13', $package['dependencies']['vue']);
        self::assertSame('^6.4.1', $package['dependencies']['vite']);
        self::assertArrayNotHasKey('laravel-vite-plugin', $package['dependencies']);

        $css = (string) file_get_contents($target . '/resources/css/app.css');
        self::assertStringContainsString("@import '@waynestate/wayne-ui-css/dist/wayne-ui.css';", $css);

        $vite = (string) file_get_contents($target . '/vite.config.js');
        self::assertStringContainsString("outDir: 'public/build'", $vite);
        self::assertStringContainsString("manifest: 'manifest.json'", $vite);
        self::assertStringContainsString('publicDir: false', $vite);
    }

    public function testShipsTheNpmCooldownAndTheNodeFloor(): void
    {
        $target = $this->generate();

        // The one-day npm release cooldown comes from Squire's vue layer...
        self::assertFileExists($target . '/.npmrc');
        self::assertStringContainsString('min-release-age=1', (string) file_get_contents($target . '/.npmrc'));

        // ...and the Node floor is pinned in both places, at the LTS the CI
        // images already run.
        self::assertFileExists($target . '/.nvmrc');
        self::assertSame('22', trim((string) file_get_contents($target . '/.nvmrc')));

        $package = json_decode((string) file_get_contents($target . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('>=22', $package['engines']['node']);
    }

    public function testDropsTheStandaloneWayneUiStubAndIgnoresTheBuild(): void
    {
        $target = $this->generate();

        self::assertFileDoesNotExist($target . '/stubs/wayne-ui.php');
        self::assertDirectoryDoesNotExist($target . '/stubs');
        self::assertStringNotContainsString('scanFiles', (string) file_get_contents($target . '/phpstan.neon'));

        $gitignore = (string) file_get_contents($target . '/.gitignore');
        foreach (['/vendor/', '/node_modules/', '/public/build/', '/public/hot'] as $line) {
            self::assertStringContainsString($line, $gitignore);
        }

        // Vite keeps local secrets in .env.*.local files, so *.local is
        // ignored too.
        self::assertContains('*.local', array_map('trim', explode("\n", $gitignore)));

        self::assertStringContainsString('frontend:', (string) file_get_contents($target . '/.gitlab-ci.yml'));
    }

    public function testRecordsTheRawVueTemplateInTheManifest(): void
    {
        $target = $this->generate();
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('raw-vue', $manifest['template']);
        self::assertSame('My Cool App', $manifest['answers']['name']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertArrayHasKey('src/Vite.php', $manifest['files']);
        self::assertArrayNotHasKey('stubs/wayne-ui.php', $manifest['files']);
    }

    public function testAdvisorAndPlaybookWiringStillApply(): void
    {
        $target = $this->generate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('waynestate/advisor', $composer['require-dev']);
        self::assertStringContainsString('.mcp.json', (string) file_get_contents($target . '/.gitignore'));
        self::assertStringContainsString('## WSU values (PLAYBOOK)', (string) file_get_contents($target . '/README.md'));

        $bare = $this->generate(false);
        $composer = json_decode((string) file_get_contents($bare . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waynestate/advisor', $composer['require-dev']);
    }

    /**
     * The baked pipeline carries the three CI gates: a bill of materials,
     * an accessibility gate over this shape's own rendered pages, and the
     * guards around deploy credentials (BEST_PRACTICES 4.15, 6.4 and 6.5).
     */
    public function testBakedPipelineCarriesTheCiGates(): void
    {
        $target = $this->generate();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        TemplateCiGatesTest::assertBakedPipelineGates($ci, 'raw-vue');
        foreach (['preflight/sbom@', 'preflight/secret-detection@', 'preflight/a11y@', "\na11y-pages:\n", "\n.deploy:\n"] as $gate) {
            self::assertStringContainsString($gate, $ci);
        }
    }

    public function testGeneratedReadmeCarriesTheSupportLine(): void
    {
        $target = $this->generate();
        $readme = (string) file_get_contents($target . '/README.md');

        self::assertStringContainsString('## Support', $readme);
        self::assertStringContainsString(
            ShapeSupport::load()->line(Permutation::RawVue, PhpFloor::resolve()),
            $readme,
        );
        self::assertStringNotContainsString('{{support_line}}', $readme);
    }

    private function generate(bool $advisor = true): string
    {
        $target = $this->makeTempDir();
        (new RawVueGenerator())->generate($target, 'My Cool App', [], PlaybookMode::Suggest, $advisor);

        return $target;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function allFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || '.sourdough' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->allFiles($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
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
