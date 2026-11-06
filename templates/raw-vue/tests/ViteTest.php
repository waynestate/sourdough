<?php

declare(strict_types=1);

use App\Vite;
use PHPUnit\Framework\TestCase;

/**
 * App\Vite turns Vite's manifest (or its dev server) into tags. These
 * tests bake a tiny public/ dir per case, so they need no npm at all.
 */
final class ViteTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/vite-test-' . bin2hex(random_bytes(6));
        mkdir($this->public . '/build', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/hot', '/build/manifest.json'] as $file) {
            if (is_file($this->public . $file)) {
                unlink($this->public . $file);
            }
        }
        rmdir($this->public . '/build');
        rmdir($this->public);
    }

    public function testBuiltTagsCarryTheNonceTheCssAndTheImportedChunks(): void
    {
        $this->manifest([
            'resources/js/app.js' => [
                'file' => 'assets/app-abc.js',
                'css' => ['assets/app-abc.css'],
                'imports' => ['_vendor.js'],
            ],
            '_vendor.js' => ['file' => 'assets/vendor-def.js', 'css' => ['assets/vendor-def.css']],
        ]);

        $tags = (new Vite($this->public))->tags('resources/js/app.js', 'n0nce');

        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/app-abc.css" nonce="n0nce">', $tags);
        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/vendor-def.css" nonce="n0nce">', $tags);
        self::assertStringContainsString('<link rel="modulepreload" href="/build/assets/vendor-def.js" nonce="n0nce">', $tags);
        self::assertStringContainsString('<script type="module" src="/build/assets/app-abc.js" nonce="n0nce"></script>', $tags);
    }

    public function testTheDevServerWinsWhileItRuns(): void
    {
        file_put_contents($this->public . '/hot', "http://localhost:5173\n");

        $vite = new Vite($this->public);
        $tags = $vite->tags('resources/js/app.js', 'n0nce');

        self::assertStringContainsString('src="http://localhost:5173/@vite/client"', $tags);
        self::assertStringContainsString('src="http://localhost:5173/resources/js/app.js"', $tags);

        $policy = $vite->withDevServer(['script-src' => ["'self'"], 'connect-src' => ["'self'"]]);
        self::assertSame(["'self'", 'http://localhost:5173'], $policy['script-src']);
        self::assertSame(["'self'", 'http://localhost:5173', 'ws://localhost:5173'], $policy['connect-src']);
    }

    public function testProductionIgnoresAStrayHotFile(): void
    {
        file_put_contents($this->public . '/hot', 'http://localhost:5173');
        $this->manifest(['resources/js/app.js' => ['file' => 'assets/app.js']]);

        $vite = new Vite($this->public, false);

        self::assertNull($vite->devOrigin());
        self::assertStringContainsString('/build/assets/app.js', $vite->tags('resources/js/app.js', 'n'));
    }

    public function testAHotFileThatIsNotAnOriginIsIgnored(): void
    {
        file_put_contents($this->public . '/hot', 'javascript:alert(1)');

        self::assertNull((new Vite($this->public))->devOrigin());
    }

    public function testNoBuildAndNoDevServerSaysWhatToRun(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        (new Vite($this->public))->tags('resources/js/app.js', 'n');
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     */
    private function manifest(array $manifest): void
    {
        file_put_contents($this->public . '/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
