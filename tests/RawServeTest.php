<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * The raw app's local server story: the template ships a composer serve
 * script that boots the app as local (SESAME's fake driver), the Next steps
 * point at it, and the smoke gate proves a baked app really serves.
 */
final class RawServeTest extends TestCase
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

    public function testTemplateShipsTheServeScript(): void
    {
        $template = json_decode((string) file_get_contents(__DIR__ . '/../templates/raw/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'Composer\\Config::disableProcessTimeout',
            '@putenv APP_ENV=local',
            '@php -S localhost:8000 -t public',
        ], $template['scripts']['serve']);
        self::assertSame(
            'Start the local dev server on http://localhost:8000 with APP_ENV=local, so SESAME uses its fake driver.',
            $template['scripts-descriptions']['serve'],
        );
    }

    public function testBakedAppKeepsTheServeScriptAfterTest(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::None);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // With Playbook off the scripts are the template's own, in order:
        // serve sits right after test.
        self::assertSame(['test', 'serve', 'lint', 'fix', 'analyse', 'proctor', 'ci'], array_keys($composer['scripts']));
        self::assertSame([
            'Composer\\Config::disableProcessTimeout',
            '@putenv APP_ENV=local',
            '@php -S localhost:8000 -t public',
        ], $composer['scripts']['serve']);
        self::assertSame(
            'Start the local dev server on http://localhost:8000 with APP_ENV=local, so SESAME uses its fake driver.',
            $composer['scripts-descriptions']['serve'],
        );
    }

    public function testRawNextStepsPointAtComposerServe(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester(new NewCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--raw' => true,
            '--no-playbook' => true,
            '--no-advisor' => true,
        ]);

        self::assertSame(0, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString('composer install', $display);
        self::assertStringContainsString('composer serve', $display);
        self::assertStringNotContainsString('php -S', $display);
    }

    public function testSmokeGateChecksTheNotFoundPath(): void
    {
        $smoke = (string) file_get_contents(__DIR__ . '/../scripts/smoke-shape.sh');

        self::assertStringContainsString('/nope', $smoke);
    }

    /**
     * Generate an app from the real templates/raw dir and return the target.
     */
    private function generateFromRealTemplate(PlaybookMode $playbook): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App', [], $playbook, false);

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
