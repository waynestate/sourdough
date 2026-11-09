<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shape's pipeline carries three gates (BEST_PRACTICES 4.15, 6.4 and
 * 6.5): a bill of materials, an accessibility gate over the app's own
 * rendered pages, and the guards around deploy credentials. They come from
 * Preflight's sbom, a11y and secret-detection components, so this test
 * holds the wiring around them, not the jobs themselves. The per-shape
 * generator tests call assertBakedPipelineGates() on a baked app, so a
 * shape that loses a gate fails twice: once here, once where it is baked.
 * A loaf that skips the proofing basket still looks like bread, right up
 * until somebody cuts it.
 */
final class TemplateCiGatesTest extends TestCase
{
    /** The shapes whose overlay ships a front end, and so a frontend job. */
    private const FRONT_END_SHAPES = ['laravel-blade-vue', 'laravel-inertia', 'raw-vue'];

    /** The pages each shape renders for the gate: the home page, and the signed-in page where the shape has one. */
    private const PATHS = [
        'raw' => ['/'],
        'raw-vue' => ['/'],
        'laravel-blade' => ['/', '/dashboard'],
        'laravel-blade-vue' => ['/', '/dashboard'],
        'laravel-inertia' => ['/', '/dashboard'],
    ];

    /** The deploy template, verbatim: no merge request pipelines, protected refs only. */
    private const DEPLOY = ".deploy:\n  stage: deploy\n  resource_group: deploy\n  interruptible: false\n  rules:\n"
        . "    - if: \$CI_PIPELINE_SOURCE == \"merge_request_event\"\n      when: never\n"
        . "    - if: \$CI_COMMIT_REF_PROTECTED == \"true\"\n";

    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    public function testEveryShapeIsCovered(): void
    {
        $shapes = array_keys(iterator_to_array(self::pipelines()));
        sort($shapes);
        self::assertSame(array_keys(self::sorted(self::PATHS)), $shapes);

        // A shape whose overlay ships package.json must have its own pipeline,
        // or it inherits one with no frontend job and the gate gets no build.
        foreach (glob(dirname(__DIR__) . '/templates/*/package.json') ?: [] as $package) {
            self::assertFileExists(dirname($package) . '/.gitlab-ci.yml', basename(dirname($package)) . ' ships a front end');
        }
    }

    #[DataProvider('pipelines')]
    public function testTemplateCarriesTheGates(string $file): void
    {
        $shape = basename(dirname($file));
        self::assertBakedPipelineGates(self::read($file), $shape);
    }

    /**
     * The a11y-pages job's script is one block, the same in every shape;
     * only the lines that boot each shape's server differ.
     */
    public function testEveryShapeSavesItsPagesWithTheSameScript(): void
    {
        $blocks = [];
        foreach (self::pipelines() as $shape => [$file]) {
            $job = self::job(self::read($file), 'a11y-pages');
            $blocks[$shape] = self::capture('/^    - \|\n((?:      .*\n|\n)+)/m', $job, "$shape: the a11y-pages job has its snapshot block")[1];
        }

        self::assertCount(1, array_unique($blocks), 'the snapshot block differs between shapes');
        $block = (string) reset($blocks);
        self::assertStringContainsString('curl -sf -o /dev/null "$origin/up"', $block, 'waits for HELMET\'s /up');
        self::assertStringContainsString("find a11y-site -name '*.php' -delete", $block, 'never ships PHP source in the artifact');
        self::assertStringContainsString('-b .a11y-cookies -c .a11y-cookies', $block, 'one cookie jar keeps the fake sign-in');
    }

