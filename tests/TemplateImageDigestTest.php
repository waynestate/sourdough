<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every image the baked pipelines pull is pinned by digest: a tag can be
 * re-pushed, and only the digest keeps every app's pipeline on the same bytes
 * (and the Dependency Proxy on one cached copy). The Node lines already carried
 * theirs; this holds the composer lines and any image a new job adds. A starter
 * that is sealed well rises without a fuss.
 */
final class TemplateImageDigestTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    #[DataProvider('pipelines')]
    public function testEveryImageLineCarriesADigest(string $file): void
    {
        $ci = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $shape = basename(dirname($file));

        preg_match_all('/^[ \t]*image:[ \t]*(.*)$/m', $ci, $m);
        self::assertNotEmpty($m[1], "$shape: no image lines found");
        foreach ($m[1] as $image) {
            $image = trim($image, '" ');
            if ('' === $image) {
                self::fail("$shape: an image line names no image");
            }
            // A bare ${...} variable alone is not an image name; it is skipped.
            if (1 === preg_match('/^\$\{[^}]+\}$/', $image)) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/@sha256:[0-9a-f]{64}(?![0-9a-f])/',
                $image,
                "$shape: $image is not pinned by digest",
            );
        }
    }
}
