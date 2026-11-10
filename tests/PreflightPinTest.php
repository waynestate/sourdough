<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One Preflight release everywhere: every shape's pipeline pins the same
 * release commit (with the tag in a trailing comment, since PROCTOR's
 * preflight-pinned check wants a full SHA or a release tag), and Sourdough's
 * own GitLab pipeline names the same release. From Preflight
 * 0.3.0 each Docker Hub component adds the Dependency Proxy prefix itself
 * (image_prefix), so an image input that carries a prefix too pulls from a
 * path that does not exist. The shapes hand the components their own
 * ${IMAGE_PREFIX}, so one project variable still sends every job, component
 * or not, to Docker Hub. A starter fed from two jars never rises evenly.
 */
final class PreflightPinTest extends TestCase
{
    private const TAG = '0.6.0';

    private const SHA = '30dd7094089931015ec8754fb321e17659749f05';

    /** Preflight 0.3.0's components that pull from Docker Hub and take image_prefix. */
    private const DOCKER_HUB_COMPONENTS = [
        'proctor', 'php-test', 'composer-audit', 'sbom', 'pint', 'phpstan', 'eslint',
        'secret-detection', 'package-publish', 'renovate', 'squire-kit', 'mysql56-migrate',
    ];

    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    public function testEveryShapePinsTheSameReleaseCommit(): void
    {
        $versions = [];
        foreach (self::pipelines() as $shape => [$file]) {
            $includes = self::includes(self::read($file));
            self::assertNotEmpty($includes, "$shape includes Preflight components");
            foreach ($includes as $include) {
                self::assertSame(self::SHA, $include['ref'], "$shape: {$include['name']} pins the release commit");
                self::assertSame(self::TAG, $include['comment'], "$shape: {$include['name']} names the tag in a trailing comment");
                $versions[$include['comment']] = true;
            }
        }

        self::assertSame([self::TAG], array_keys($versions), 'one Preflight version across every shape');
    }

    public function testSourdoughsOwnPipelineNamesTheSameRelease(): void
    {
        $includes = self::includes(self::read(dirname(__DIR__) . '/.gitlab-ci.yml'));
        self::assertNotEmpty($includes, 'Sourdough includes Preflight components');
        foreach ($includes as $include) {
            self::assertSame(self::TAG, $include['ref'], "{$include['name']} is pinned to the release tag");
        }
    }

    public function testGithubWorkflowPinsTheSameReleaseCommit(): void
    {
        $workflow = self::read(dirname(__DIR__) . '/.github/workflows/ci.yml');
        self::assertStringContainsString('php-package.yml@' . self::SHA . ' # ' . self::TAG, $workflow);
        self::assertStringNotContainsString('79029cc', $workflow);
    }

    /**
     * The shapes pass no image inputs at all: the components' defaults are
     * pinned by digest inside the pinned release, so a copy here would only
     * drift from them. An app owner may add one; it has to be plain.
     */
    #[DataProvider('pipelines')]
    public function testNoComponentImageInputCarriesAPrefix(string $file): void
    {
        $images = $this->assertPlainImageInputs(self::read($file), basename(dirname($file)));
        self::assertSame([], $images, basename(dirname($file)) . ': the shapes keep the components\' default images');
    }

    public function testSourdoughsOwnComponentImageInputsArePlain(): void
    {
        $images = $this->assertPlainImageInputs(self::read(dirname(__DIR__) . '/.gitlab-ci.yml'), 'sourdough');
        self::assertNotEmpty($images, 'Sourdough names its component images');
    }

    #[DataProvider('pipelines')]
    public function testDockerHubComponentsTakeTheShapesImagePrefix(string $file): void
    {
        $shape = basename(dirname($file));
        foreach (self::includes(self::read($file)) as $include) {
            if (!in_array($include['name'], self::DOCKER_HUB_COMPONENTS, true)) {
                continue;
            }
            self::assertStringContainsString(
                "\n      image_prefix: \"\${IMAGE_PREFIX}\"\n",
                $include['body'],
                "$shape: {$include['name']} follows the shape's IMAGE_PREFIX",
            );
        }
    }

    /** @return list<string> the image inputs in the include block */
    private function assertPlainImageInputs(string $ci, string $where): array
    {
        $found = preg_match('/^include:\n(?:  [^\n]*\n|\n)*/m', $ci, $m);
        self::assertSame(1, $found, "$where: the pipeline has an include block");
        self::assertStringNotContainsString('CI_DEPENDENCY_PROXY', $m[0], "$where: the components add the proxy themselves");

        preg_match_all('/^      image:[ \t]*(\S.*)$/m', $m[0], $images);
        $plain = [];
        foreach ($images[1] as $image) {
            $image = trim($image, '"');
            self::assertDoesNotMatchRegularExpression('#^\$\{?[A-Za-z_]+\}?/#', $image, "$where: $image carries a prefix");
            self::assertMatchesRegularExpression('#^[a-z0-9][a-z0-9._-]*:#', $image, "$where: $image is a plain Docker Hub name with a tag");
            $plain[] = $image;
        }

        return $plain;
    }

    /**
     * The Preflight component includes, in file order.
     *
     * @return list<array{name: string, ref: string, comment: ?string, body: string}>
     */
    private static function includes(string $ci): array
    {
        preg_match_all(
            '~^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/([a-z0-9-]+)@(\S+)(?: # (\S+))?\n((?:    [^\n]*\n)*)~m',
            $ci,
            $m,
            PREG_SET_ORDER,
        );

        $includes = [];
        foreach ($m as $match) {
            $includes[] = [
                'name' => $match[1],
                'ref' => $match[2],
                'comment' => '' === $match[3] ? null : $match[3],
                'body' => "\n" . $match[4],
            ];
        }

        return $includes;
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }
}
