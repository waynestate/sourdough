<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Every queued job has a retry limit and a timeout
|--------------------------------------------------------------------------
|
| A queued job with no limits retries as often as the worker lets it and
| can hold a worker until the platform kills the container. So every
| class under app/Jobs that implements ShouldQueue declares Laravel's
| #[Tries] and #[Timeout] attributes, on the class, a parent or a trait
| (the older $tries and $timeout properties count too), and its timeout
| stays below the queue connection's retry_after, or a second worker
| picks the job up while the first is still running it (WELD W18, W20).
| php artisan make:job writes both attributes already (stubs/). No jobs
| yet? Then this passes, like a starter waiting for its first feed.
*/

/**
 * The classes declared under $dir, by PSR-4 from $namespace. A file that
 * autoloading cannot find is loaded directly, so a fixture folder works too.
 *
 * @return list<class-string>
 */
function sourdoughJobClasses(string $dir, string $namespace): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($dir) + 1, -4);
        $class = $namespace.'\\'.str_replace(['/', '\\'], '\\', $relative);

        if (! class_exists($class)) {
            require_once $file->getPathname();
        }

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Whether $class, a parent or one of their traits carries $attribute, or
 * the class gives $property a value of its own (Laravel's older form).
 *
 * @param  class-string  $class
 * @param  class-string  $attribute
 */
function sourdoughJobDeclares(string $class, string $attribute, string $property): bool
{
    for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
        if ($reflection->getAttributes($attribute) !== []) {
            return true;
        }

        foreach ($reflection->getTraits() as $trait) {
            if ($trait->getAttributes($attribute) !== []) {
                return true;
            }
        }
    }

    $defaults = (new ReflectionClass($class))->getDefaultProperties();

    return ($defaults[$property] ?? null) !== null;
}

/**
 * The timeout a job declares, in seconds, or null when it declares none.
 *
 * @param  class-string  $class
 */
function sourdoughJobTimeout(string $class): ?int
{
    for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
        $attributes = $reflection->getAttributes(Timeout::class);

        foreach ($reflection->getTraits() as $trait) {
            $attributes = [...$attributes, ...$trait->getAttributes(Timeout::class)];
        }

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->timeout;
        }
    }

    $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

    return $timeout === null ? null : (int) $timeout;
}

/**
 * Every queued job that is missing a limit, with what it is missing.
 *
 * @param  list<class-string>  $classes
 * @return array<class-string, list<string>>
 */
function sourdoughJobsMissingLimits(array $classes): array
{
    $missing = [];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        $lacks = [];

        if (! sourdoughJobDeclares($class, Tries::class, 'tries') && ! $reflection->hasMethod('tries')) {
            $lacks[] = '#[Tries]';
        }

        if (! sourdoughJobDeclares($class, Timeout::class, 'timeout')) {
            $lacks[] = '#[Timeout]';
        }

        if ($lacks !== []) {
            $missing[$class] = $lacks;
        }
    }

    return $missing;
}

/**
 * The retry_after a job's timeout must stay below: the default queue
 * connection's, or, when it has none (sync), the lowest of any connection.
 */
function sourdoughRetryAfter(): ?int
{
    $retryAfter = config('queue.connections.'.config('queue.default').'.retry_after');

    if ($retryAfter !== null) {
        return (int) $retryAfter;
    }

    $all = array_filter(array_map(
        fn (array $connection): ?int => isset($connection['retry_after']) ? (int) $connection['retry_after'] : null,
        config('queue.connections'),
    ), fn (?int $value): bool => $value !== null);

    return $all === [] ? null : min($all);
}

/**
 * Write a small fixture folder of job classes and return their names.
 *
 * @return list<class-string>
 */
