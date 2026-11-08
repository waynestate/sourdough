<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use DateTimeImmutable;
use Waynestate\Creed\Policy;
use Waynestate\Sourdough\Generator\ComposerSkeletonSource;

/**
 * The PHP floor and test matrix a new app gets, read from CREED's
 * policy.json at bake time instead of being hard-coded in each template
 * (BEST_PRACTICES 4.2).
 *
 * The policy is the one CREED publishes: the copy Composer installs with
 * waynestate/creed (a Sourdough dependency, and the same file PROCTOR's
 * php-floor check reads in the app), or the file named by the
 * SOURDOUGH_POLICY environment variable. The rule:
 *
 * 1. As of the policy's own refreshed_on date (not today's clock, so the
 *    same Sourdough and the same policy always bake the same loaf, and a
 *    floor moves only when the policy does), look HORIZON_DAYS ahead.
 * 2. The floor is the oldest PHP line CREED's reader still calls supported
 *    on that later date (Policy::oldestSupported()). A new app never starts
 *    on a line that ends inside its first year. For a Laravel shape the
 *    floor is raised to the Laravel major's own minimum PHP when that is
 *    higher.
 * 3. The test matrix is every line from the floor up that is supported on
 *    the as-of date and has a Docker image in the policy (the image
 *    Preflight's php-test job pulls), capped at the Laravel major's maximum
 *    PHP for a Laravel shape.
 *
 * When the policy cannot be read or does not answer (no CREED, a bad file,
 * no refreshed_on, no line qualifies), the bake does not fail: it uses the
 * pinned fallback, the same values the templates carry as literals, and
 * says so. Starter culture in the fridge, in case the jar is empty.
 */
final class PhpFloor
{
    /** The pinned fallback floor, also the literal every template carries. */
    public const FALLBACK_FLOOR = '8.3';

    /**
     * The pinned fallback test matrix, also the literal every template's
     * .gitlab-ci.yml carries.
     *
     * @var list<string>
     */
    public const FALLBACK_VERSIONS = ['8.3', '8.4', '8.5'];

    /**
     * How far past the policy's refreshed_on date the floor must still be
     * supported: a new app gets at least a year on its floor. A proposal
     * (see docs/guides/support.md); CREED is the better home for it.
     */
    public const HORIZON_DAYS = 365;

    /** The environment variable that names another CREED policy file. */
    public const ENV_POLICY = 'SOURDOUGH_POLICY';

    public const SOURCE_POLICY = 'policy';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param list<string> $versions
     * @param string|null  $floorSecurityUntil   the floor line's security_until in the policy; null on the fallback
     * @param string|null  $laravelMajor         the Laravel major a Laravel shape generates; null for the raw shapes
     * @param string|null  $laravelSecurityUntil that major's security_until in the policy; null on the fallback or a raw shape
     */
    private function __construct(
        public readonly string $floor,
        public readonly array $versions,
        public readonly string $source,
        public readonly string $origin,
        public readonly ?string $refreshedOn,
        public readonly ?string $reason,
        public readonly ?string $floorSecurityUntil = null,
        public readonly ?string $laravelMajor = null,
        public readonly ?string $laravelSecurityUntil = null,
    ) {}

    /**
     * The floor for one shape: the Laravel shapes are bounded by the Laravel
     * major Sourdough generates, the raw shapes by PHP alone.
     */
    public static function forPermutation(Permutation $permutation): self
    {
        $laravel = match ($permutation) {
            Permutation::Raw, Permutation::RawVue => null,
            default => ComposerSkeletonSource::laravelMajor(),
        };

        return self::resolve($laravel);
    }

    /**
     * The floor for a new app, from SOURDOUGH_POLICY when it is set, else
     * from the policy installed with waynestate/creed; the pinned fallback
     * when neither can be read.
     *
     * @param string|null $laravelMajor the Laravel major a Laravel shape generates ("13"); null for the raw shapes
     */
    public static function resolve(?string $laravelMajor = null): self
    {
        $env = getenv(self::ENV_POLICY);
        if (is_string($env) && '' !== $env) {
            return self::fromFile($env, $laravelMajor);
        }

        $path = self::bundledPath();
        if (null === $path) {
            return self::fallback('waynestate/creed is not installed', $laravelMajor);
        }

        return self::fromFile($path, $laravelMajor, 'waynestate/creed');
    }

