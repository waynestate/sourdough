<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Manifest;

final class ManifestTest extends TestCase
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

    public function testManifestIsWrittenWithTheExpectedShape(): void
    {
        $target = $this->generateApp();
        $manifest = $this->readManifest($target);

        self::assertSame(1, $manifest['schema']);
        self::assertSame('raw', $manifest['template']);
        // 'dev' from a branch, the tag's version on a tag build: both are true.
        self::assertSame(Manifest::packageVersion(), $manifest['sourdough_version']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest['generated_at']);
        self::assertIsArray($manifest['files']);
        self::assertArrayHasKey('composer.json', $manifest['files']);
        self::assertArrayHasKey('public/index.php', $manifest['files']);
        self::assertArrayHasKey('scripts/release.sh', $manifest['files']);
    }

    public function testManifestHashesMatchTheFiles(): void
    {
        $target = $this->generateApp();
        $manifest = $this->readManifest($target);

        foreach ($manifest['files'] as $relative => $info) {
            $path = $target . '/' . $relative;
            self::assertFileExists($path, $relative);
            self::assertSame(hash_file('sha256', $path), $info['sha256'], $relative);
        }
    }

    public function testSeededFilesAreClassifiedSeededAndTheRestManaged(): void
    {
        $target = $this->generateApp();
        $manifest = $this->readManifest($target);

        self::assertSame('seeded', $manifest['files']['.env.example']['class']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertSame('managed', $manifest['files']['composer.json']['class']);
        self::assertSame('managed', $manifest['files']['public/index.php']['class']);
        self::assertSame('managed', $manifest['files']['scripts/release.sh']['class']);
        self::assertSame('managed', $manifest['files']['.gitlab-ci.yml']['class']);
    }

    public function testManifestIsPrettyPrintedWithSortedKeysAndTrailingNewline(): void
    {
        $target = $this->generateApp();
        $json = (string) file_get_contents($target . '/.sourdough/manifest.json');

        // Trailing newline, pretty-printed (indented) JSON.
        self::assertStringEndsWith("\n", $json);
        self::assertStringContainsString("\n  ", $json);

        // Top-level keys are sorted.
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['answers', 'files', 'generated_at', 'schema', 'sourdough_version', 'template'],
            array_keys($manifest),
        );

        // File paths are sorted and use forward slashes. JSON object keys are
        // always strings, so strval is a no-op here.
        $paths = array_map('strval', array_keys($manifest['files']));
        $sorted = $paths;
        sort($sorted);
        self::assertSame($sorted, $paths);
        foreach ($paths as $relative) {
            self::assertStringNotContainsString('\\', $relative, $relative);
        }

        // Re-encoding the decoded manifest reproduces the file byte for byte.
        $reencoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        self::assertSame($reencoded, $json);
    }

    public function testOrderingIsStableAcrossRuns(): void
    {
        $first = $this->readManifest($this->generateApp());
        $second = $this->readManifest($this->generateApp());

        unset($first['generated_at'], $second['generated_at']);
        self::assertSame($first, $second);
    }

    public function testBuildLeavesOutTheSqliteDatabase(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/database/seeders', 0777, true);
        touch($dir . '/database/database.sqlite');
        file_put_contents($dir . '/database/seeders/DatabaseSeeder.php', "<?php\n\n// The app's seeder.\n");

        $manifest = (new Manifest('raw', ['name' => 'My Cool App'], []))->build($dir);

        self::assertArrayHasKey('database/seeders/DatabaseSeeder.php', $manifest['files']);
        self::assertArrayNotHasKey('database/database.sqlite', $manifest['files']);
    }

    public function testCheckIsCleanRightAfterGeneration(): void
    {
        $target = $this->generateApp();

        $result = Manifest::check($target);

        self::assertSame([], $result['changed']);
        self::assertSame([], $result['missing']);
    }

    public function testCheckReportsChangedAndMissingManagedFiles(): void
    {
        $target = $this->generateApp();

        file_put_contents($target . '/composer.json', '{"name": "wsu/tampered"}');
        unlink($target . '/.gitignore');

        $result = Manifest::check($target);

        self::assertSame(['composer.json'], $result['changed']);
        self::assertSame(['.gitignore'], $result['missing']);
    }

    public function testCheckIgnoresEditedSeededFiles(): void
    {
        $target = $this->generateApp();

        file_put_contents($target . '/README.md', "The app owner's own documentation.\n");
        file_put_contents($target . '/.env.example', "APP_ENV=local\nMY_VARIABLE=1\n");

        $result = Manifest::check($target);

        self::assertSame([], $result['changed']);
        self::assertSame([], $result['missing']);
    }

    public function testCheckThrowsWithoutAManifest(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No Sourdough manifest found');

        Manifest::check($target);
    }

    public function testPackageVersionIsDevOrARelease(): void
    {
        // A branch checkout says 'dev'; a tag build says the tag. This test
        // used to insist on 'dev' and so only ever failed on release day,
        // which is the worst day for a test to discover it has opinions.
        self::assertMatchesRegularExpression('/^(dev|v?\d+\.\d+\.\d+([-+.][0-9A-Za-z.-]+)?)$/', Manifest::packageVersion());

        $pretty = class_exists(\Composer\InstalledVersions::class)
            ? \Composer\InstalledVersions::getPrettyVersion('waynestate/sourdough')
            : null;
        self::assertSame(Manifest::versionFrom($pretty), Manifest::packageVersion());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function prettyVersions(): iterable
    {
        yield 'no version' => [null, 'dev'];
        yield 'empty' => ['', 'dev'];
        yield 'branch' => ['dev-main', 'dev'];
        yield 'feature branch' => ['dev-esc/sourdough-tag-manifest-1109', 'dev'];
        yield 'no git at all' => ['1.0.0+no-version-set', 'dev'];
        yield 'release tag' => ['0.4.1', '0.4.1'];
        yield 'prerelease tag' => ['0.5.0-rc.1', '0.5.0-rc.1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prettyVersions')]
    public function testVersionFromFallsBackToDevForAnythingButARelease(?string $pretty, string $expected): void
    {
        self::assertSame($expected, Manifest::versionFrom($pretty));
    }

    private function generateApp(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App');

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $target): array
    {
        $content = (string) file_get_contents($target . '/.sourdough/manifest.json');

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
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
