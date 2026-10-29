<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Manifest;
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
 * Then the generator wires Playbook into the app per the chosen mode: by
 * default it suggests the package, lists its values in .env.example and
 * documents them in the README; --playbook also requires it with its VCS
 * repository; --no-playbook leaves the app untouched.
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
     * else in the template is managed: `sourdough feed` (a later release)
     * will update it.
     *
     * - .env.example: the app's environment config. The developer fills in
     *   real values per environment, so Sourdough must not clobber them.
     * - README.md: the app's own documentation. The developer rewrites it
     *   for their app, so overwriting it would destroy their work.
     */
    public const SEEDED_FILES = [
        '.env.example',
        'README.md',
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
     * on git.wayne.edu. The stack reads the environment first, then
     * Playbook, so a generated app installs without it by default.
     */
    public const PLAYBOOK_PACKAGE = 'waynestate/playbook';

    public const PLAYBOOK_VERSION = '^0.1';

    public const PLAYBOOK_REPO_URL = 'https://git.wayne.edu/enterprise-apps/playbook.git';

    public const PLAYBOOK_SUGGEST = "WSU's shared values; private, git.wayne.edu";

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

    public function __construct(
        private readonly string $templateDir = __DIR__ . '/../../templates/raw',
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        $this->support = new GeneratorSupport($squireDir);
    }

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers  each option the user chose, recorded in the manifest
     * @param PlaybookMode               $playbook how the app wires Playbook (see GeneratorSupport::applyPlaybook)
     *
     * @throws \InvalidArgumentException when the name does not match NAME_PATTERN, the target exists and is not empty, or the name has no valid slug
     * @throws \RuntimeException         when the template is missing or a file cannot be read or written
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Suggest): void
    {
        self::assertValidName($name);

        $slug = self::slugify($name);
        if ('' === $slug) {
            throw new \InvalidArgumentException(sprintf(
                'The app name "%s" does not produce a valid slug: use letters, numbers and dashes.',
                $name,
            ));
        }

        if (!is_dir($this->templateDir)) {
            throw new \RuntimeException(sprintf('The template directory "%s" does not exist.', $this->templateDir));
        }

        $this->support->refuseNonEmptyTarget($targetDir);

        $this->support->copyDir($this->templateDir, $targetDir, $name, $slug);
        $this->support->installSquireFiles($targetDir);
        $this->support->applyPlaybook($targetDir, $playbook);

        $answers = array_merge(['name' => $name], $answers);
        (new Manifest('raw', $answers, self::SEEDED_FILES))->write($targetDir);
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
