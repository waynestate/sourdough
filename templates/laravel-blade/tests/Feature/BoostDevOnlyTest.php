<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Laravel Boost stays a dev tool
|--------------------------------------------------------------------------
|
| Boost's MCP server can query the database and run code, so it belongs on
| a laptop and never on a server (WELD W29). It lives in require-dev, and
| every release installs with composer install --no-dev, which leaves
| require-dev behind. What Boost regenerates stays out of git; what the
| team shares (.ai/guidelines and .ai/rules) is committed (WELD W5).
| A starter needs a boost to rise, but only in the proofing drawer.
*/

/**
 * @return array<string, mixed>
 */
function sourdoughBoostComposer(): array
{
    return json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Run the boost-update guard from composer.json the way Composer would,
 * with $dir as the app root.
 */
function sourdoughBoostGuard(string $dir): Process
{
    $command = (string) (sourdoughBoostComposer()['scripts']['boost-update'] ?? '');
    expect(preg_match('/^@php -r "(.+)"$/s', $command, $match))->toBe(1, 'composer boost-update must be one @php -r "..." line');

    $process = new Process([PHP_BINARY, '-r', $match[1]], $dir);
    $process->run();

    return $process;
}

it('keeps Boost in require-dev only', function () {
    $composer = sourdoughBoostComposer();

    expect($composer['require-dev'] ?? [])->toHaveKey('laravel/boost')
        ->and($composer['require'] ?? [])->not->toHaveKey('laravel/boost');
});

it('locks Boost as a dev package, so composer install --no-dev leaves it out', function () {
    if (! is_file(base_path('composer.lock'))) {
        $this->markTestSkipped('No composer.lock yet: run composer install first.');
    }

    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($lock['packages-dev'] ?? [], 'name'))->toContain('laravel/boost')
        ->and(array_column($lock['packages'] ?? [], 'name'))->not->toContain('laravel/boost');
});

it('refreshes Boost after composer update', function () {
    expect(sourdoughBoostComposer()['scripts']['post-update-cmd'] ?? [])->toContain('@boost-update');
});

it('skips the refresh where Boost is not set up', function () {
    $dir = sys_get_temp_dir().'/boost-guard-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    // An artisan that would fail loudly if the guard ever called it.
    File::put($dir.'/artisan', "<?php\nfwrite(STDERR, 'called');\nexit(9);\n");

    try {
        // A fresh clone: boost:install has not run, so there is no boost.json.
        $fresh = sourdoughBoostGuard($dir);
        expect($fresh->getExitCode())->toBe(0)
            ->and($fresh->getErrorOutput())->toBe('');

        // composer install --no-dev: boost.json is there, Boost is not.
        File::put($dir.'/boost.json', "{\"agents\": [\"claude_code\"]}\n");
        $noDev = sourdoughBoostGuard($dir);
        expect($noDev->getExitCode())->toBe(0)
            ->and($noDev->getErrorOutput())->toBe('');

        // boost:install found no agent: boost:update would only say "set me up".
        File::ensureDirectoryExists($dir.'/vendor/laravel/boost');
        File::put($dir.'/boost.json', "{\"agents\": []}\n");
        $noAgent = sourdoughBoostGuard($dir);
        expect($noAgent->getExitCode())->toBe(0)
            ->and($noAgent->getErrorOutput())->toBe('');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('runs boost:update and keeps its exit code where Boost is set up', function () {
    $dir = sys_get_temp_dir().'/boost-guard-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir.'/vendor/laravel/boost');
    File::put($dir.'/boost.json', "{\"agents\": [\"claude_code\"]}\n");
    File::put($dir.'/artisan', "<?php\necho implode(' ', array_slice(\$argv, 1));\nexit(3);\n");

    try {
        $process = sourdoughBoostGuard($dir);

        expect($process->getOutput())->toBe('boost:update --ansi')
            ->and($process->getExitCode())->toBe(3);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('ignores what Boost regenerates and keeps what the team shares', function () {
    $ignored = array_map('trim', file(base_path('.gitignore')) ?: []);

    expect($ignored)->toContain('/.mcp.json', '/boost.json', '/AGENTS.md', '/CLAUDE.md')
        ->and(base_path('.ai/guidelines/wayne-state.md'))->toBeFile()
        ->and(base_path('.ai/rules/index.md'))->toBeFile();
});
