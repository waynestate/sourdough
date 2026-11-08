<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\ShapeSupport;

/**
 * BEST_PRACTICES 3.7: every shape has an owner role, a support status and
 * a sunset path, in resources/shapes.json, and the rules in
 * docs/guides/support.md hold for it. `sourdough new --dry-run --json`
 * reports the same facts, with the framework end date from CREED.
 */
final class ShapeSupportTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        putenv(PhpFloor::ENV_POLICY);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testTheShippedCatalogIsSound(): void
    {
        self::assertSame([], ShapeSupport::load()->problems());
    }

    public function testEveryShapeIsOwnedAndSupported(): void
    {
        $support = ShapeSupport::load();
        foreach (Permutation::cases() as $permutation) {
            $entry = $support->entry($permutation->value);
            self::assertNotNull($entry, $permutation->value);
            self::assertSame('Sourdough maintainers', $entry['owner'], $permutation->value . ': owners are roles, never people');
            self::assertSame(ShapeSupport::STATUS_SUPPORTED, $entry['status'], $permutation->value);
            self::assertNull($support->notice($permutation->value));
        }
        self::assertStringStartsWith('https://sourdough.apps.wayne.edu/docs/', $support->policyUrl);
    }

    public function testTheSunsetRulesAreEnforced(): void
    {
        $shapes = $this->soundShapes();
        $shapes['raw-vue'] = ['owner' => '', 'status' => 'deprecated', 'deprecated_in' => '0.4.0', 'removed_in' => null, 'replacement' => 'raw'];
        $shapes['laravel-blade'] = ['owner' => 'Sourdough maintainers', 'status' => 'deprecated', 'deprecated_in' => '0.4.0', 'removed_in' => '0.4.0', 'replacement' => 'laravel-inertia'];
        $shapes['laravel-blade-vue'] = ['owner' => 'Sourdough maintainers', 'status' => 'supported', 'deprecated_in' => null, 'removed_in' => null, 'replacement' => 'laravel-inertia'];
        $shapes['laravel-inertia'] = ['owner' => 'Sourdough maintainers', 'status' => 'removed', 'deprecated_in' => '0.3.0', 'removed_in' => '0.4.0', 'replacement' => 'raw'];
        $shapes['laravel-livewire'] = ['owner' => 'Sourdough maintainers', 'status' => 'deprecated', 'deprecated_in' => '0.3.0', 'removed_in' => '0.4.0', 'replacement' => 'nope'];
        unset($shapes['raw']);

        $problems = ShapeSupport::load($this->writeCatalog($shapes))->problems();

        self::assertContains('raw: Sourdough bakes it, but the catalog does not list it', $problems);
        self::assertContains('raw-vue: no owner role', $problems);
        self::assertContains('raw-vue: a deprecated shape needs deprecated_in, removed_in and replacement', $problems);
        self::assertContains('laravel-blade: removed_in (0.4.0) must be a later release than deprecated_in (0.4.0)', $problems);
        self::assertContains('laravel-blade-vue: a supported shape carries no deprecated_in, removed_in or replacement', $problems);
        self::assertContains('laravel-inertia: marked removed, but Sourdough still bakes it', $problems);
        self::assertContains('laravel-livewire: Sourdough does not bake it, so it must be marked removed', $problems);
        self::assertContains('laravel-livewire: the replacement "nope" is not a shape Sourdough bakes', $problems);
        self::assertCount(8, $problems);
    }

    public function testADeprecatedOrRetiredShapeSaysWhereToGo(): void
    {
        $shapes = $this->soundShapes();
        $shapes['laravel-blade-vue'] = ['owner' => 'Sourdough maintainers', 'status' => 'deprecated', 'deprecated_in' => '0.4.0', 'removed_in' => '0.5.0', 'replacement' => 'laravel-inertia'];
        $shapes['laravel-livewire'] = ['owner' => 'Sourdough maintainers', 'status' => 'removed', 'deprecated_in' => '0.2.0', 'removed_in' => '0.3.0', 'replacement' => 'laravel-inertia'];
        $support = ShapeSupport::load($this->writeCatalog($shapes));

        self::assertSame([], $support->problems());
        self::assertSame(
            'The laravel-blade-vue shape is deprecated since Sourdough 0.4.0 and is removed in 0.5.0; move to laravel-inertia. See https://sourdough.apps.wayne.edu/docs/guides/support.html',
            $support->notice('laravel-blade-vue'),
        );
        self::assertSame(
            'The laravel-livewire shape was retired in Sourdough 0.3.0; move to laravel-inertia. See https://sourdough.apps.wayne.edu/docs/guides/support.html',
            $support->notice('laravel-livewire'),
        );
    }

    public function testTheDryRunReportsTheFloorAndTheSupportFacts(): void
    {
        foreach (Permutation::cases() as $permutation) {
            $flags = match ($permutation) {
                Permutation::Raw => ['--raw' => true],
                Permutation::RawVue => ['--raw' => true, '--vue' => true],
                Permutation::LaravelBlade => ['--laravel' => true],
                Permutation::LaravelBladeVue => ['--laravel' => true, '--vue' => true],
                Permutation::LaravelInertia => ['--inertia' => true],
            };
            $tester = new CommandTester(new NewCommand());
            $tester->execute(['dir' => sys_get_temp_dir() . '/sourdough-shape-support-' . bin2hex(random_bytes(4)), '--dry-run' => true, '--json' => true, '--no-playbook' => true] + $flags);

            $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('^8.3', $data['php']['constraint'], $permutation->value);
            self::assertSame(['8.3', '8.4', '8.5'], $data['php']['versions'], $permutation->value);
            self::assertSame(PhpFloor::SOURCE_POLICY, $data['php']['source'], $permutation->value);
            self::assertSame($permutation->value, $data['support']['shape']);
            self::assertSame('Sourdough maintainers', $data['support']['owner']);
            self::assertSame('supported', $data['support']['status']);

            $laravel = Permutation::Raw !== $permutation && Permutation::RawVue !== $permutation;
            self::assertSame($laravel ? 'laravel' : 'php', $data['support']['runs_on']['component'], $permutation->value);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['support']['runs_on']['security_until'], $permutation->value);
        }
    }

    public function testThePlanSaysWhereTheFloorCameFrom(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute(['dir' => sys_get_temp_dir() . '/sourdough-shape-support-text', '--dry-run' => true, '--inertia' => true, '--no-playbook' => true]);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/PHP: +PHP \^8\.3, tested on 8\.3, 8\.4 and 8\.5 \(from waynestate\/creed, refreshed \d{4}-\d{2}-\d{2}\)/', $display);
        self::assertMatchesRegularExpression('/Support: +supported, owned by Sourdough maintainers; Laravel \d+ gets security fixes until \d{4}-\d{2}-\d{2}/', $display);
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function soundShapes(): array
    {
        $shapes = [];
        foreach (Permutation::cases() as $permutation) {
            $shapes[$permutation->value] = ['owner' => 'Sourdough maintainers', 'status' => 'supported', 'deprecated_in' => null, 'removed_in' => null, 'replacement' => null];
        }

        return $shapes;
    }

    /**
     * @param array<string, array<string, string|null>> $shapes
     */
    private function writeCatalog(array $shapes): string
    {
        $path = sys_get_temp_dir() . '/sourdough-shapes-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode([
            'schema' => 1,
            'policy' => 'https://sourdough.apps.wayne.edu/docs/guides/support.html',
            'shapes' => $shapes,
        ], JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }
}
