<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The proof harness (scripts/proof.sh and scripts/proof.ps1) needs Docker and
 * half an hour, so the suite cannot run it. What it can do is keep the two
 * twins, the per-shape gate and the CI job agreeing with each other and with
 * docs/reference/proof.md, so a run never goes missing from one of them. Two
 * loaves from one dough should at least have the same number of slices.
 */
final class ProofHarnessTest extends TestCase
{
    private const RUNS = [
        'raw', 'raw-vue', 'blade', 'blade-vue', 'inertia',
        'blade+sail', 'blade+docker', 'blade-vue+sail', 'blade-vue+docker', 'inertia+sail', 'inertia+docker',
    ];

    public function testTheShellHarnessListsEveryRun(): void
    {
        $sh = self::read('scripts/proof.sh');

        self::assertSame(1, preg_match("/^all_runs='([^']+)'/m", $sh, $m));
        self::assertSame(self::RUNS, explode(' ', $m[1]));
    }

    public function testThePowerShellHarnessListsTheSameRuns(): void
    {
        $ps1 = self::read('scripts/proof.ps1');

        self::assertSame(1, preg_match('/\$allRuns = @\((.*?)\)/s', $ps1, $m));
        self::assertSame(count(self::RUNS), preg_match_all("/'([^']+)'/", $m[1], $runs));
        self::assertSame(self::RUNS, $runs[1]);
    }

    public function testEveryRunHasARowInTheLastRunTable(): void
    {
        $proof = self::read('docs/reference/proof.md');

        foreach (self::RUNS as $run) {
            $label = str_replace('+', ' --', $run);
            self::assertStringContainsString('| `sh scripts/smoke-shape.sh ' . $label . '` |', $proof, $run);
        }
    }

    public function testTheGateChecksTheManifestPROCTORAndTheFrontPage(): void
    {
        $smoke = self::read('scripts/smoke-shape.sh');

        self::assertStringContainsString('manifest --check', $smoke);
        self::assertStringContainsString('step "composer proctor" composer proctor', $smoke);
        self::assertStringContainsString('SMOKE_FEED_FROM', $smoke);
        foreach (['raw', 'raw-vue', 'blade', 'blade-vue', 'inertia'] as $shape) {
            self::assertMatchesRegularExpression('/check_http "' . preg_quote($shape, '/') . ' GET \/ answers 200/', $smoke, $shape);
        }
    }

    public function testTheCiJobNeverRunsOnAPush(): void
    {
        $ci = self::read('.gitlab-ci.yml');

        self::assertSame(1, preg_match('/^proof:\n((?:[ ].*\n|\n)+)/m', $ci, $m));
        $job = $m[1];
        self::assertStringContainsString('sh scripts/proof.sh --here', $job);
        self::assertStringContainsString('$CI_PIPELINE_SOURCE == "schedule" && $SOURDOUGH_PROOF == "1"', $job);
        self::assertStringContainsString('- when: manual', $job);
        // Every rule either needs the schedule, says never, or waits for a hand.
        self::assertSame(1, substr_count($job, '- if: $CI_PIPELINE_SOURCE == "schedule" &&'));
        self::assertStringContainsString("when: never", $job);
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../' . $path);
        self::assertIsString($contents, $path);

        return str_replace("\r\n", "\n", $contents);
    }
}
