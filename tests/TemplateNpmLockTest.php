<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every template that ships a package.json ships the package-lock.json that
 * goes with it (BEST_PRACTICES 4.10), so a freshly baked app passes the
 * pipeline's `npm ci` on its first push. `npm ci` refuses a lock whose root
 * entry disagrees with package.json, so this test holds the two together
 * without the network: edit a template's package.json and forget the lock,
 * and it fails here before it fails in every new app's pipeline. The smoke
 * jobs then run the real `npm ci` against the baked lock.
 *
 * MAINTAINING.md ("Refreshing a template's lock file") has the recipe for
 * writing a lock again after a package.json edit.
 *
 * A starter without its lock is a jar without a lid: it still works, right
 * up until something gets in.
 */
final class TemplateNpmLockTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function packages(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/package.json') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    public function testEveryFrontEndTemplateIsCovered(): void
    {
        $shapes = array_keys(iterator_to_array(self::packages()));
        sort($shapes);
        self::assertSame(['laravel-blade-vue', 'laravel-inertia', 'raw-vue'], $shapes);
    }

    #[DataProvider('packages')]
    public function testTheLockMatchesThePackage(string $packageFile): void
    {
        $shape = basename(dirname($packageFile));
        $lockFile = dirname($packageFile) . '/package-lock.json';
        self::assertFileExists($lockFile, "$shape ships package-lock.json next to package.json");

        self::assertLockMatchesPackage(self::json($packageFile), self::json($lockFile), $shape);
        self::assertSame('{{app_slug}}', self::json($lockFile)['name'], "$shape: the lock carries the placeholder the bake replaces");
    }

    #[DataProvider('packages')]
    public function testTheLockResolvesFromThePublicRegistryWithIntegrity(string $packageFile): void
    {
        $shape = basename(dirname($packageFile));
        $lock = self::json(dirname($packageFile) . '/package-lock.json');

        self::assertSame(3, $lock['lockfileVersion'] ?? null, "$shape: lockfileVersion 3 (npm 9 and later)");
        $packages = $lock['packages'] ?? null;
        self::assertIsArray($packages, "$shape: the lock has a packages map");
        self::assertGreaterThan(1, count($packages), "$shape: the lock lists the installed packages, not only the root");

        foreach ($packages as $path => $entry) {
            if ('' === $path || !is_array($entry) || true === ($entry['link'] ?? false)) {
                continue;
            }
            // A lock written on a desk behind a mirror would send every new
            // app's pipeline to that mirror: only the public registry, over HTTPS.
            self::assertStringStartsWith('https://registry.npmjs.org/', (string) ($entry['resolved'] ?? ''), "$shape: $path resolves from the public registry");
            self::assertMatchesRegularExpression('/^sha512-[A-Za-z0-9+\/]+=*$/', (string) ($entry['integrity'] ?? ''), "$shape: $path carries a sha512 integrity hash");
        }
    }

    /**
     * Assert a baked app's lock file. Public so the per-shape generator
     * tests hold the baked file to it: the lock arrived, the app's slug
     * replaced the placeholder in both name fields, it still matches the
     * baked package.json, and the manifest seeds it (npm owns it after the
     * bake, so a feed never merges one).
     */
    public static function assertBakedLockFile(string $target, string $slug): void
    {
        self::assertFileExists($target . '/package-lock.json', 'the bake ships package-lock.json');

        $lock = self::json($target . '/package-lock.json');
        self::assertSame($slug, $lock['name'] ?? null, "the lock's name is the app's slug");
        self::assertSame($slug, $lock['packages']['']['name'] ?? null, "the lock's root entry is named for the app");
        self::assertLockMatchesPackage(self::json($target . '/package.json'), $lock, 'the baked app');

        $manifest = self::json($target . '/.sourdough/manifest.json');
        self::assertSame('seeded', $manifest['files']['package-lock.json']['class'] ?? null, 'the manifest seeds the lock');
    }

    /**
     * The root entry of the lock names exactly what package.json names, the
     * check `npm ci` makes before it installs anything.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $lock
     */
    private static function assertLockMatchesPackage(array $package, array $lock, string $label): void
    {
        self::assertSame($package['name'] ?? null, $lock['name'] ?? null, "$label: the lock and package.json carry the same name");
        $root = $lock['packages'][''] ?? null;
        self::assertIsArray($root, "$label: the lock has a root entry");
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'engines'] as $key) {
            self::assertSame($package[$key] ?? null, $root[$key] ?? null, "$label: the lock's root $key match package.json (run npm install and commit the lock)");
        }
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        self::assertFileExists($file);
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, "$file is a JSON object");

        return $decoded;
    }
}
