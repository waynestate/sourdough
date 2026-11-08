<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every app shape's pipeline keeps its dependency downloads cached: Docker
 * Hub images through ${IMAGE_PREFIX} (the jobs name it in their images, the
 * Preflight components take it as image_prefix; the group's Dependency Proxy when the
 * GitLab has one, Docker Hub when it does not), and every Composer or npm
 * install on a cache keyed by the lock file. A new job that pulls or
 * installs without them fails here, before it can get a runner rate
 * limited. A starter that is fed well rises without a fuss.
 */
final class TemplateCiCacheTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    public function testEveryShapeShipsAPipeline(): void
    {
        $shapes = array_keys(iterator_to_array(self::pipelines()));
        sort($shapes);

        self::assertSame(['laravel-blade', 'laravel-inertia', 'raw', 'raw-vue'], $shapes);
    }

    #[DataProvider('pipelines')]
    public function testImageRegistryFallsBackToDockerHub(string $file): void
    {
        $ci = self::read($file);

        self::assertMatchesRegularExpression('/^variables:\n(?:  .*\n)*  IMAGE_PREFIX: docker\.io\n/m', $ci);
        self::assertStringContainsString(
            "workflow:\n  rules:\n    - if: \$CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX\n      variables:\n"
            . "        IMAGE_PREFIX: \$CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX\n    - when: always\n",
            $ci,
        );
    }

    /**
     * The app's own jobs name ${IMAGE_PREFIX} in their images. The Preflight
     * components' image inputs (the include block) are plain names, because
     * from Preflight 0.3.0 the components add the prefix themselves through
     * image_prefix; tests/PreflightPinTest.php covers those.
     */
    #[DataProvider('pipelines')]
    public function testEveryJobImageComesThroughThePrefix(string $file): void
    {
        $ci = self::read($file);
        $jobs = (string) preg_replace('/^include:\n(?:  [^\n]*\n|\n)*/m', '', $ci, 1);

        self::assertSame(0, preg_match('/^[ \t]+image:[ \t]*$/m', $ci), 'image: blocks are not used here; write the image inline');
        preg_match_all('/^[ \t]+image:[ \t]*(\S.*)$/m', $jobs, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $image) {
            self::assertStringStartsWith('${IMAGE_PREFIX}/', trim($image, '"'), basename(dirname($file)) . ': ' . $image);
        }
    }

    #[DataProvider('pipelines')]
    public function testEveryInstallIsCachedOnItsLockFile(string $file): void
    {
        $ci = self::read($file);
        $jobs = self::jobs($ci);
        $shape = basename(dirname($file));
        $composer = false;
        $npm = false;

        foreach ($jobs as $name => $body) {
            if (1 === preg_match('/^    - composer (install|update|require|create-project)\b/m', $body)) {
                $composer = true;
                self::assertStringContainsString("  extends: .composer-cache\n", $body, "$shape: $name installs with Composer");
            }
            if (1 === preg_match('/^    - npm (ci|install)\b/m', $body)) {
                $npm = true;
                self::assertStringContainsString("  extends: .npm-cache\n", $body, "$shape: $name installs with npm");
            }
        }

        self::assertTrue($composer, "$shape: no job installs with Composer");
        self::assertStringContainsString('COMPOSER_CACHE_DIR: "$CI_PROJECT_DIR/.cache/composer"', $ci);
        self::assertStringContainsString(
            ".composer-cache:\n  cache:\n    key: composer\n    paths: [.cache/composer/]\n    when: always\n",
            $ci,
        );
        self::assertStringNotContainsString("    key:\n      files:", $ci, "$shape: the cache key is fixed, not a lock-file hash");
        if ($npm) {
            self::assertStringContainsString('npm_config_cache: "$CI_PROJECT_DIR/.cache/npm"', $ci);
            self::assertStringContainsString(
                ".npm-cache:\n  cache:\n    key: npm\n    paths: [.cache/npm/]\n    when: always\n",
                $ci,
            );
        }
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /**
     * The pipeline's jobs, name => body: every top-level block except the
     * hidden templates (.name) and the GitLab keywords.
     *
     * @return array<string, string>
     */
    private static function jobs(string $ci): array
    {
        preg_match_all('/^([A-Za-z0-9_-]+):\n((?:[ ].*\n|\n)*)/m', $ci, $blocks, PREG_SET_ORDER);
        $jobs = [];
        foreach ($blocks as [, $name, $body]) {
            if (!in_array($name, ['include', 'workflow', 'variables', 'stages', 'default'], true)) {
                $jobs[$name] = $body;
            }
        }

        return $jobs;
    }
}
