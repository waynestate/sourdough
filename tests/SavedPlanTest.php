<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\AppPlan;
use Waynestate\Sourdough\Command\ManifestCommand;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Describe;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;

/**
 * Every bake saves its plan in the app as `.sourdough/plan.yaml` (ADR-0014,
 * decision 3): the recipe card in the jar. These tests bake through the real
 * `sourdough new` command and read the card back through the same reader
 * `--plan` uses, so "it round-trips" means "the CLI can bake from it".
 *
 * Feed's side (the card is never fed or flagged) is in FeedCommandTest.
 */
final class SavedPlanTest extends TestCase
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

    public function testAPlanFileBakeSavesThePlanAndItReadsBackEqual(): void
    {
        $plan = $this->blogPlan();
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--plan' => $this->planFile(json_encode($plan, JSON_THROW_ON_ERROR), 'json')]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($target . '/' . AppPlan::FILE);

        $saved = (new Describe())->fromFile($target . '/' . AppPlan::FILE);
        self::assertEquals(AppPlan::fromArray($plan), $saved);
        self::assertSame($plan, $saved->toArray());
    }

    public function testTheSavedPlanIsCommentedBlockYaml(): void
    {
        $target = $this->makeTempDir() . '/demo';
        $this->newApp(['dir' => $target, '--plan' => $this->planFile(json_encode($this->blogPlan(), JSON_THROW_ON_ERROR), 'json')]);

        $yaml = (string) file_get_contents($target . '/' . AppPlan::FILE);
        self::assertStringStartsWith("# The plan this app was baked from, saved by sourdough new (ADR-0014).\n", $yaml);
        self::assertStringContainsString("#   sourdough new <dir> --plan=.sourdough/plan.yaml\n", $yaml);
        self::assertStringContainsString(
            "permutation: raw\n"
            . "parts:\n  - sesame\n  - helmet\n"
            . "name: demo-app\n"
            . "models:\n  - name: Post\n    fields:\n      - { name: title, type: string }\n      - { name: body, type: text }\n"
            . "pages:\n  - /\n  - /posts\n",
            $yaml,
        );
        self::assertStringEndsWith("\n", $yaml);
    }

    public function testAFlagsBakeSavesTheShapeTheNameAndEveryPart(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--raw' => true, '--vue' => true, '--name' => 'Flag Demo']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $saved = (new Describe())->fromFile($target . '/' . AppPlan::FILE);
        self::assertSame([
            'permutation' => 'raw-vue',
            'parts' => ['sesame', 'helmet', 'proctor', 'preflight'],
            'name' => 'Flag Demo',
        ], $saved->toArray());
    }

    public function testALaravelBakeSavesThePlan(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--inertia' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $saved = (new Describe())->fromFile($target . '/' . AppPlan::FILE);
        self::assertSame(Permutation::LaravelInertia, $saved->permutation);
        self::assertSame('demo', $saved->name);
    }

    public function testAnExplicitNameWinsInTheSavedPlan(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp([
            'dir' => $target,
            '--plan' => $this->planFile(json_encode($this->blogPlan(), JSON_THROW_ON_ERROR), 'json'),
            '--name' => 'Renamed',
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $saved = (new Describe())->fromFile($target . '/' . AppPlan::FILE);
        self::assertSame('Renamed', $saved->name);
        self::assertSame('Renamed', Manifest::read($target)['answers']['name'] ?? null);
        self::assertSame($this->blogPlan()['models'], $saved->models, 'only the name changes');
    }

    public function testTheSavedPlanBakesTheSameLoafAgain(): void
    {
        $parent = $this->makeTempDir();
        $first = $parent . '/first';
        $second = $parent . '/second';

        $this->newApp(['dir' => $first, '--plan' => $this->planFile(json_encode($this->blogPlan(), JSON_THROW_ON_ERROR), 'json')]);
        $tester = $this->newApp(['dir' => $second, '--plan' => $first . '/' . AppPlan::FILE]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(Manifest::files(Manifest::read($first)), Manifest::files(Manifest::read($second)));
        self::assertSame(Manifest::read($first)['answers'], Manifest::read($second)['answers']);
        self::assertFileEquals($first . '/' . AppPlan::FILE, $second . '/' . AppPlan::FILE);
    }

    public function testTheSavedPlanIsNotInTheManifestAndNeverDrift(): void
    {
        $target = $this->makeTempDir() . '/demo';
        $this->newApp(['dir' => $target, '--raw' => true]);

        $files = Manifest::files(Manifest::read($target));
        self::assertArrayNotHasKey(AppPlan::FILE, $files);
        self::assertSame([], array_filter(array_keys($files), static fn(string $path): bool => str_starts_with($path, '.sourdough/')));

        // The app may grow its plan; the manifest check never calls that drift.
        file_put_contents($target . '/' . AppPlan::FILE, "pages:\n  - /later\n", FILE_APPEND);
        self::assertSame(['changed' => [], 'missing' => []], Manifest::check($target));

        $check = new CommandTester(new ManifestCommand());
        $check->execute(['dir' => $target, '--check' => true]);
        self::assertSame(0, $check->getStatusCode(), $check->getDisplay());
        self::assertStringNotContainsString('plan.yaml', $check->getDisplay());
    }

    public function testADryRunSavesNoPlan(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--raw' => true, '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileDoesNotExist($target . '/' . AppPlan::FILE);
    }

    public function testARefusedBakeSavesNoPlan(): void
    {
        $target = $this->makeTempDir() . '/demo';
        mkdir($target);
        file_put_contents($target . '/already-here.txt', "not empty\n");

        $tester = $this->newApp(['dir' => $target, '--raw' => true]);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileDoesNotExist($target . '/' . AppPlan::FILE);
    }

    public function testAYamlPlanThatFailsTheSchemaIsRefused(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--plan' => $this->planFile("permutation: raw\nname: demo-app\n", 'yaml')]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('not a valid plan', $tester->getDisplay());
        self::assertStringContainsString('did not match the plan schema', $tester->getDisplay());
    }

    public function testAYamlPlanThatDoesNotParseIsRefused(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--plan' => $this->planFile("permutation: [raw\n", 'yml')]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($target);
        self::assertStringContainsString('not valid YAML', $tester->getDisplay());
    }

    public function testAYamlListIsNotAPlan(): void
    {
        $target = $this->makeTempDir() . '/demo';

        $tester = $this->newApp(['dir' => $target, '--plan' => $this->planFile("- raw\n- demo-app\n", 'yaml')]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not a mapping', $tester->getDisplay());
    }

    /**
     * @return array{permutation: string, parts: list<string>, name: string, models: list<array{name: string, fields: list<array{name: string, type: string}>}>, pages: list<string>}
     */
    private function blogPlan(): array
    {
        return [
            'permutation' => 'raw',
            'parts' => ['sesame', 'helmet'],
            'name' => 'demo-app',
            'models' => [
                [
                    'name' => 'Post',
                    'fields' => [
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'body', 'type' => 'text'],
                    ],
                ],
            ],
            'pages' => ['/', '/posts'],
        ];
    }

    /**
     * Run `sourdough new` the way CI does: no Playbook or ADVISOR wiring,
     * no prompts, and the checked-in Laravel skeleton, so nothing here
     * touches the network.
     *
     * @param array<string, mixed> $input
     */
    private function newApp(array $input): CommandTester
    {
        $tester = new CommandTester(new NewCommand(null, new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')));
        $tester->execute($input + ['--no-playbook' => true, '--no-advisor' => true], ['interactive' => false]);

        return $tester;
    }

    private function planFile(string $content, string $extension): string
    {
        $path = $this->makeTempDir() . '/plan.' . $extension;
        file_put_contents($path, $content);

        return $path;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-saved-plan-' . bin2hex(random_bytes(8));
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
