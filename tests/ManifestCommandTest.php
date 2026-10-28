<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\ManifestCommand;
use Waynestate\Sourdough\Generator\RawGenerator;

final class ManifestCommandTest extends TestCase
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

    public function testCheckIsCleanRightAfterGeneration(): void
    {
        $target = $this->generateApp();

        $tester = $this->tester();
        $exit = $tester->execute(['dir' => $target, '--check' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Clean', $tester->getDisplay());
    }

    public function testCheckReportsChangedAndMissingManagedFiles(): void
    {
        $target = $this->generateApp();

        file_put_contents($target . '/composer.json', '{"name": "wsu/tampered"}');
        unlink($target . '/.gitignore');

        $tester = $this->tester();
        $exit = $tester->execute(['dir' => $target, '--check' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('changed  composer.json', $tester->getDisplay());
        self::assertStringContainsString('missing  .gitignore', $tester->getDisplay());
    }

    public function testCheckIgnoresEditedSeededFiles(): void
    {
        $target = $this->generateApp();

        file_put_contents($target . '/README.md', "The app owner's own documentation.\n");

        $tester = $this->tester();
        $exit = $tester->execute(['dir' => $target, '--check' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Clean', $tester->getDisplay());
    }

    public function testCheckWithoutTheFlagExitsTwo(): void
    {
        $target = $this->generateApp();

        $tester = $this->tester();
        $exit = $tester->execute(['dir' => $target]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('--check', $tester->getDisplay());
    }

    public function testCheckWithoutAManifestExitsTwo(): void
    {
        $target = $this->makeTempDir();

        $tester = $this->tester();
        $exit = $tester->execute(['dir' => $target, '--check' => true]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('No Sourdough manifest', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ManifestCommand());
    }

    private function generateApp(): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App');

        return $target;
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
