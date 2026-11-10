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

    /**
     * The raw shapes' .gitattributes checks *.ps1 out with CRLF, so every
     * clone of a fresh app has scripts/parity.ps1 in CRLF. That is a
     * checkout, not an edit: the check forgives line endings, as feed does.
     */
    public function testCheckForgivesLineEndings(): void
    {
        $target = $this->generateApp();
        $file = $target . '/scripts/parity.ps1';
        $lf = str_replace("\r\n", "\n", (string) file_get_contents($file));
        self::assertStringNotContainsString("\r\n", $lf);
        // Start from LF in both environments: on a Windows checkout the
        // template's parity.ps1 arrives in CRLF, and the check forgives the
        // difference, as feed does.
        file_put_contents($file, $lf);
        file_put_contents($file, str_replace("\n", "\r\n", $lf));

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['dir' => $target, '--check' => true]), $tester->getDisplay());

        file_put_contents($file, str_replace("\n", "\r\n", $lf) . "Write-Host 'edited'\r\n");
        self::assertSame(1, $tester->execute(['dir' => $target, '--check' => true]));
        self::assertStringContainsString('changed  scripts/parity.ps1', $tester->getDisplay());
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
