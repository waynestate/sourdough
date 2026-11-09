<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\PlaybookMode;

/**
 * Copies the raw template into a target dir, replacing {{app_name}} and
 * {{app_slug}} in file contents and file names.
 *
 * The template dir is a constructor argument so tests can point it at a
 * fixture; by default it is the real templates/raw dir.
 *
 * The generator refuses an existing non-empty target dir and writes nothing
 * outside the target dir.
 *
 * After copying the template, the generator installs the raw deploy files
 * from the checked copy of Squire's templates (templates/squire/). Those
 * files are copied as-is (Squire owns them, so Sourdough does not rewrite
 * them), and their executable bits come from Squire's manifest.
 *
 * When the shape ships a package.json (the raw-vue overlay does, plain raw
 * does not), the generator also installs Squire's Vue .npmrc, which sets
 * the one-day npm release cooldown (min-release-age=1); an app that already
 * has a .npmrc gets the line merged in, not overwritten.
 *
 * Then the generator wires Playbook into the app per the chosen mode: by
 * default it requires the package (VCS repository, require entry and the
 * gitlab-domains config entry), lists its values in .env.example and
 * documents them in the README; Suggest keeps the old suggest-only wiring
 * for library mode; None leaves the app untouched.
 *
 * The app ships ADVISOR by default: waynestate/advisor under require-dev,
 * .mcp.json git-ignored and an "ADVISOR" section in the README. With
 * $advisor false (--no-advisor) the wiring comes out again.
 *
 * Finally the generator writes `.sourdough/manifest.json` so a later
 * `sourdough feed` knows what was generated, including the Squire files.
 *
 * The shared steps (refusing the target, copying, the Squire install, the
 * Playbook wiring) live in GeneratorSupport, which the next generator
 * (Laravel + Blade) reuses.
 */
final class RawGenerator
{
    /**
     * The files Sourdough seeds once and then leaves to the app. Everything
     * else in the template is managed: `sourdough feed` updates it, merging
     * in whatever the app changed.
     *
     * - .env.example: the app's environment config. The developer fills in
     *   real values per environment, so Sourdough must not clobber them.
     * - README.md: the app's own documentation. The developer rewrites it
     *   for their app, so overwriting it would destroy their work.
     * - package-lock.json (the shapes with a front end): the record of what
     *   the app installed. The first one comes from the template, so a fresh
     *   bake passes `npm ci`; after that npm writes it, and a three-way text
     *   merge of two lock files is a pile of crumbs, not a loaf.
     */
    public const SEEDED_FILES = [
        '.env.example',
        'README.md',
        'package-lock.json',
    ];

    /**
     * The app name allow-list: a letter or number first, then letters,
     * numbers, spaces, dots, dashes and underscores, at most 63 characters.
     *
     * The allowed alphabet needs no escaping in JSON, single-quoted PHP
     * strings, Markdown or INI, so the generator may paste the name into
     * template contents unescaped.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,62}\z/';

    /**
     * Playbook (waynestate/playbook) is WSU's private shared-values package
     * on git.wayne.edu. A generated app requires it by default; the stack
     * reads each value from the environment first, then from Playbook.
     */
    public const PLAYBOOK_PACKAGE = 'waynestate/playbook';

    public const PLAYBOOK_VERSION = '^0.1';

    public const PLAYBOOK_REPO_URL = 'https://git.wayne.edu/enterprise-apps/playbook.git';

    public const PLAYBOOK_SUGGEST = "WSU's shared values; private, git.wayne.edu";

    /**
     * ADVISOR (waynestate/advisor) is the stack's MCP server, on Packagist.
     * Every Sourdough app ships it in require-dev by default; --no-advisor
     * removes the wiring again.
     */
    public const ADVISOR_PACKAGE = 'waynestate/advisor';

    public const ADVISOR_VERSION = '^0.2';

