<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

final class BinAutoloadTest extends TestCase
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

    public function testRunsFromAGlobalStyleInstallLayout(): void
    {
        // Lay out a temp dir the way `composer global require` lays out the
        // package: the autoloader sits three folders above bin/.
        $home = $this->makeTempDir();
        $bin = $home . '/vendor/waynestate/sourdough/bin';
        mkdir($bin, 0777, true);
        copy(__DIR__ . '/../bin/sourdough', $bin . '/sourdough');
        file_put_contents($home . '/vendor/autoload.php', "<?php require '" . __DIR__ . "/../vendor/autoload.php';\n");

        // Run the real binary so stdout and stderr stay separate.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, $bin . '/sourdough', '--version'], $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringStartsWith('Sourdough ', $stdout);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-bin-test-' . bin2hex(random_bytes(8));
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