function sourdoughJobFixtures(): array
{
    $suffix = Str::random(8);
    $namespace = 'SourdoughJobFixture'.$suffix;
    $dir = storage_path('framework/testing/job-fixtures-'.$suffix);
    mkdir($dir, 0755, true);

    $header = "<?php\n\nnamespace {$namespace};\n\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nuse Illuminate\\Queue\\Attributes\\Timeout;\nuse Illuminate\\Queue\\Attributes\\Tries;\n\n";

    file_put_contents($dir.'/Bare.php', $header."class Bare implements ShouldQueue\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/Limited.php', $header."#[Tries(3)]\n#[Timeout(45)]\nclass Limited implements ShouldQueue\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/TriesOnly.php', $header."#[Tries(3)]\nclass TriesOnly implements ShouldQueue\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/OldStyle.php', $header."class OldStyle implements ShouldQueue\n{\n    public \$tries = 3;\n\n    public \$timeout = 45;\n\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/RunsNow.php', $header."class RunsNow\n{\n    public function handle(): void {}\n}\n");
    file_put_contents($dir.'/TooSlow.php', $header."#[Tries(3)]\n#[Timeout(600)]\nclass TooSlow implements ShouldQueue\n{\n    public function handle(): void {}\n}\n");

    $classes = sourdoughJobClasses($dir, $namespace);

    foreach (glob($dir.'/*.php') as $file) {
        unlink($file);
    }
    rmdir($dir);

    return $classes;
}

it('gives every queued job under app/Jobs a #[Tries] and a #[Timeout]', function () {
    $missing = sourdoughJobsMissingLimits(sourdoughJobClasses(app_path('Jobs'), 'App\\Jobs'));

    $lines = array_map(
        fn (string $class, array $lacks): string => $class.' needs '.implode(' and ', $lacks),
        array_keys($missing),
        $missing,
    );

    expect($missing)->toBe([], implode('; ', $lines));
});

it('keeps every job timeout below the queue connection retry_after', function () {
    $retryAfter = sourdoughRetryAfter();

    expect($retryAfter)->not->toBeNull();

    foreach (sourdoughJobClasses(app_path('Jobs'), 'App\\Jobs') as $class) {
        $timeout = sourdoughJobTimeout($class);

        if ($timeout !== null) {
            expect($timeout)->toBeLessThan($retryAfter, $class.' times out after retry_after');
        }
    }
});

it('catches a queued job without its limits', function () {
    $classes = sourdoughJobFixtures();

    expect($classes)->toHaveCount(6);

    $missing = array_combine(
        array_map(fn (string $class): string => class_basename($class), array_keys(sourdoughJobsMissingLimits($classes))),
        array_values(sourdoughJobsMissingLimits($classes)),
    );

    // Bare has neither and TriesOnly lacks a timeout; Limited and OldStyle
    // pass, TooSlow passes this test (the next one catches it), and RunsNow
    // is not queued, so the limits would do nothing.
    expect($missing)->toBe([
        'Bare' => ['#[Tries]', '#[Timeout]'],
        'TriesOnly' => ['#[Timeout]'],
    ]);
});

it('catches a job timeout at or above retry_after', function () {
    $timeouts = [];

    foreach (sourdoughJobFixtures() as $class) {
        $timeouts[class_basename($class)] = sourdoughJobTimeout($class);
    }

    expect($timeouts['Limited'])->toBe(45)
        ->and($timeouts['OldStyle'])->toBe(45)
        ->and($timeouts['Bare'])->toBeNull()
        ->and($timeouts['TooSlow'])->toBe(600)
        ->and($timeouts['TooSlow'])->toBeGreaterThanOrEqual(sourdoughRetryAfter())
        ->and($timeouts['Limited'])->toBeLessThan(sourdoughRetryAfter());
});

it('writes both limits into the jobs make:job creates', function () {
    foreach (['job.queued.stub', 'job.batched.queued.stub'] as $stub) {
        $source = (string) file_get_contents(base_path('stubs/'.$stub));

        expect($source)->toContain('#[Tries(')
            ->and($source)->toContain('#[Timeout(')
            ->and($source)->toContain('use Illuminate\Queue\Attributes\Tries;')
            ->and($source)->toContain('use Illuminate\Queue\Attributes\Timeout;');

        preg_match('/#\[Timeout\((\d+)\)\]/', $source, $matches);

        expect((int) ($matches[1] ?? 0))->toBeGreaterThan(0)
            ->and((int) ($matches[1] ?? 0))->toBeLessThan(sourdoughRetryAfter());
    }
});
