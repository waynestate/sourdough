<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Describe;

final class NewCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string, string|false> */
    private array $savedLlmEnv = [];

    protected function setUp(): void
    {
        foreach (['SOURDOUGH_LLM_BASE_URL', 'SOURDOUGH_LLM_API_KEY', 'SOURDOUGH_LLM_MODEL'] as $name) {
            $this->savedLlmEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        foreach ($this->savedLlmEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedLlmEnv = [];
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

    public function testManifestRecordsTheChosenFlags(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--raw' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'demo', 'raw' => true], $manifest['answers']);
    }

    public function testDescribeAndPlanAreMutuallyExclusive(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $planFile = $this->writePlanFile($this->validPlanJson());

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--describe' => 'A blog.',
            '--plan' => $planFile,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--describe and --plan are mutually exclusive', $tester->getDisplay());
    }

    public function testPlanOnlyWithoutDescribeExitsOne(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--plan-only' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--plan-only is only available with --describe', $tester->getDisplay());
    }

    public function testDirRequiredWithoutDescribe(): void
    {
        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('The dir argument is required unless you use --describe', $tester->getDisplay());
    }

    public function testDescribePlanOnlyPrintsJsonAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $this->setLlmEnv();

        $describe = $this->describeWithResponse($this->validPlanJson());
        $tester = new CommandTester($this->createCommand($describe));
        $exit = $tester->execute([
            'dir' => $target,
            '--describe' => 'A blog with posts.',
            '--plan-only' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertDirectoryDoesNotExist($target);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('raw', $data['permutation']);
        self::assertSame('demo-app', $data['name']);
    }

    public function testDescribePrintsHumanPlanAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $this->setLlmEnv();

        $describe = $this->describeWithResponse($this->validPlanJson());
        $tester = new CommandTester($this->createCommand($describe));
        $exit = $tester->execute([
            'dir' => $target,
            '--describe' => 'A blog with posts.',
        ]);

        self::assertSame(0, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('Permutation: Raw PHP with WayneUI', $tester->getDisplay());
        self::assertStringContainsString('Name:        demo-app', $tester->getDisplay());
        self::assertStringContainsString('Generation from a plan is not built yet', $tester->getDisplay());
    }

    public function testDescribeMissingEnvExitsOne(): void
    {
        $this->unsetLlmEnv();
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--describe' => 'A blog with posts.',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('SOURDOUGH_LLM_BASE_URL', $tester->getDisplay());
    }

    public function testPlanFileGeneratesApp(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $planFile = $this->writePlanFile($this->validPlanJson());

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--plan' => $planFile,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        // The plan's name wins over the dir basename.
        self::assertSame('demo-app', $manifest['answers']['name']);
        self::assertTrue($manifest['answers']['raw']);
    }

    public function testPlanFileInvalidExitsOne(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $planFile = $this->writePlanFile((string) json_encode(['permutation' => 'raw', 'name' => 'demo-app']));

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--plan' => $planFile,
        ]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('not a valid plan', $tester->getDisplay());
    }

    public function testPlanFileMissingExitsOne(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--plan' => $parent . '/nope.json',
        ]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testNoColorIsHonored(): void
    {
        // The CLI renders through Symfony Console output, whose decoration is
        // auto-detected from the environment. FORCE_COLOR would enable color,
        // but NO_COLOR must take precedence.
        $this->withColorEnv('1', '1', function (): void {
            $stream = fopen('php://memory', 'w+');
            self::assertIsResource($stream);
            $output = new StreamOutput($stream);
            self::assertFalse($output->isDecorated(), 'NO_COLOR should disable color even when FORCE_COLOR is set.');
        });
    }

    public function testColorIsEnabledWithoutNoColor(): void
    {
        // The companion: without NO_COLOR, FORCE_COLOR does enable color, so
        // the NO_COLOR test above is meaningful.
        $this->withColorEnv(null, '1', function (): void {
            $stream = fopen('php://memory', 'w+');
            self::assertIsResource($stream);
            $output = new StreamOutput($stream);
            self::assertTrue($output->isDecorated(), 'FORCE_COLOR should enable color when NO_COLOR is not set.');
        });
    }

    private function createCommand(?Describe $describe = null): NewCommand
    {
        return new NewCommand($describe);
    }

    private function describeWithResponse(string $content): Describe
    {
        $fake = new ClientFake([
            CreateResponse::from([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'created' => 1_677_652_288,
                'model' => 'test-model',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $content,
                        ],
                        'logprobs' => null,
                        'finish_reason' => 'stop',
                    ],
                ],
            ], MetaInformation::from([])),
        ]);

        return new Describe($fake);
    }

    private function validPlanJson(): string
    {
        return (string) json_encode([
            'permutation' => 'raw',
            'parts' => ['sesame', 'helmet'],
            'name' => 'demo-app',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function writePlanFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/sourdough-plan-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function setLlmEnv(): void
    {
        putenv('SOURDOUGH_LLM_BASE_URL=https://example.test/v1');
        putenv('SOURDOUGH_LLM_API_KEY=test-key');
        putenv('SOURDOUGH_LLM_MODEL=test-model');
    }

    private function unsetLlmEnv(): void
    {
        putenv('SOURDOUGH_LLM_BASE_URL');
        putenv('SOURDOUGH_LLM_API_KEY');
        putenv('SOURDOUGH_LLM_MODEL');
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
