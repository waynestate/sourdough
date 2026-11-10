<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Describe;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\SkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\PlaybookAccess;

final class NewCommandTest extends TestCase
{
    /**
     * The variables PlaybookAccess::isCi looks at. They are stripped for
     * every test so a developer's CI machine cannot change what "interactive"
     * means here, and restored afterwards.
     */
    private const CI_ENV_KEYS = ['CI', 'GITLAB_CI', 'GITHUB_ACTIONS', 'SOURDOUGH_NO_INTERACTION', 'COMPOSER_NO_INTERACTION'];

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string, string|false> */
    private array $savedLlmEnv = [];

    /** @var array<string, string|false> */
    private array $savedCiEnv = [];

    /** @var list<array<int|string, mixed>> */
    private array $playbookCalls = [];

    protected function setUp(): void
    {
        foreach (['SOURDOUGH_LLM_BASE_URL', 'SOURDOUGH_LLM_API_KEY', 'SOURDOUGH_LLM_MODEL'] as $name) {
            $this->savedLlmEnv[$name] = getenv($name);
        }
        foreach (self::CI_ENV_KEYS as $name) {
            $this->savedCiEnv[$name] = getenv($name);
            putenv($name);
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

        foreach ($this->savedCiEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedCiEnv = [];
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

    public function testDryRunJsonCarriesTheNextStepsTheRealRunPrints(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $dry = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        self::assertSame(0, $dry->execute(['dir' => $target, '--laravel' => true, '--docker' => true, '--dry-run' => true, '--json' => true]));
        $data = json_decode($dry->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'cd ' . $target,
            'composer install',
            'php vendor/bin/advisor install --client=claude --write',
            'cp .env.example .env',
            'composer dev-up',
            'docker compose exec app php artisan key:generate',
            'docker compose exec app php artisan migrate',
        ], $data['next_steps']);

        // The bake prints the very same lines under "Next steps:", in order.
        $real = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        self::assertSame(0, $real->execute(['dir' => $target, '--laravel' => true, '--docker' => true]));
        $printed = explode("\n", str_replace("\r\n", "\n", $real->getDisplay()));
        $at = array_search('Next steps:', $printed, true);
        self::assertIsInt($at);
        self::assertSame(
            array_map(static fn(string $line): string => '  ' . $line, $data['next_steps']),
            array_slice($printed, $at + 1, count($data['next_steps'])),
        );
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

    public function testInertiaAloneImpliesLaravel(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--inertia' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('laravel-inertia', $data['permutation']);
        self::assertSame('Laravel + Inertia/Vue', $data['label']);
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool, string}>
     */
    public static function documentedShapes(): iterable
    {
        yield 'raw php' => [false, false, false, false, 'raw'];
        yield 'raw php + vue spa' => [true, false, true, false, 'raw-vue'];
        yield 'laravel + blade' => [false, true, false, false, 'laravel-blade'];
        yield 'laravel + blade + vue islands' => [false, true, true, false, 'laravel-blade-vue'];
        yield 'laravel + inertia/vue' => [false, true, false, true, 'laravel-inertia'];
    }

    #[DataProvider('documentedShapes')]
    public function testEachShapeFromItsDocumentedFlags(bool $raw, bool $laravel, bool $vue, bool $inertia, string $expected): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $input = ['dir' => $target];
        if ($raw) {
            $input['--raw'] = true;
        }
        if ($laravel) {
            $input['--laravel'] = true;
        }
        if ($vue) {
            $input['--vue'] = true;
        }
        if ($inertia) {
            $input['--inertia'] = true;
        }
        $input['--dry-run'] = true;
        $input['--json'] = true;

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute($input);

        self::assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $data['permutation']);
    }

    public function testLaravelInertiaFlagOrderGivesTheSamePlan(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $first = new CommandTester($this->createCommand());
        $firstExit = $first->execute([
            'dir' => $target,
            '--laravel' => true,
            '--inertia' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $second = new CommandTester($this->createCommand());
        $secondExit = $second->execute([
            'dir' => $target,
            '--inertia' => true,
            '--laravel' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $firstExit);
        self::assertSame(0, $secondExit);
        self::assertSame(
            json_decode($first->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
            json_decode($second->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testRawWithInertiaExitsOneAndNamesTheShapes(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--raw' => true,
            '--inertia' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('--raw and --laravel are mutually exclusive', $tester->getDisplay());
        self::assertStringContainsString('The five shapes are:', $tester->getDisplay());
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

    public function testLaravelBladeVueGeneratesWithTheFixtureSource(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute([
            'dir' => $target,
            '--laravel' => true,
            '--vue' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('laravel-blade-vue', $manifest['template']);
        self::assertTrue($manifest['answers']['laravel']);
        self::assertTrue($manifest['answers']['vue']);

        // The blade overlay replaced the skeleton's files and the islands
        // front end ships over it.
        self::assertFileExists($target . '/routes/web.php');
        self::assertFileExists($target . '/resources/views/dashboard.blade.php');
        self::assertFileExists($target . '/package.json');
        self::assertFileExists($target . '/vite.config.js');
        self::assertFileExists($target . '/resources/js/app.js');

        // The Next steps suit the islands shape: build the front end before
        // serving.
        self::assertStringContainsString('cp .env.example .env', $tester->getDisplay());
        self::assertStringContainsString('php artisan key:generate', $tester->getDisplay());
        self::assertStringContainsString('npm install', $tester->getDisplay());
        self::assertStringContainsString('npm run build', $tester->getDisplay());
        self::assertStringContainsString('php artisan serve', $tester->getDisplay());
        self::assertStringNotContainsString('php -S localhost:8000', $tester->getDisplay());
    }

    public function testInertiaGeneratesWithTheFixtureSource(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute([
            'dir' => $target,
            '--inertia' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('laravel-inertia', $manifest['template']);
        self::assertTrue($manifest['answers']['inertia']);

        // The overlay replaced the skeleton's files and shipped the Vue front end.
        self::assertFileExists($target . '/routes/web.php');
        self::assertFileExists($target . '/resources/views/app.blade.php');
        self::assertFileExists($target . '/resources/js/app.ts');
        self::assertFileExists($target . '/package.json');

        // The next steps suit the Inertia shape: build the front end before serving.
        self::assertStringContainsString('cp .env.example .env', $tester->getDisplay());
        self::assertStringContainsString('php artisan key:generate', $tester->getDisplay());
        self::assertStringContainsString('npm install', $tester->getDisplay());
        self::assertStringContainsString('npm run build', $tester->getDisplay());
        self::assertStringContainsString('php artisan serve', $tester->getDisplay());
        self::assertStringNotContainsString('php -S localhost:8000', $tester->getDisplay());
    }

    public function testRawVueGeneratesTheVueSpa(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--raw' => true,
            '--vue' => true,
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringNotContainsString('not available in this release', $tester->getDisplay());

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('raw-vue', $manifest['template']);
        self::assertTrue($manifest['answers']['raw']);
        self::assertTrue($manifest['answers']['vue']);

        self::assertFileExists($target . '/public/index.php');
        self::assertFileExists($target . '/src/Vite.php');
        self::assertFileExists($target . '/resources/js/App.vue');
        self::assertFileExists($target . '/package.json');

        // Build the front end, then serve it the raw way.
        $display = $tester->getDisplay();
        self::assertStringContainsString('npm install', $display);
        self::assertStringContainsString('npm run build', $display);
        self::assertStringContainsString('composer serve', $display);
        self::assertStringNotContainsString('php artisan', $display);
    }

    /**
     * The manifest is written after the --docker or --sail scaffold, so it
     * lists the scaffold's files and the composer.json it edited, and the
     * answers say which scaffold `sourdough feed` must bake again.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function scaffolds(): array
    {
        return [
            'docker' => ['--docker', ['Dockerfile', 'compose.yaml', '.dockerignore']],
            'sail' => ['--sail', ['compose.yaml']],
        ];
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('scaffolds')]
    public function testTheManifestRecordsTheScaffoldAndIsCleanRightAfterTheBake(string $flag, array $files): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute(['dir' => $target, '--laravel' => true, $flag => true]);
        self::assertSame(0, $exit, $tester->getDisplay());

        $manifest = Manifest::read($target);
        self::assertIsArray($manifest['answers']);
        self::assertTrue($manifest['answers'][ltrim($flag, '-')]);
        foreach ($files as $file) {
            self::assertArrayHasKey($file, Manifest::files($manifest), $file . ' is in the manifest');
        }
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($target));
    }

    public function testLaravelGeneratesWithTheFixtureSource(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $exit = $tester->execute([
            'dir' => $target,
            '--laravel' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('laravel-blade', $manifest['template']);
        self::assertTrue($manifest['answers']['laravel']);

        // The overlay replaced the skeleton's files.
        self::assertFileExists($target . '/routes/web.php');
        self::assertFileExists($target . '/resources/views/dashboard.blade.php');

        // The Next steps suit the Laravel shape.
        self::assertStringContainsString('cp .env.example .env', $tester->getDisplay());
        self::assertStringContainsString('php artisan key:generate', $tester->getDisplay());
        self::assertStringContainsString('php artisan serve', $tester->getDisplay());
        self::assertStringNotContainsString('php -S localhost:8000', $tester->getDisplay());
    }

    public function testHostileNameExitsOneWritesStderrAndCreatesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        // Run the real binary so stdout and stderr stay separate: the error
        // must land on stderr, where scripts can read it.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/sourdough', 'new', $target, '--name', 'a"b'], $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertStringContainsString('must start with a letter or number', $stderr);
        self::assertDirectoryDoesNotExist($target);
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
        // Playbook is the default now, so a plain run records it too. Keys are sorted by Manifest.
        self::assertSame(['name' => 'demo', 'playbook' => true, 'raw' => true], $manifest['answers']);
    }

    public function testDefaultRunPrintsThePlaybookNextStep(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('WSU values: Playbook installs with composer install. Check it any time with: composer playbook', $tester->getDisplay());
    }

    public function testPlaybookFlagRequiresThePackageAndAddsTheRepository(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--playbook' => true,
        ]);

        self::assertSame(0, $exit);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/playbook.git'],
        ], $composer['repositories']);
        // Composer must be told git.wayne.edu is a GitLab host or the token
        // is never sent to it.
        self::assertSame(['git.wayne.edu'], $composer['config']['gitlab-domains']);
        // The default wiring stays in place under --playbook.
        self::assertSame("WSU's shared values; private, git.wayne.edu", $composer['suggest']['waynestate/playbook']);

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($manifest['answers']['playbook']);

        // The hint is for the default mode; with --playbook the package is already in.
        self::assertStringNotContainsString('or re-run with --playbook', $tester->getDisplay());
    }

    public function testNoPlaybookFlagSkipsThePlaybookWiring(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute([
            'dir' => $target,
            '--no-playbook' => true,
        ]);

        self::assertSame(0, $exit);
        // None mode never asks the machine about access.
        self::assertSame([], $this->playbookCalls);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('suggest', $composer);
        self::assertArrayNotHasKey('repositories', $composer);
        self::assertArrayNotHasKey('waynestate/playbook', $composer['require']);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringNotContainsString('PLAYBOOK', $env);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('WSU values (PLAYBOOK)', $readme);

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($manifest['answers']['no-playbook']);

        self::assertStringNotContainsString('or re-run with --playbook', $tester->getDisplay());
    }

    public function testPlaybookAndNoPlaybookAreMutuallyExclusive(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--playbook' => true,
            '--no-playbook' => true,
        ]);

        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('--playbook and --no-playbook are mutually exclusive', $tester->getDisplay());
    }

    public function testPlaybookNoAccessRefusesAndWritesNothing(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('no-access')));
        $exit = $tester->execute([
            'dir' => $target,
        ]);

        self::assertSame(1, $exit);
        // The refusal happens before anything is written.
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('You need read access to Playbook (a private package on git.wayne.edu) to make a Wayne State app.', $tester->getDisplay());
        self::assertStringContainsString('read_repository', $tester->getDisplay());
        self::assertStringContainsString('Then run this command again.', $tester->getDisplay());
    }

    public function testPlaybookAccessGeneratesRequireWithGitlabDomains(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute([
            'dir' => $target,
        ]);

        self::assertSame(0, $exit);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/playbook.git'],
        ], $composer['repositories']);
        self::assertSame(['git.wayne.edu'], $composer['config']['gitlab-domains']);
    }

    public function testPlaybookOfflineWarnsAndGenerates(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('offline')));
        $exit = $tester->execute([
            'dir' => $target,
        ]);

        // Offline is not a refusal: the app is generated and the developer
        // hears about it before composer install fails for them.
        self::assertSame(0, $exit);
        self::assertFileExists($target . '/composer.json');
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
        self::assertStringContainsString('Could not reach git.wayne.edu to check your Playbook access; composer install will need it.', $tester->getDisplay());
    }

    public function testPlaybookSkippedWhenNonInteractiveOrCi(): void
    {
        $parent = $this->makeTempDir();

        // Non-interactive input: no network check, the wiring still goes in.
        $target = $parent . '/non-interactive';
        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute(['dir' => $target], ['interactive' => false]);

        self::assertSame(0, $exit);
        self::assertSame([], $this->playbookCalls);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);

        // A CI environment: same skip, same result.
        putenv('CI=1');
        $target = $parent . '/ci';
        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute(['dir' => $target]);

        self::assertSame(0, $exit);
        self::assertSame([], $this->playbookCalls);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
    }

    public function testNoPlaybookSkipsDetectionAndWiring(): void
    {
        $parent = $this->makeTempDir();

        // The default run wires Playbook, so there is something to skip.
        $defaultTarget = $parent . '/default';
        $tester = new CommandTester($this->createCommand());
        self::assertSame(0, $tester->execute(['dir' => $defaultTarget]));
        $composer = json_decode((string) file_get_contents($defaultTarget . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);

        // --no-playbook never detects and leaves no wiring behind.
        $target = $parent . '/demo';
        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute(['dir' => $target, '--no-playbook' => true]);

        self::assertSame(0, $exit);
        self::assertSame([], $this->playbookCalls);

        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('suggest', $composer);
        self::assertArrayNotHasKey('repositories', $composer);
        self::assertArrayNotHasKey('waynestate/playbook', $composer['require']);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringNotContainsString('PLAYBOOK', $env);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('WSU values (PLAYBOOK)', $readme);
    }

    public function testPlaybookFlagIsNoOp(): void
    {
        // Both apps get the same name (the dir basename) so the only thing
        // that may differ between the runs is the Playbook wiring.
        $withParent = $this->makeTempDir();
        $with = $withParent . '/demo';
        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        self::assertSame(0, $tester->execute(['dir' => $with, '--playbook' => true]));
        $withComposer = json_decode((string) file_get_contents($with . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^0.1', $withComposer['require']['waynestate/playbook']);

        // Without it: the very same wiring, so the flag changed nothing.
        $withoutParent = $this->makeTempDir();
        $without = $withoutParent . '/demo';
        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        self::assertSame(0, $tester->execute(['dir' => $without]));
        $withoutComposer = json_decode((string) file_get_contents($without . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($withComposer, $withoutComposer);
    }

    public function testDryRunJsonHasPlaybookKey(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand(null, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute([
            'dir' => $target,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertDirectoryDoesNotExist($target);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['mode' => 'require', 'access' => 'access', 'via' => 'git'], $data['playbook']);
    }

    public function testDescribeNeverDetectsPlaybook(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';
        $this->setLlmEnv();

        $describe = $this->describeWithResponse($this->validPlanJson());
        $tester = new CommandTester($this->createCommand($describe, null, $this->fakePlaybookAccess('access')));
        $exit = $tester->execute([
            'dir' => $target,
            '--describe' => 'A blog with posts.',
        ]);

        self::assertSame(0, $exit);
        self::assertDirectoryDoesNotExist($target);
        // Describing plans an app; it never asks the machine about access.
        self::assertSame([], $this->playbookCalls);
    }

    public function testDryRunListsTheAdvisorStep(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Wire ADVISOR into the app', $tester->getDisplay());
    }

    public function testNoAdvisorDryRunOmitsTheAdvisorStep(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--dry-run' => true,
            '--no-advisor' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('Wire ADVISOR into the app', $tester->getDisplay());
    }

    public function testDefaultRunPrintsTheAdvisorNextStep(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('php vendor/bin/advisor install --client=claude --write', $tester->getDisplay());
    }

    public function testNoAdvisorFlagSkipsTheAdvisorWiring(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/demo';

        $tester = new CommandTester($this->createCommand());
        $exit = $tester->execute([
            'dir' => $target,
            '--no-advisor' => true,
        ]);

        self::assertSame(0, $exit);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waynestate/advisor', $composer['require-dev']);

        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertStringNotContainsString('.mcp.json', $gitignore);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('ADVISOR', $readme);

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($manifest['answers']['no-advisor']);

        self::assertStringNotContainsString('php vendor/bin/advisor install', $tester->getDisplay());
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

    private function createCommand(?Describe $describe = null, ?SkeletonSource $skeletonSource = null, ?PlaybookAccess $playbookAccess = null): NewCommand
    {
        // Tests get a network-free access check by default; tests that care
        // about the outcome inject their own status.
        return new NewCommand($describe, $skeletonSource, $playbookAccess ?? $this->fakePlaybookAccess('access'));
    }

    /**
     * A PlaybookAccess that never touches the network: the injected runner
     * answers the composer credential probe with "no token" and the git
     * probe with $status. Every argv it sees lands in $this->playbookCalls.
     */
    private function fakePlaybookAccess(string $status): PlaybookAccess
    {
        $this->playbookCalls = [];

        /** @param list<string> $argv @param array<string, string> $env @return array{0: int, 1: string} */
        $run = function (array $argv, array $env, int $timeout) use ($status): array {
            $this->playbookCalls[] = $argv;

            if ('composer' === ($argv[0] ?? '')) {
                return [1, ''];
            }

            return match ($status) {
                'access' => [0, ''],
                'no-access' => [128, 'fatal: Authentication failed for \'https://git.wayne.edu/enterprise-apps/playbook.git\''],
                default => [124, 'timeout'],
            };
        };

        return new PlaybookAccess($run, [], 1);
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