    /**
     * The floor from one policy file, or the fallback with the reason it
     * could not be read.
     *
     * @param string|null $origin how to name the file in messages; null means its path
     */
    public static function fromFile(string $path, ?string $laravelMajor = null, ?string $origin = null): self
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (false === $raw) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('the policy file "%s" could not be read', $path));
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('the policy file "%s" is not valid JSON', $path));
        }
        if (!is_array($data)) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('the policy file "%s" is not a JSON object', $path));
        }

        /** @var array<string, mixed> $data */
        return self::fromData($data, $origin ?? $path, $laravelMajor);
    }

    /**
     * The floor from an already-decoded policy, or the fallback with the
     * reason it does not answer.
     *
     * @param array<string, mixed> $data
     */
    public static function fromData(array $data, string $origin, ?string $laravelMajor = null): self
    {
        $refreshedOn = $data['refreshed_on'] ?? null;
        $asOf = is_string($refreshedOn) ? DateTimeImmutable::createFromFormat('!Y-m-d', $refreshedOn) : false;
        if (!is_string($refreshedOn) || false === $asOf || $asOf->format('Y-m-d') !== $refreshedOn) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('%s has no usable refreshed_on date', $origin));
        }

        try {
            $policy = Policy::fromArray($data);
            $floor = $policy->oldestSupported('php', $asOf->modify(sprintf('+%d days', self::HORIZON_DAYS)));
            $range = null === $laravelMajor ? null : $policy->phpRange('laravel', $laravelMajor);
            $current = $policy->supported('php', $asOf);
        } catch (\InvalidArgumentException $e) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('%s could not be read: %s', $origin, $e->getMessage()));
        }

        if (null === $floor) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('%s lists no PHP line still supported %d days after %s', $origin, self::HORIZON_DAYS, $refreshedOn));
        }
        if (null !== $laravelMajor && null === $range) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('%s has no PHP range for Laravel %s', $origin, $laravelMajor));
        }
        if (null !== $range && version_compare($range['min'], $floor, '>')) {
            $floor = $range['min'];
        }

        $versions = [];
        foreach ($current as $version) {
            if (version_compare($version, $floor, '<')) {
                continue;
            }
            if (null !== $range && version_compare($version, $range['max'], '>')) {
                continue;
            }
            if (!self::hasDockerImage($data, $version)) {
                continue;
            }
            $versions[] = $version;
        }

        if ([] === $versions || $versions[0] !== $floor) {
            return self::fallback(laravelMajor: $laravelMajor, reason: sprintf('%s has no supported PHP %s line with a Docker image to test the floor on', $origin, $floor));
        }

        return new self(
            $floor,
            $versions,
            self::SOURCE_POLICY,
            $origin,
            $refreshedOn,
            null,
            $policy->securityUntil('php', $floor)?->format('Y-m-d'),
            $laravelMajor,
            null === $laravelMajor ? null : $policy->securityUntil('laravel', $laravelMajor)?->format('Y-m-d'),
        );
    }

    /**
     * The pinned fallback, with the reason the policy was not used.
     */
    public static function fallback(string $reason, ?string $laravelMajor = null): self
    {
        return new self(self::FALLBACK_FLOOR, self::FALLBACK_VERSIONS, self::SOURCE_FALLBACK, 'pinned fallback', null, $reason, null, $laravelMajor);
    }

    /** The composer.json require.php constraint: "^8.3". */
    public function constraint(): string
    {
        return '^' . $this->floor;
    }

    /** The composer.json config.platform.php pin: "8.3.0". */
    public function platform(): string
    {
        return $this->floor . '.0';
    }

    /** The matrix as prose: "8.3, 8.4 and 8.5". */
    public function prose(): string
    {
        return self::proseOf($this->versions);
    }

    /**
     * A list of versions as prose: "8.3, 8.4 and 8.5", "8.4 and 8.5", "8.5".
     *
     * @param list<string> $versions
     */
    public static function proseOf(array $versions): string
    {
        if (\count($versions) < 2) {
            return implode('', $versions);
        }
        $last = array_pop($versions);

        return implode(', ', $versions) . ' and ' . $last;
    }

    /** The matrix as a YAML flow list: ["8.3", "8.4", "8.5"]. */
    public function yamlList(): string
    {
        return self::yamlListOf($this->versions);
    }

    /**
     * @param list<string> $versions
     */
    public static function yamlListOf(array $versions): string
    {
        return '[' . implode(', ', array_map(static fn(string $v): string => '"' . $v . '"', $versions)) . ']';
    }

    /**
     * One line for people: where the floor came from.
     */
    public function describe(): string
    {
        if (self::SOURCE_POLICY === $this->source) {
            return sprintf('PHP %s, tested on %s (from %s, refreshed %s)', $this->constraint(), $this->prose(), $this->origin, (string) $this->refreshedOn);
        }

        return sprintf('PHP %s, tested on %s (pinned fallback: %s)', $this->constraint(), $this->prose(), (string) $this->reason);
    }

    /**
     * The same facts for --json.
     *
     * @return array{constraint: string, platform: string, versions: list<string>, floor_security_until: string|null, source: string, origin: string, refreshed_on: string|null, horizon_days: int, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'constraint' => $this->constraint(),
            'platform' => $this->platform(),
            'versions' => $this->versions,
            'floor_security_until' => $this->floorSecurityUntil,
            'source' => $this->source,
            'origin' => $this->origin,
            'refreshed_on' => $this->refreshedOn,
            'horizon_days' => self::HORIZON_DAYS,
            'reason' => $this->reason,
        ];
    }

    /**
     * The policy.json that ships with the installed waynestate/creed: the
     * file CREED's own Policy::bundled() reads. Null when CREED is absent.
     */
    private static function bundledPath(): ?string
    {
        if (!class_exists(Policy::class)) {
            return null;
        }
        $file = (new \ReflectionClass(Policy::class))->getFileName();
        if (false === $file) {
            return null;
        }
        $path = dirname($file, 2) . '/policy/policy.json';

        return is_file($path) ? $path : null;
    }

    /**
     * True when the policy names a Docker image for the PHP line. Read from
     * the raw data: CREED's reader does not expose images.
     *
     * @param array<string, mixed> $data
     */
    private static function hasDockerImage(array $data, string $version): bool
    {
        $components = $data['components'] ?? null;
        $php = is_array($components) ? ($components['php'] ?? null) : null;
        $versions = is_array($php) ? ($php['versions'] ?? null) : null;
        $line = is_array($versions) ? ($versions[$version] ?? null) : null;
        $images = is_array($line) ? ($line['images'] ?? null) : null;
        $docker = is_array($images) ? ($images['docker'] ?? null) : null;

        return is_string($docker) && '' !== $docker;
    }
}
