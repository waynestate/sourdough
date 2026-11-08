<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * Who owns each shape, whether it is still supported, and how it is retired
 * (BEST_PRACTICES 3.7), read from resources/shapes.json.
 *
 * The policy behind the fields is docs/guides/support.md. In short: every
 * shape names an owner role (never a person); a shape is supported while
 * its status says so, and the date its app inherits is the framework's
 * (CREED's security_until for the Laravel major, or for the PHP floor on
 * the raw shapes); a shape is retired one release ahead: it is marked
 * deprecated with the release that removes it and its replacement, and
 * `sourdough new` and `sourdough feed` say so on every run until then.
 * Even the best loaf goes stale; this is how we say so before it does.
 */
final class ShapeSupport
{
    public const FILE = __DIR__ . '/../resources/shapes.json';

    public const STATUS_SUPPORTED = 'supported';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_REMOVED = 'removed';

    /**
     * @param array<string, array{owner: string, status: string, deprecated_in: string|null, removed_in: string|null, replacement: string|null}> $shapes
     */
    private function __construct(
        private readonly array $shapes,
        public readonly string $policyUrl,
    ) {}

    /**
     * Read the catalog.
     *
     * @throws \RuntimeException when the file is missing, not JSON, or an entry is malformed
     */
    public static function load(string $path = self::FILE): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (false === $raw) {
            throw new \RuntimeException(sprintf('The shape catalog "%s" could not be read.', $path));
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['shapes'] ?? null) || !is_string($data['policy'] ?? null)) {
            throw new \RuntimeException(sprintf('The shape catalog "%s" needs a "policy" URL and a "shapes" object.', $path));
        }

        $shapes = [];
        foreach ($data['shapes'] as $name => $entry) {
            if (!is_array($entry) || !is_string($entry['owner'] ?? null) || !is_string($entry['status'] ?? null)) {
                throw new \RuntimeException(sprintf('The shape catalog entry "%s" needs an owner and a status.', (string) $name));
            }
            $shapes[(string) $name] = [
                'owner' => $entry['owner'],
                'status' => $entry['status'],
                'deprecated_in' => self::stringOrNull($entry['deprecated_in'] ?? null),
                'removed_in' => self::stringOrNull($entry['removed_in'] ?? null),
                'replacement' => self::stringOrNull($entry['replacement'] ?? null),
            ];
        }

        return new self($shapes, $data['policy']);
    }

    /**
     * Every rule the catalog breaks, one line each; empty when it is sound.
     * The rules are the sunset path in docs/guides/support.md: every shape
     * Sourdough bakes is listed and owned; a deprecated or removed shape
     * names the release that deprecated it, a later release that removes it
     * (one release ahead at least) and a replacement it can feed toward; a
     * supported shape carries none of that; and a removed shape is one
     * Sourdough no longer bakes.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach (Permutation::cases() as $permutation) {
            if (!isset($this->shapes[$permutation->value])) {
                $problems[] = sprintf('%s: Sourdough bakes it, but the catalog does not list it', $permutation->value);
            }
        }

        $statuses = [self::STATUS_SUPPORTED, self::STATUS_DEPRECATED, self::STATUS_REMOVED];
        foreach ($this->shapes as $name => $entry) {
            if ('' === trim($entry['owner'])) {
                $problems[] = sprintf('%s: no owner role', $name);
            }
            if (!in_array($entry['status'], $statuses, true)) {
                $problems[] = sprintf('%s: status "%s" is not one of %s', $name, $entry['status'], implode(', ', $statuses));
                continue;
            }

            $baked = null !== Permutation::tryFrom($name);
            if (self::STATUS_REMOVED === $entry['status'] && $baked) {
                $problems[] = sprintf('%s: marked removed, but Sourdough still bakes it', $name);
            }
            if (self::STATUS_REMOVED !== $entry['status'] && !$baked) {
                $problems[] = sprintf('%s: Sourdough does not bake it, so it must be marked removed', $name);
            }

            if (self::STATUS_SUPPORTED === $entry['status']) {
                if (null !== $entry['deprecated_in'] || null !== $entry['removed_in'] || null !== $entry['replacement']) {
                    $problems[] = sprintf('%s: a supported shape carries no deprecated_in, removed_in or replacement', $name);
                }
                continue;
            }

            if (null === $entry['deprecated_in'] || null === $entry['removed_in'] || null === $entry['replacement']) {
                $problems[] = sprintf('%s: a %s shape needs deprecated_in, removed_in and replacement', $name, $entry['status']);
                continue;
            }
            if (!version_compare($entry['removed_in'], $entry['deprecated_in'], '>')) {
                $problems[] = sprintf('%s: removed_in (%s) must be a later release than deprecated_in (%s)', $name, $entry['removed_in'], $entry['deprecated_in']);
            }
            if (null === Permutation::tryFrom($entry['replacement'])) {
                $problems[] = sprintf('%s: the replacement "%s" is not a shape Sourdough bakes', $name, $entry['replacement']);
            }
        }

        return $problems;
    }

    /**
     * The catalog entry for a template name, or null when it is not listed.
     *
     * @return array{owner: string, status: string, deprecated_in: string|null, removed_in: string|null, replacement: string|null}|null
     */
    public function entry(string $template): ?array
    {
        return $this->shapes[$template] ?? null;
    }

    /**
     * The deprecation or retirement notice for a template, or null while it
     * is supported (or not listed).
     */
    public function notice(string $template): ?string
    {
        $entry = $this->entry($template);
        if (null === $entry) {
            return null;
        }

        return match ($entry['status']) {
            self::STATUS_DEPRECATED => sprintf(
                'The %s shape is deprecated since Sourdough %s and is removed in %s; move to %s. See %s',
                $template,
                (string) $entry['deprecated_in'],
                (string) $entry['removed_in'],
                (string) $entry['replacement'],
                $this->policyUrl,
            ),
            self::STATUS_REMOVED => sprintf(
                'The %s shape was retired in Sourdough %s; move to %s. See %s',
                $template,
                (string) $entry['removed_in'],
                (string) $entry['replacement'],
                $this->policyUrl,
            ),
            default => null,
        };
    }

    /**
     * The support facts for one shape, for --json: the catalog entry plus
     * the line the app inherits its clock from and that line's end of
     * security support in CREED's policy (null when the policy was not read).
     *
     * @return array{shape: string, owner: string, status: string, deprecated_in: string|null, removed_in: string|null, replacement: string|null, policy: string, runs_on: array{component: string, version: string, security_until: string|null}}
     */
    public function toArray(Permutation $permutation, PhpFloor $floor): array
    {
        $entry = $this->entry($permutation->value) ?? [
            'owner' => '',
            'status' => self::STATUS_SUPPORTED,
            'deprecated_in' => null,
            'removed_in' => null,
            'replacement' => null,
        ];

        return ['shape' => $permutation->value] + $entry + [
            'policy' => $this->policyUrl,
            'runs_on' => self::runsOn($floor),
        ];
    }

    /**
     * One line for people: "supported, owned by Sourdough maintainers;
     * Laravel 13 gets security fixes until 2028-03-17".
     */
    public function line(Permutation $permutation, PhpFloor $floor): string
    {
        $entry = $this->entry($permutation->value);
        $runsOn = self::runsOn($floor);
        $label = 'laravel' === $runsOn['component'] ? 'Laravel ' . $runsOn['version'] : 'PHP ' . $runsOn['version'];
        $until = null === $runsOn['security_until']
            ? sprintf('%s (its end date is in CREED\'s policy, which was not read)', $label)
            : sprintf('%s gets security fixes until %s', $label, $runsOn['security_until']);

        return sprintf(
            '%s, owned by %s; %s',
            $entry['status'] ?? self::STATUS_SUPPORTED,
            $entry['owner'] ?? 'nobody (not in the catalog)',
            $until,
        );
    }

    /**
     * @return array{component: string, version: string, security_until: string|null}
     */
    private static function runsOn(PhpFloor $floor): array
    {
        if (null !== $floor->laravelMajor) {
            return ['component' => 'laravel', 'version' => $floor->laravelMajor, 'security_until' => $floor->laravelSecurityUntil];
        }

        return ['component' => 'php', 'version' => $floor->floor, 'security_until' => $floor->floorSecurityUntil];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
