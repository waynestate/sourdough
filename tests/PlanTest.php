<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\Plan;

final class PlanTest extends TestCase
{
    public function testRawPlanHasCopyAndReplaceSteps(): void
    {
        $plan = Plan::forPermutation(Permutation::Raw, '/tmp/demo', false, false);

        self::assertSame(Permutation::Raw, $plan->permutation);
        self::assertSame('/tmp/demo', $plan->dir);
        self::assertSame([
            'Copy the raw template into /tmp/demo',
            'Replace {{app_name}} and {{app_slug}} in file names and contents',
        ], $plan->steps);
    }

    public function testNonRawPlanSaysNotAvailableInThisRelease(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', false, false);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo (not available in this release)',
        ], $plan->steps);
    }

    public function testLaravelPlanAddsSailStep(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', true, false);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo (not available in this release)',
            'Use Sail for the local runtime',
        ], $plan->steps);
    }

    public function testLaravelPlanAddsDockerStep(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', false, true);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo (not available in this release)',
            'Use Docker for the local runtime',
        ], $plan->steps);
    }

    public function testRawWithSailThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sail and --docker are for the Laravel paths');

        Plan::forPermutation(Permutation::Raw, '/tmp/demo', true, false);
    }

    public function testRawWithDockerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sail and --docker are for the Laravel paths');

        Plan::forPermutation(Permutation::Raw, '/tmp/demo', false, true);
    }

    public function testRawVueWithSailThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sail and --docker are for the Laravel paths');

        Plan::forPermutation(Permutation::RawVue, '/tmp/demo', true, false);
    }

    public function testRawVueWithDockerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sail and --docker are for the Laravel paths');

        Plan::forPermutation(Permutation::RawVue, '/tmp/demo', false, true);
    }
}
