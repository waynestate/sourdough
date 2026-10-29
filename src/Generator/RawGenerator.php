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

    public function __construct(
        private readonly string $templateDir = __DIR__ . '/../../templates/raw',
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
    ) {}

    /**
     * Generate the app named $name into $targetDir.
     *
     * @param array<string, string|bool> $answers  each option the user chose, recorded in the manifest
     * @param PlaybookMode               $playbook how the app wires Playbook (see applyPlaybook)
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

        $this->refuseNonEmptyTarget($targetDir);

        $this->copyDir($this->templateDir, $targetDir, $name, $slug);
        $this->installSquireFiles($targetDir);
        $this->applyPlaybook($targetDir, $playbook);

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

    private function refuseNonEmptyTarget(string $targetDir): void
    {
        if (is_file($targetDir)) {
            throw new \InvalidArgumentException(sprintf('The target "%s" is a file, not a directory.', $targetDir));
        }

        if (!is_dir($targetDir)) {
            return;
        }

        foreach (scandir($targetDir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                throw new \InvalidArgumentException(sprintf(
                    'The target directory "%s" already exists and is not empty.',
                    $targetDir,
                ));
            }
        }
    }

    private function copyDir(string $from, string $to, string $name, string $slug): void
    {
        if (!is_dir($to)) {
            if (!mkdir($to, 0777, true) && !is_dir($to)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $to));
            }
        }

        $replacements = ['{{app_name}}' => $name, '{{app_slug}}' => $slug];

        foreach (scandir($from) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $fromPath = $from . DIRECTORY_SEPARATOR . $item;
            $toPath = $to . DIRECTORY_SEPARATOR . strtr($item, $replacements);

            if (is_dir($fromPath)) {
                $this->copyDir($fromPath, $toPath, $name, $slug);
                continue;
            }

            $content = file_get_contents($fromPath);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $fromPath));
            }

            if (false === file_put_contents($toPath, strtr($content, $replacements))) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $toPath));
            }
        }
    }

    /**
     * Install the raw deploy files from the checked copy of Squire's
     * templates into the target dir.
     *
     * The file list and target paths come from the copy's catalog.json (the
     * "raw" kind); the executable bit for each file comes from its mode in
     * the copy's manifest.json. The files are copied as-is: Squire owns
     * them, so Sourdough does not rewrite their contents.
     *
     * @throws \RuntimeException when the copy is missing or a file cannot be read or written
     */
    private function installSquireFiles(string $targetDir): void
    {
        $catalog = $this->readJson($this->squireDir . '/catalog.json');
        if (!isset($catalog['raw']) || !is_array($catalog['raw'])) {
            throw new \RuntimeException('The SQUIRE copy has no "raw" kind in its catalog.');
        }

        $manifest = $this->readJson($this->squireDir . '/manifest.json');
        $modes = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

        foreach ($catalog['raw'] as $entry) {
            $path = (string) $entry['path'];
            $source = $this->squireDir . '/raw/' . $path;
            $target = $targetDir . '/' . $path;

            if (!is_file($source)) {
                throw new \RuntimeException(sprintf('The SQUIRE template file "%s" does not exist.', $source));
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dir));
            }

            $content = file_get_contents($source);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $source));
            }
            if (false === file_put_contents($target, $content)) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $target));
            }

            // The mode is an octal string ("644", "755"). chmod is a no-op for
            // the executable bit on Windows, where the bit is not tracked; the
            // mode is still recorded in the manifest, so this is best-effort.
            $mode = (string) ($modes['raw/' . $path]['mode'] ?? '644');
            chmod($target, intval($mode, 8));
        }
    }

    /**
     * Wire Playbook into the generated app, per the chosen mode.
     *
     * - Suggest and Require: composer.json gets a suggest for the package,
     *   .env.example lists the seven setting names, and the README gains a
     *   "WSU values (PLAYBOOK)" section.
     * - Require additionally adds the VCS repository and the package under
     *   require.
     * - None leaves the app untouched.
     *
     * Files the template does not ship are skipped, so a custom template
     * stays as-is. The README section is inserted before the Handbook
     * heading; a README without one is left alone.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    private function applyPlaybook(string $targetDir, PlaybookMode $mode): void
    {
        if (PlaybookMode::None === $mode) {
            return;
        }

        $composerPath = $targetDir . '/composer.json';
        if (is_file($composerPath)) {
            $this->applyPlaybookComposer($composerPath, $mode);
        }

        $envPath = $targetDir . '/.env.example';
        if (is_file($envPath)) {
            $this->appendPlaybookEnvBlock($envPath);
        }

        $readmePath = $targetDir . '/README.md';
        if (is_file($readmePath)) {
            $this->insertPlaybookReadmeSection($readmePath);
        }
    }

    /**
     * Add the Playbook wiring to the app's composer.json: a suggest entry in
     * every mode, and in Require mode the VCS repository (after authors,
     * where composer.json convention puts it) plus the package under
     * require.
     *
     * @throws \RuntimeException when the file is not valid JSON or cannot be written
     */
    private function applyPlaybookComposer(string $path, PlaybookMode $mode): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }
        $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($composer)) {
            throw new \RuntimeException(sprintf('"%s" is not a composer.json object.', $path));
        }

        if (PlaybookMode::Require === $mode) {
            $repository = ['type' => 'vcs', 'url' => self::PLAYBOOK_REPO_URL];
            $withRepositories = [];
            foreach ($composer as $key => $value) {
                $withRepositories[$key] = $value;
                if ('authors' === $key) {
                    $withRepositories['repositories'] = [$repository];
                }
            }
            if (!array_key_exists('repositories', $withRepositories)) {
                $withRepositories['repositories'] = [$repository];
            }
            $composer = $withRepositories;

            $require = $composer['require'] ?? [];
            if (!is_array($require)) {
                throw new \RuntimeException(sprintf('"%s" has no usable require section.', $path));
            }
            $require[self::PLAYBOOK_PACKAGE] = self::PLAYBOOK_VERSION;
            $composer['require'] = $require;
        }

        $suggest = $composer['suggest'] ?? [];
        if (!is_array($suggest)) {
            throw new \RuntimeException(sprintf('"%s" has no usable suggest section.', $path));
        }
        $suggest[self::PLAYBOOK_PACKAGE] = self::PLAYBOOK_SUGGEST;
        $composer['suggest'] = $suggest;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('Could not encode "%s".', $path));
        }
        if (false === file_put_contents($path, $encoded . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Append the commented Playbook block to .env.example: each of the seven
     * setting names, empty, with the note that Playbook supplies it when
     * unset.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function appendPlaybookEnvBlock(string $path): void
    {
        $existing = file_get_contents($path);
        if (false === $existing) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        $lines = [
            '',
            '# WSU shared values from PLAYBOOK (waynestate/playbook), private on',
            '# git.wayne.edu. The stack reads the environment first, then',
            '# PLAYBOOK, so leave these empty unless your environment needs',
            '# its own value.',
        ];
        foreach (self::PLAYBOOK_ENV_VARS as $name) {
            $lines[] = sprintf('# %s= # from PLAYBOOK when unset', $name);
        }

        if (false === file_put_contents($path, $existing . implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Insert the "WSU values (PLAYBOOK)" section into the README before the
     * Handbook heading. A README without that heading is left alone.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function insertPlaybookReadmeSection(string $path): void
    {
        $readme = file_get_contents($path);
        if (false === $readme) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        $marker = '## Handbook';
        $pos = strpos($readme, $marker);
        if (false === $pos) {
            return;
        }

        $updated = substr($readme, 0, $pos) . implode("\n", self::playbookReadmeLines()) . "\n\n" . substr($readme, $pos);
        if (false === file_put_contents($path, $updated)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * The "WSU values (PLAYBOOK)" README section, line by line.
     *
     * @return list<string>
     */
    private static function playbookReadmeLines(): array
    {
        return [
            '## WSU values (PLAYBOOK)',
            '',
            'The stack reads each value from the environment first, then from',
            'PLAYBOOK, so most apps need none of these. To fetch the shared',
            'values:',
            '',
            'curl (Linux, macOS):',
            '',
            '```bash',
            'curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook',
            '```',
            '',
            'PowerShell (Windows):',
            '',
            '```powershell',
            'Invoke-WebRequest -Headers @{ \'PRIVATE-TOKEN\' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook',
            '```',
            '',
            'Clone:',
            '',
            '```bash',
            'git clone https://git.wayne.edu/enterprise-apps/playbook.git',
            '```',
            '',
            'Or install it: `composer require waynestate/playbook` after adding the VCS repository (token in `auth.json`).',
            '',
            'A fetched `.env.playbook` is plain `KEY=value` lines: copy the ones you need into `.env`.',
            '',
            'PLAYBOOK is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('The SQUIRE copy file "%s" does not exist.', $path));
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