    /** The raw shapes fetch the same WayneUI release the front controller names. */
    public function testRawShapeFetchesTheWayneUiReleaseTheAppNames(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__) . '/templates/raw/public/index.php');
        $url = self::capture('#https://wayneui\.apps\.wayne\.edu/v2/releases/[0-9.]+/wayne-ui\.php#', $index, 'the raw front controller names a WayneUI release')[0];

        $job = self::job(self::read(dirname(__DIR__) . '/templates/raw/.gitlab-ci.yml'), 'a11y-pages');
        self::assertStringContainsString('test -f public/wayne-ui.php || curl -fsSL -o public/wayne-ui.php ' . $url . "\n", $job);
    }

    /**
     * Assert the gates on one pipeline, template or baked. Public so the
     * per-shape generator tests can hold the baked file to the same rules.
     */
    public static function assertBakedPipelineGates(string $ci, string $shape): void
    {
        $ci = str_replace("\r\n", "\n", $ci);
        $includes = self::includes($ci);

        // 4.15: Preflight's sbom component (Composer), on the shape's proxy rule.
        self::assertArrayHasKey('sbom', $includes, "$shape: includes Preflight's sbom component");
        self::assertStringContainsString('image_prefix: "${IMAGE_PREFIX}"', $includes['sbom'], "$shape: sbom follows IMAGE_PREFIX");

        // 6.5: gitleaks over the checkout, blocking.
        self::assertArrayHasKey('secret-detection', $includes, "$shape: includes Preflight's secret-detection component");
        self::assertStringNotContainsString('allow_failure', $includes['secret-detection'], "$shape: secret detection blocks");

        // 6.4: the a11y component over the pages the a11y-pages job saves, at WCAG 2.2 AA, blocking.
        self::assertArrayHasKey('a11y', $includes, "$shape: includes Preflight's a11y component");
        $a11y = $includes['a11y'];
        self::assertStringContainsString("      site_dir: a11y-site\n", $a11y, "$shape: a11y checks the saved pages");
        self::assertStringContainsString('      wcag_tags: ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]' . "\n", $a11y, "$shape: WCAG 2.2 AA");
        self::assertStringNotContainsString('allow_failure', $a11y, "$shape: the a11y gate blocks");
        $listed = json_decode(self::capture('/^      pages: (\[.*\])$/m', $a11y, "$shape: a11y lists its pages")[1], true, 512, JSON_THROW_ON_ERROR);

        $job = self::job($ci, 'a11y-pages');
        $saved = preg_split('/\s+/', trim(self::capture('/^    A11Y_PATHS: "([^"]*)"$/m', $job, "$shape: a11y-pages sets A11Y_PATHS")[1])) ?: [];
        self::assertSame($saved, $listed, "$shape: the a11y include's pages and A11Y_PATHS name the same pages");
        if (isset(self::PATHS[$shape])) {
            self::assertSame(self::PATHS[$shape], $saved, "$shape: checks the home page and the signed-in page");
        }
        self::assertStringContainsString("  extends: .composer-cache\n", $job, "$shape: a11y-pages installs on the Composer cache");
        self::assertStringContainsString("    paths:\n      - a11y-site/\n", $job, "$shape: a11y-pages hands its folder on");
        self::assertStringContainsString("\na11y:\n  needs: [a11y-pages]\n", $ci, "$shape: the a11y job waits for the pages");

        // The front-end shapes hand their build to the pages job and keep an npm SBOM.
        if (str_contains($ci, "\nfrontend:\n")) {
            $frontend = self::job($ci, 'frontend');
            self::assertStringContainsString("  needs: [frontend]\n", $job, "$shape: a11y-pages takes the frontend build");
            self::assertStringContainsString("      - public/build/\n      - sbom-npm.cdx.json\n", $frontend, "$shape: frontend keeps the build and the npm SBOM");
            // 4.10: install the lock exactly, install scripts off, then the audit gate.
            self::assertStringContainsString(
                "  script:\n    - npm ci --ignore-scripts --no-audit --no-fund\n    - npm audit --audit-level=high\n",
                $frontend,
                "$shape: frontend installs from the lock with no install scripts, then audits at high",
            );
            self::assertDoesNotMatchRegularExpression('/^    - npm (install|i)\b/m', $frontend, "$shape: frontend never resolves new versions with npm install");
            self::assertMatchesRegularExpression(
                '#^    - npm exec --yes --ignore-scripts --package=@cyclonedx/cyclonedx-npm@[0-9]+\.[0-9]+\.[0-9]+ -- cyclonedx-npm .*--validate --output-file sbom-npm\.cdx\.json$#m',
                $frontend,
                "$shape: the npm SBOM comes from CycloneDX's tool at an exact version, with no install scripts",
            );
        } else {
            self::assertNotContains($shape, self::FRONT_END_SHAPES, "$shape: a front-end shape has a frontend job");
            self::assertStringNotContainsString('needs: [frontend]', $job, "$shape: no frontend job to wait for");
        }

        // 6.5: deploys only from protected refs, never from a merge request.
        self::assertStringContainsString("\n" . self::DEPLOY, $ci, "$shape: ships the .deploy template");
        foreach (self::jobs($ci) as $name => $body) {
            if (1 === preg_match('/^  (stage: deploy|environment:)/m', $body)) {
                self::assertStringContainsString("  extends: .deploy\n", $body, "$shape: $name deploys, so it extends .deploy");
            }
        }

        // 6.5: no credential in the file. Component inputs reference only the
        // GitLab host and the proxy prefix, and no variable carries a secret.
        $includeBlock = self::includeBlock($ci);
        preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $includeBlock, $refs);
        self::assertSame([], array_values(array_diff(array_unique($refs[1]), ['CI_SERVER_FQDN', 'IMAGE_PREFIX'])), "$shape: component inputs carry no variables but the host and the prefix");
        self::assertDoesNotMatchRegularExpression('/^\s+[A-Z0-9_]*(TOKEN|PASSWORD|SECRET|PRIVATE_KEY|API_KEY|AUTH)[A-Z0-9_]*:/m', $ci, "$shape: no credential is set in the file");
    }

    /**
     * Each Preflight include's input block, by component name.
     *
     * @return array<string, string>
     */
    private static function includes(string $ci): array
    {
        preg_match_all(
            '~^  - component: \$CI_SERVER_FQDN/enterprise-apps/preflight/([a-z0-9-]+)@\S+(?: # \S+)?\n((?:    [^\n]*\n)*)~m',
            $ci,
            $m,
            PREG_SET_ORDER,
        );
        $out = [];
        foreach ($m as $match) {
            $out[$match[1]] = $match[2];
        }

        return $out;
    }

    private static function includeBlock(string $ci): string
    {
        return self::capture('/^include:\n(?:  [^\n]*\n|\n)*/m', $ci, 'the pipeline has an include block')[0];
    }

    /**
     * The first match of $pattern in $subject, failing the test when there is none.
     *
     * @return array<int, string>
     */
    private static function capture(string $pattern, string $subject, string $message): array
    {
        self::assertSame(1, preg_match($pattern, $subject, $m), $message);

        return array_map(strval(...), $m);
    }

    /** One top-level job's body. */
    private static function job(string $ci, string $name): string
    {
        $jobs = self::jobs($ci);
        self::assertArrayHasKey($name, $jobs, "the pipeline has a $name job");

        return $jobs[$name];
    }

    /**
     * The top-level jobs, name => body, hidden templates and GitLab keywords left out.
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

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }
}
