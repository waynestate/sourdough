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
            'Wire ADVISOR into the app',
        ], $plan->steps);
    }

    public function testNoAdvisorDropsTheAdvisorStep(): void
    {
        $plan = Plan::forPermutation(Permutation::Raw, '/tmp/demo', false, false, false);

        self::assertSame([
            'Copy the raw template into /tmp/demo',
            'Replace {{app_name}} and {{app_slug}} in file names and contents',
        ], $plan->steps);
    }

    public function testLaravelBladePlanIsGeneratable(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', false, false);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo',
            'Wire ADVISOR into the app',
        ], $plan->steps);
    }

    public function testLaravelInertiaPlanIsGeneratable(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelInertia, '/tmp/demo', false, false);

        self::assertSame([
            'Create the Laravel + Inertia/Vue app in /tmp/demo',
            'Wire ADVISOR into the app',
        ], $plan->steps);
    }

    public function testLaravelPlanAddsSailStep(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', true, false);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo',
            'Wire ADVISOR into the app',
            'Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)',
        ], $plan->steps);
    }

    public function testLaravelPlanAddsDockerStep(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBlade, '/tmp/demo', false, true);

        self::assertSame([
            'Create the Laravel + Blade app in /tmp/demo',
            'Wire ADVISOR into the app',
            'Use Docker for the local runtime (Dockerfile, compose.yaml, MySQL 8.4)',
        ], $plan->steps);
    }

    public function testRawVuePlanLayersTheOverlayAndWiresAdvisor(): void
    {
        $plan = Plan::forPermutation(Permutation::RawVue, '/tmp/demo', false, false);

        self::assertSame([
            'Copy the raw template, then the raw-vue template over it, into /tmp/demo',
            'Replace {{app_name}} and {{app_slug}} in file names and contents',
            'Wire ADVISOR into the app',
        ], $plan->steps);
    }

    public function testLaravelBladeVuePlanIsGeneratable(): void
    {
        $plan = Plan::forPermutation(Permutation::LaravelBladeVue, '/tmp/demo', false, false);

        self::assertSame([
            'Create the Laravel + Blade + Vue islands app in /tmp/demo',
            'Wire ADVISOR into the app',
        ], $plan->steps);
    }

    public function testRawNextStepsServeWithComposer(): void
    {
        $plan = Plan::forPermutation(Permutation::Raw, 'demo', false, false);

        self::assertSame([
            'cd demo',
            'composer install',
            'php vendor/bin/advisor install --client=claude --write',
            'composer serve',
        ], $plan->nextSteps);
    }

    public function testRawVueNextStepsBuildBeforeServing(): void
    {
        $plan = Plan::forPermutation(Permutation::RawVue, 'demo', false, false, false);

        self::assertSame([
            'cd demo',
            'composer install',
            'npm install',
            'npm run build',
            'composer serve',
            '(npm run dev in a second terminal for hot reload)',
        ], $plan->nextSteps);
    }

    public function testLaravelBenchNextStepsMigrateAndServe(): void
    {
        self::assertSame([
            'cd demo',
            'composer install',
            'php vendor/bin/advisor install --client=claude --write',
            'cp .env.example .env',
            'php artisan key:generate',
            'php artisan migrate',
            'php artisan serve',
        ], Plan::forPermutation(Permutation::LaravelBlade, 'demo', false, false)->nextSteps);

        self::assertSame([
            'cd demo',
            'composer install',
            'cp .env.example .env',
            'php artisan key:generate',
            'php artisan migrate',
            'npm install',
            'npm run build',
            'php artisan serve',
        ], Plan::forPermutation(Permutation::LaravelInertia, 'demo', false, false, false)->nextSteps);
    }

    public function testSailNextStepsRunThroughSailAndNameWsl(): void
    {
        $blade = Plan::forPermutation(Permutation::LaravelBlade, 'demo', true, false, false)->nextSteps;
        self::assertSame([
            'cd demo',
            'composer install',
            'cp .env.example .env',
            './vendor/bin/sail up -d',
            './vendor/bin/sail artisan key:generate',
            './vendor/bin/sail artisan migrate',
            '(Windows: run sail from WSL2, or use --docker)',
        ], $blade);

        $islands = Plan::forPermutation(Permutation::LaravelBladeVue, 'demo', true, false, false)->nextSteps;
        self::assertSame(['./vendor/bin/sail npm install', './vendor/bin/sail npm run dev', '(Windows: run sail from WSL2, or use --docker)'], array_slice($islands, -3));
    }

    public function testDockerNextStepsUseComposeAndNameViteForFrontEnds(): void
    {
        $blade = Plan::forPermutation(Permutation::LaravelBlade, 'demo', false, true, false)->nextSteps;
        self::assertSame([
            'cd demo',
            'composer install',
            'cp .env.example .env',
            'composer dev-up',
            'docker compose exec app php artisan key:generate',
            'docker compose exec app php artisan migrate',
        ], $blade);

        $inertia = Plan::forPermutation(Permutation::LaravelInertia, 'demo', false, true, false)->nextSteps;
        self::assertSame('(Vite runs as its own service on port 5173)', end($inertia));
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