    /**
     * The seven setting names Playbook holds, listed in .env.example.
     *
     * @var list<string>
     */
    public const PLAYBOOK_ENV_VARS = [
        'SESAME_CAS_BASE_URL',
        'SESAME_OAUTH2_ISSUER',
        'SESAME_OAUTH2_JWKS_URL',
        'SESAME_GATEWAY_ISSUER',
        'ROLLCALL_GITLAB_URL',
        'SOURDOUGH_LLM_BASE_URL',
        'HELMET_PRESET',
    ];

    private readonly GeneratorSupport $support;

    /**
     * @param string|null  $overlayDir   a second template copied over the raw one, replacing files it also ships (RawVueGenerator's raw-vue dir); null for plain raw
     * @param string       $templateName the template name the manifest records
     * @param list<string> $leftovers    raw template files the overlay has no use for, removed after the copy
     */
    public function __construct(
        private readonly string $templateDir = __DIR__ . '/../../templates/raw',
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
        private readonly ?string $overlayDir = null,
        private readonly string $templateName = 'raw',
        private readonly array $leftovers = [],
    ) {
        $this->support = new GeneratorSupport($squireDir);
    }

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers  each option the user chose, recorded in the manifest
     * @param PlaybookMode               $playbook how the app wires Playbook (see GeneratorSupport::applyPlaybook)
     * @param bool                       $advisor  wire ADVISOR into the app (the default) or remove the wiring (--no-advisor)
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN, the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the template is missing or a file cannot be read or written
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Require, bool $advisor = true): void
    {
        self::assertValidName($name);

        $slug = self::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name,
            ));
        }

        foreach (array_filter([$this->templateDir, $this->overlayDir]) as $dir) {
            if (!is_dir($dir)) {
                throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $dir));
            }
        }

        $this->support->refuseNonEmptyTarget($targetDir);

        $this->support->copyDir($this->templateDir, $targetDir, $name, $slug);
        if (null !== $this->overlayDir) {
            $this->support->copyDir($this->overlayDir, $targetDir, $name, $slug);
        }
        foreach ($this->leftovers as $leftover) {
            $this->removeLeftover($targetDir, $leftover);
        }
        // The PHP floor and test matrix come from CREED's policy
        // (BEST_PRACTICES 4.2); the template's literals are the fallback.
        $floor = PhpFloor::resolve();
        $this->support->applyPhpFloor($targetDir, $floor);
        $permutation = Permutation::tryFrom($this->templateName);
        if (null !== $permutation) {
            $this->support->applySupportLine($targetDir, $permutation, $floor);
        }
        $this->support->installSquireFiles($targetDir);
        if ($this->shipsPackageJson()) {
            $this->support->installNpmrc($targetDir);
        }
        $this->support->applyPlaybook($targetDir, $playbook);
        $this->support->applyAdvisor($targetDir, $advisor);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest($this->templateName, $answers, self::SEEDED_FILES))->write($targetDir);
    }

    /**
     * Throw when $name is not safe to paste into generated file names and
     * contents. Called before generate() touches the disk.
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN
     */
    public static function assertValidName(string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'The app name must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters.',
            );
        }
    }

    /**
     * Remove one raw template file the overlay does not use, and its folder
     * when that leaves it empty.
     *
     * @throws \RuntimeException when the file cannot be removed
     */
    private function removeLeftover(string $targetDir, string $relative): void
    {
        $path = $targetDir . '/' . $relative;
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Could not remove "%s".', $path));
        }

        $dir = dirname($path);
        if ($dir !== $targetDir && is_dir($dir) && [] === array_diff(scandir($dir) ?: [], ['.', '..'])) {
            rmdir($dir);
        }
    }

    /**
     * True when this shape ships a package.json: the plain raw template has
     * no front end, but the raw-vue overlay adds one. Checked on the
     * template dirs, never on the target, so the answer does not depend on
     * what the app already holds.
     */
    private function shipsPackageJson(): bool
    {
        foreach (array_filter([$this->templateDir, $this->overlayDir]) as $dir) {
            if (is_file($dir . '/package.json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case the name and keep only [a-z0-9-], collapsing runs of other
     * characters into a single dash.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
