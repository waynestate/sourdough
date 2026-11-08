<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Sourdough\Generator\ComposerSkeletonSource;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelBladeVueGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\Generator\RawVueGenerator;
use Waynestate\Sourdough\Generator\SailScaffold;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\PlaybookMode;

/**
 * BEST_PRACTICES 4.2: the PHP floor, the platform pin and the CI matrix of
 * every shape come from CREED's policy.json at bake time, with the pinned
 * fallback when the policy cannot be read. Each test edits a copy of the
 * policy CREED ships and points SOURDOUGH_POLICY at it, so the rule is
 * proved against the real file, not a hand-made one. The Laravel shapes
 * bake on the fixture skeleton, so nothing touches the network.
 */
final class PhpFloorTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        putenv(PhpFloor::ENV_POLICY);
    }

    protected function tearDown(): void
    {
        putenv(PhpFloor::ENV_POLICY);
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testTheInstalledCreedPolicyIsTheSource(): void
    {
        $floor = PhpFloor::resolve();

        self::assertSame(PhpFloor::SOURCE_POLICY, $floor->source, (string) $floor->reason);
        self::assertSame('waynestate/creed', $floor->origin);
        self::assertSame($this->bundled()['refreshed_on'], $floor->refreshedOn);
    }

    /**
     * The pinned fallback (and the literals in the templates) must say what
     * the policy says today. When CREED moves the floor, this fails: update
     * PhpFloor::FALLBACK_* and the template literals together.
     */
    public function testTheFallbackMatchesWhatThePolicySaysToday(): void
    {
        foreach ([null, ComposerSkeletonSource::laravelMajor()] as $laravel) {
            $floor = PhpFloor::resolve($laravel);
            self::assertSame(PhpFloor::FALLBACK_FLOOR, $floor->floor);
            self::assertSame(PhpFloor::FALLBACK_VERSIONS, $floor->versions);
        }
    }

    public function testTheTemplatesCarryTheFallbackAsTheirLiterals(): void
    {
        $root = dirname(__DIR__) . '/templates';

        $raw = json_decode((string) file_get_contents($root . '/raw/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^' . PhpFloor::FALLBACK_FLOOR, $raw['require']['php']);
        self::assertSame(PhpFloor::FALLBACK_FLOOR . '.0', $raw['config']['platform']['php']);

        foreach (['laravel-blade', 'laravel-inertia'] as $overlay) {
            $composer = json_decode((string) file_get_contents($root . '/' . $overlay . '/composer.overlay.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(PhpFloor::FALLBACK_FLOOR . '.0', $composer['config']['platform']['php'], $overlay);
        }

        foreach (['raw', 'raw-vue', 'laravel-blade', 'laravel-inertia'] as $template) {
            $ci = (string) file_get_contents($root . '/' . $template . '/.gitlab-ci.yml');
            self::assertStringContainsString('php_versions: ' . PhpFloor::yamlListOf(PhpFloor::FALLBACK_VERSIONS), $ci, $template);
        }
        foreach (['raw', 'raw-vue'] as $template) {
            $readme = (string) file_get_contents($root . '/' . $template . '/README.md');
            self::assertStringContainsString('the test suite on PHP ' . PhpFloor::proseOf(PhpFloor::FALLBACK_VERSIONS), $readme, $template);
        }
    }

    public function testTheFloorLooksAYearPastThePolicyDate(): void
    {
        // On the bundled policy's date 8.2 is still in security support,
        // but it ends inside the year, so a new app starts on 8.3.
        $policy = Policy::fromArray($this->bundled());
        $asOf = new \DateTimeImmutable((string) $this->bundled()['refreshed_on']);
        self::assertContains('8.2', $policy->supported('php', $asOf), 'the premise: 8.2 is still supported on the policy date');

        self::assertSame('8.3', PhpFloor::fromData($this->bundled(), 'test')->floor);
    }

    public function testRaisingThePolicyDateRaisesTheFloor(): void
    {
        // 8.3 leaves security support on 2027-12-31, so from 2027-01-01 a
        // year ahead lands past it.
        $data = $this->bundled();
        $data['refreshed_on'] = '2027-03-01';

        $floor = PhpFloor::fromData($data, 'test');

        self::assertSame(PhpFloor::SOURCE_POLICY, $floor->source, (string) $floor->reason);
        self::assertSame('8.4', $floor->floor);
        self::assertSame(['8.4', '8.5'], $floor->versions);
        self::assertSame('^8.4', $floor->constraint());
        self::assertSame('8.4.0', $floor->platform());
        self::assertSame('2028-12-31', $floor->floorSecurityUntil);
    }

    public function testTheLaravelMajorBoundsTheFloorAndTheMatrix(): void
    {
        $data = $this->bundled();
        $major = ComposerSkeletonSource::laravelMajor();
        // A newer PHP line with an image: the raw shapes test on it, the
        // Laravel shapes stop at the major's maximum.
        $data['components']['php']['versions']['8.6'] = [
            'active_until' => '2028-12-31',
            'security_until' => '2030-12-31',
            'images' => ['docker' => 'php:8.6-cli-trixie'],
        ];
        $data['components']['laravel']['versions'][$major]['requires']['php'] = ['min' => '8.4', 'max' => '8.5'];

        $raw = PhpFloor::fromData($data, 'test');
        $laravel = PhpFloor::fromData($data, 'test', $major);

        self::assertSame('8.3', $raw->floor);
        self::assertSame(['8.3', '8.4', '8.5', '8.6'], $raw->versions);
        self::assertSame('8.4', $laravel->floor, 'raised to the Laravel major\'s minimum');
        self::assertSame(['8.4', '8.5'], $laravel->versions, 'capped at the Laravel major\'s maximum');
        self::assertSame($major, $laravel->laravelMajor);
        self::assertNotNull($laravel->laravelSecurityUntil);
    }

    public function testALineWithoutAnImageIsNotTested(): void
    {
        $data = $this->bundled();
        unset($data['components']['php']['versions']['8.5']['images']);

        self::assertSame(['8.3', '8.4'], PhpFloor::fromData($data, 'test')->versions);
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function unreadablePolicies(): iterable
    {
        yield 'missing file' => [null];
        yield 'not JSON' => ['{ not json'];
        yield 'no refreshed_on' => ['{"schema_version": 1, "components": {}}'];
        yield 'no schema_version' => ['{"refreshed_on": "2026-11-01", "components": {"php": {"versions": {}}}}'];
        yield 'no php component' => ['{"schema_version": 1, "refreshed_on": "2026-11-01", "components": {}}'];
        yield 'nothing supported' => ['{"schema_version": 1, "refreshed_on": "2026-11-01", "components": {"php": {"versions": {"7.4": {"active_until": "2021-11-28", "security_until": "2022-11-28"}}}}}'];
    }

    #[DataProvider('unreadablePolicies')]
    public function testAPolicyThatCannotBeReadFallsBackToThePin(?string $content): void
    {
        $dir = $this->makeTempDir();
        $path = $dir . '/policy.json';
        if (null !== $content) {
            file_put_contents($path, $content);
        }
        putenv(PhpFloor::ENV_POLICY . '=' . $path);

        $floor = PhpFloor::resolve();

        self::assertSame(PhpFloor::SOURCE_FALLBACK, $floor->source);
        self::assertSame(PhpFloor::FALLBACK_FLOOR, $floor->floor);
        self::assertSame(PhpFloor::FALLBACK_VERSIONS, $floor->versions);
        self::assertNotNull($floor->reason);
        self::assertStringContainsString('pinned fallback', $floor->describe());
    }

    /**
     * The test the practice asks for: change the policy's floor and every
     * shape bakes the new one into composer.json, its platform pin, its CI
     * matrix (and the comment above it), and the README's test-suite line.
     */
    public function testChangingThePolicyFloorChangesEveryShape(): void
    {
        $data = $this->bundled();
        $data['refreshed_on'] = '2027-03-01';
        putenv(PhpFloor::ENV_POLICY . '=' . $this->writePolicy($data));

        foreach ($this->shapes() as [$shape, $generator, $laravel]) {
            $target = $this->makeTempDir();
            $generator->generate($target, 'My Cool App', [], PlaybookMode::Suggest);

            $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('^8.4', $composer['require']['php'], "$shape require.php");
            self::assertSame('8.4.0', $composer['config']['platform']['php'], "$shape config.platform.php");

            $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');
            self::assertStringContainsString('php_versions: ["8.4", "8.5"]', $ci, "$shape CI matrix");
            self::assertStringNotContainsString('"8.3"', $ci, "$shape CI matrix");
            if ($laravel) {
                self::assertStringContainsString('My Cool App requires PHP ^8.4, so the test matrix starts at 8.4.', $ci, "$shape CI comment");
            } else {
                self::assertStringContainsString('the test suite on PHP 8.4 and 8.5', (string) file_get_contents($target . '/README.md'), "$shape README");
            }
        }
    }

    public function testTheBundledPolicyBakesTheTemplateAsWritten(): void
    {
        foreach ($this->shapes() as [$shape, $generator]) {
            $target = $this->makeTempDir();
            $generator->generate($target, 'My Cool App', [], PlaybookMode::Suggest);

            $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('^8.3', $composer['require']['php'], $shape);
            self::assertSame('8.3.0', $composer['config']['platform']['php'], $shape);
            self::assertStringContainsString('php_versions: ["8.3", "8.4", "8.5"]', (string) file_get_contents($target . '/.gitlab-ci.yml'), $shape);
        }
    }

    public function testAnUnreadablePolicyStillBakesEveryShapeOnThePin(): void
    {
        putenv(PhpFloor::ENV_POLICY . '=' . $this->makeTempDir() . '/missing.json');

        foreach ($this->shapes() as [$shape, $generator]) {
            $target = $this->makeTempDir();
            $generator->generate($target, 'My Cool App', [], PlaybookMode::Suggest);

            $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('^' . PhpFloor::FALLBACK_FLOOR, $composer['require']['php'], $shape);
            self::assertSame(PhpFloor::FALLBACK_FLOOR . '.0', $composer['config']['platform']['php'], $shape);
        }
    }

    /**
     * The Docker base and the Sail runtime stay pinned (by digest, moved by
     * Renovate), but they must be a line the policy's matrix tests.
     */
    public function testTheDockerBaseAndSailRuntimeAreOnThePolicyMatrix(): void
    {
        $versions = PhpFloor::forPermutation(Permutation::LaravelInertia)->versions;

        $dockerfile = (string) file_get_contents(dirname(__DIR__) . '/templates/docker/Dockerfile');
        self::assertSame(2, preg_match_all('/^FROM php:(\d+\.\d+)-/m', $dockerfile, $matches));
        foreach ($matches[1] as $line) {
            self::assertContains($line, $versions, 'the Docker base PHP ' . $line);
        }

        self::assertContains(SailScaffold::SAIL_RUNTIME, $versions, 'the Sail runtime');
    }

    /**
     * @return list<array{string, RawGenerator|RawVueGenerator|LaravelBladeGenerator|LaravelBladeVueGenerator|LaravelInertiaGenerator, bool}>
     */
    private function shapes(): array
    {
        $skeleton = new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton');

        return [
            ['raw', new RawGenerator(), false],
            ['raw-vue', new RawVueGenerator(), false],
            ['laravel-blade', new LaravelBladeGenerator($skeleton), true],
            ['laravel-blade-vue', new LaravelBladeVueGenerator($skeleton), true],
            ['laravel-inertia', new LaravelInertiaGenerator($skeleton), true],
        ];
    }

    /**
     * The policy.json the installed waynestate/creed ships.
     *
     * @return array<string, mixed>
     */
    private function bundled(): array
    {
        $file = (string) (new \ReflectionClass(Policy::class))->getFileName();
        $data = json_decode((string) file_get_contents(dirname($file, 2) . '/policy/policy.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writePolicy(array $data): string
    {
        $path = $this->makeTempDir() . '/policy.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
