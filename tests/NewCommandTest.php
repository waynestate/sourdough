<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;

final class NewCommandTest extends TestCase
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

    public function testDryRunWritesNothingAndExitsZero(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('Permutation: Raw PHP with WayneUI', $tester->getDisplay());
        self::assertStringContainsString('Copy the raw template into', $tester->getDisplay());
    }

    public function testDryRunJsonPrintsValidPlanJson(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('raw', $data['permutation']);
        self::assertSame("Raw PHP with WayneUI's standalone path", $data['label']);
        self::assertSame($target, $data['dir']);
        self::assertIsArray($data['steps']);
        self::assertNotEmpty($data['steps']);
    }

    public function testDryRunJsonReflectsThePermutationFlags(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--laravel' => true,
            '--inertia' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('laravel-inertia', $data['permutation']);
        self::assertSame('Laravel + Inertia/Vue', $data['label']);
    }

    public function testJsonWithoutDryRunExitsTwoAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--json' => true,
        ]);

        self::assertSame(2, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('--json is only available with --dry-run', $tester->getDisplay());
    }

    public function testInertiaWithoutLaravelExitsOne(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--inertia' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--inertia needs --laravel', $tester->getDisplay());
    }

    public function testRawWithLaravelExitsOne(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--raw' => true,
            '--laravel' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--raw and --laravel are mutually exclusive', $tester->getDisplay());
    }

    public function testNonRawRealRunExitsOneAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--laravel' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('not available in this release', $tester->getDisplay());
    }

    public function testNoColorIsHonored(): void
    {
        // The CLI renders through Symfony Console output, whose decoration is
        // auto-detected from the environment. FORCE_COLOR would enable color,
        // but NO_COLOR must take precedence.
        $this->withColorEnv('1', '1', function (): void {
            $output = new StreamOutput(fopen('php://memory', 'w+'));
            self::assertFalse($output->isDecorated(), 'NO_COLOR should disable color even when FORCE_COLOR is set.');
        });
    }

    public function testColorIsEnabledWithoutNoColor(): void
    {
        // The companion: without NO_COLOR, FORCE_COLOR does enable color, so
        // the NO_COLOR test above is meaningful.
        $this->withColorEnv(null, '1', function (): void {
            $output = new StreamOutput(fopen('php://memory', 'w+'));
            self::assertTrue($output->isDecorated(), 'FORCE_COLOR should enable color when NO_COLOR is not set.');
        });
    }

    private function createCommand(): NewCommand
    {
        return new NewCommand();
    }

    private function withColorEnv(?string $noColor, ?string $forceColor, callable $fn): void
    {
        $saved = [
            'hadServerNoColor' => array_key_exists('NO_COLOR', $_SERVER),
            'serverNoColor' => $_SERVER['NO_COLOR'] ?? null,
            'hadServerForceColor' => array_key_exists('FORCE_COLOR', $_SERVER),
            'serverForceColor' => $_SERVER['FORCE_COLOR'] ?? null,
            'envNoColor' => getenv('NO_COLOR'),
            'envForceColor' => getenv('FORCE_COLOR'),
        ];

        $this->applyColorEnv($noColor, $forceColor);

        try {
            $fn();
        } finally {
            if ($saved['hadServerNoColor']) {
                $_SERVER['NO_COLOR'] = $saved['serverNoColor'];
            } else {
                unset($_SERVER['NO_COLOR']);
            }
            if ($saved['hadServerForceColor']) {
                $_SERVER['FORCE_COLOR'] = $saved['serverForceColor'];
            } else {
                unset($_SERVER['FORCE_COLOR']);
            }
            $this->restoreEnv('NO_COLOR', $saved['envNoColor']);
            $this->restoreEnv('FORCE_COLOR', $saved['envForceColor']);
        }
    }

    private function applyColorEnv(?string $noColor, ?string $forceColor): void
    {
        if ($noColor === null) {
            unset($_SERVER['NO_COLOR']);
            putenv('NO_COLOR');
        } else {
            $_SERVER['NO_COLOR'] = $noColor;
            putenv("NO_COLOR=$noColor");
        }
        if ($forceColor === null) {
            unset($_SERVER['FORCE_COLOR']);
            putenv('FORCE_COLOR');
        } else {
            $_SERVER['FORCE_COLOR'] = $forceColor;
            putenv("FORCE_COLOR=$forceColor");
        }
    }

    private function restoreEnv(string $key, string|false $saved): void
    {
        if ($saved === false) {
            putenv($key);
        } else {
            putenv("$key=$saved");
        }
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
