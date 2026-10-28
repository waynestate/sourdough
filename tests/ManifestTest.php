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
        self::assertSame('dev', $manifest['sourdough_version']);
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

    public function testPackageVersionFallsBackToDev(): void
    {
        // From a git checkout without a release tag the version is unknown.
        self::assertSame('dev', Manifest::packageVersion());
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
