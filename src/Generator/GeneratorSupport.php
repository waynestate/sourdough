<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\Permutation;
use Waynestate\Sourdough\PhpFloor;
use Waynestate\Sourdough\PlaybookAccess;
use Waynestate\Sourdough\PlaybookMode;
use Waynestate\Sourdough\ShapeSupport;

/**
 * The steps every Sourdough generator shares: refusing a non-empty target,
 * copying a template tree with placeholders replaced, installing Squire's
 * deploy files for a flavour, installing Squire's Vue .npmrc for the shapes
 * that ship a front end, and wiring Playbook and ADVISOR into the generated
 * app.
 *
 * The generators keep their own template, name and manifest knowledge and
 * delegate these steps here, so every permutation gets the same behaviour.
 * RawGenerator is the first to use them; the Laravel + Blade generator is
 * next.
 *
 * The Squire dir defaults to the checked copy in templates/squire; tests
 * can point it at a fixture.
 */
final class GeneratorSupport
{
    public function __construct(
        private readonly string $squireDir = __DIR__ . '/../../templates/squire',
    ) {}

    /**
     * Throw when the target is a file, or exists and is not empty.
     *
     * @throws \InvalidArgumentException when the target is a file, or exists and is not empty
     */
    public function refuseNonEmptyTarget(string $targetDir): void
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

    /**
     * Copy the template tree from $from to $to, replacing {{app_name}} and
     * {{app_slug}} in file contents and file names.
     *
     * @throws \RuntimeException when a directory cannot be created or a file cannot be read or written
     */
    public function copyDir(string $from, string $to, string $name, string $slug): void
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
     * Install the deploy files for $flavour from the checked copy of
     * Squire's templates into the target dir.
     *
     * The file list and the executable bit for each file come from the
     * copy's manifest.json (its "<flavour>/" keys); the appMayOwn flags
     * come from its catalog.json, the only place they live. The files are
     * copied as-is: Squire owns them, so Sourdough does not rewrite their
     * contents. A file flagged appMayOwn (the raw flavour's
     * public/index.php and .htaccess) is the app's own when the template
     * already wrote one: it is installed only where the path is free,
     * never overwritten.
     *
     * @throws \RuntimeException when the copy is missing or a file cannot be read or written
     */
    public function installSquireFiles(string $targetDir, string $flavour = 'raw'): void
    {
        $manifest = $this->readJson($this->squireDir . '/manifest.json');
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

        $prefix = $flavour . '/';
        $entries = [];
        foreach ($files as $key => $entry) {
            if (!is_string($key) || !str_starts_with($key, $prefix)) {
                continue;
            }
            $mode = '644';
            if (is_array($entry) && isset($entry['mode'])) {
                $mode = (string) $entry['mode'];
            }
            $entries[substr($key, strlen($prefix))] = $mode;
        }

        if ([] === $entries) {
            throw new \RuntimeException(sprintf('The SQUIRE copy has no "%s" kind in its manifest.', $flavour));
        }

        // The appMayOwn flags live in the catalog only.
        $catalog = $this->readJson($this->squireDir . '/catalog.json');
        $appMayOwn = [];
        $kind = $catalog[$flavour] ?? null;
        if (is_array($kind)) {
            foreach ($kind as $entry) {
                if (is_array($entry) && isset($entry['path'])) {
                    $appMayOwn[(string) $entry['path']] = (bool) ($entry['appMayOwn'] ?? false);
                }
            }
        }

        foreach ($entries as $path => $mode) {
            $source = $this->squireDir . '/' . $flavour . '/' . $path;
            $target = $targetDir . '/' . $path;

            // An appMayOwn file is the app's own when one is already there:
            // the raw template's public/index.php carries the SESAME wiring,
            // and Squire writes these only where the path is free.
            if (($appMayOwn[$path] ?? false) && is_file($target)) {
                continue;
            }

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
            chmod($target, intval($mode, 8));
        }
    }

    /**
     * Install Squire's Vue .npmrc into the target dir, so an app with a
     * front end gets the one-day npm release cooldown (min-release-age=1)
     * from the same file Squire's release script reads.
     *
     * A target without a .npmrc takes the file as-is. One that already sets
     * a min-release-age line keeps it: the app's own value wins and the file
     * is left untouched. One that has no such line gets the cooldown line
     * appended, merged in rather than overwritten.
     *
     * @throws \RuntimeException when the Squire copy is missing or a file cannot be read or written
     */
    public function installNpmrc(string $targetDir): void
    {
        $source = $this->squireDir . '/vue/.npmrc';
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('The SQUIRE template file "%s" does not exist.', $source));
        }
        $content = file_get_contents($source);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $source));
        }

        // The line to merge in comes from the file itself, so the two can
        // never drift apart.
        $cooldownLine = null;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_starts_with($line, 'min-release-age=')) {
                $cooldownLine = $line;
                break;
            }
        }
        if (null === $cooldownLine) {
            throw new \RuntimeException(sprintf('The SQUIRE vue .npmrc sets no min-release-age line ("%s").', $source));
        }

        $target = $targetDir . '/.npmrc';
        if (!is_file($target)) {
            if (false === file_put_contents($target, $content)) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $target));
            }
            chmod($target, 0644);

            return;
        }

        $existing = file_get_contents($target);
        if (false === $existing) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $target));
        }

        foreach (preg_split('/\R/', $existing) ?: [] as $line) {
            if (str_starts_with($line, 'min-release-age=')) {
                // The app already sets its own cooldown; leave the file alone.
                return;
            }
        }

        if ('' !== $existing && !str_ends_with($existing, "\n")) {
            $existing .= "\n";
        }
        if (false === file_put_contents($target, $existing . $cooldownLine . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $target));
        }
    }

    /**
     * Wire Playbook into the generated app, per the chosen mode.
     *
     * - Suggest and Require: composer.json gets a suggest for the package,
     *   .env.example lists the seven setting names, and the README gains a
     *   "WSU values (PLAYBOOK)" section.
     * - Require additionally adds the VCS repository, the package under
     *   require, and the gitlab-domains config entry that lets composer
     *   read the private host.
     * - None leaves the app untouched.
     *
     * Files the template does not ship are skipped, so a custom template
     * stays as-is. The README section is inserted before the Handbook
     * heading; a README without one is left alone.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    public function applyPlaybook(string $targetDir, PlaybookMode $mode): void
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
            $this->appendPlaybookEnvBlock($envPath, $mode);
        }

        $readmePath = $targetDir . '/README.md';
        if (is_file($readmePath)) {
            $this->insertPlaybookReadmeSection($readmePath, $mode);
        }
    }

    /**
     * Add the Playbook wiring to the app's composer.json: a suggest entry in
     * every mode, and in Require mode the VCS repository (after authors,
     * where composer.json convention puts it), the package under require,
     * the gitlab-domains config entry that lets composer read
     * git.wayne.edu with the developer's token, and a `playbook` script
     * (with its scripts-descriptions entry) that shows whether Playbook is
     * installed. Existing scripts keep their order; playbook goes last, and
     * an existing playbook entry is left alone.
     *
     * @throws \RuntimeException when the file is not valid JSON or cannot be written
     */
    public function applyPlaybookComposer(string $path, PlaybookMode $mode): void
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
            $repository = ['type' => 'vcs', 'url' => RawGenerator::PLAYBOOK_REPO_URL];
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
            $require[RawGenerator::PLAYBOOK_PACKAGE] = RawGenerator::PLAYBOOK_VERSION;
            $composer['require'] = $require;

            // Composer only sends the gitlab-token credential to hosts it
            // knows are GitLab; without this entry the private VCS
            // repository above would still fail to fetch.
            $config = $composer['config'] ?? [];
            if (!is_array($config)) {
                throw new \RuntimeException(sprintf('"%s" has no usable config section.', $path));
            }
            $domains = $config['gitlab-domains'] ?? [];
            if (!is_array($domains)) {
                throw new \RuntimeException(sprintf('"%s" has no usable gitlab-domains entry.', $path));
            }
            if (!in_array(PlaybookAccess::HOST, $domains, true)) {
                $domains[] = PlaybookAccess::HOST;
            }
            $config['gitlab-domains'] = $domains;
            $withConfig = [];
            foreach ($composer as $key => $value) {
                // The merged config lands after the require sections, so
                // the template's own config entry is not copied here.
                if ('config' === $key) {
                    continue;
                }
                $withConfig[$key] = $value;
                // Keep the usual composer.json order: config after the
                // require sections, before everything else.
                if ('require-dev' === $key || ('require' === $key && !array_key_exists('require-dev', $composer))) {
                    $withConfig['config'] = $config;
                }
            }
            if (!array_key_exists('config', $withConfig)) {
                $withConfig['config'] = $config;
            }
            $composer = $withConfig;

            // The app gets a composer playbook script that shows whether
            // Playbook is installed. Existing scripts keep their order and
            // an existing playbook entry is left alone.
            $scripts = $composer['scripts'] ?? [];
            if (!is_array($scripts)) {
                throw new \RuntimeException(sprintf('"%s" has no usable scripts section.', $path));
            }
            if (!array_key_exists('playbook', $scripts)) {
                $scripts['playbook'] = '@composer show ' . RawGenerator::PLAYBOOK_PACKAGE . ' --no-interaction';
            }
            $composer['scripts'] = $scripts;

            $descriptions = $composer['scripts-descriptions'] ?? [];
            if (!is_array($descriptions)) {
                throw new \RuntimeException(sprintf('"%s" has no usable scripts-descriptions section.', $path));
            }
            if (!array_key_exists('playbook', $descriptions)) {
                $descriptions['playbook'] = 'Check that Playbook (WSU shared values) is installed';
            }
            $composer['scripts-descriptions'] = $descriptions;
        }

        $suggest = $composer['suggest'] ?? [];
        if (!is_array($suggest)) {
            throw new \RuntimeException(sprintf('"%s" has no usable suggest section.', $path));
        }
        $suggest[RawGenerator::PLAYBOOK_PACKAGE] = RawGenerator::PLAYBOOK_SUGGEST;
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
     * setting names, empty, with a note per mode: in Suggest mode Playbook
     * supplies a value when unset, in Require mode composer install brings
     * Playbook in and the stack supplies the values automatically.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    public function appendPlaybookEnvBlock(string $path, PlaybookMode $mode): void
    {
        $existing = file_get_contents($path);
        if (false === $existing) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        $comment = PlaybookMode::Require === $mode
            ? [
                '# WSU shared values from PLAYBOOK (waynestate/playbook), private on',
                '# git.wayne.edu. Once Playbook is installed the stack supplies',
                '# these automatically; leave them empty unless your environment',
                '# needs its own value.',
            ]
            : [
                '# WSU shared values from PLAYBOOK (waynestate/playbook), private on',
                '# git.wayne.edu. The stack reads the environment first, then',
                '# PLAYBOOK, so leave these empty unless your environment needs',
                '# its own value.',
            ];

        $lines = [''];
        foreach ($comment as $line) {
            $lines[] = $line;
        }
        foreach (RawGenerator::PLAYBOOK_ENV_VARS as $name) {
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
    public function insertPlaybookReadmeSection(string $path, PlaybookMode $mode): void
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

        $updated = substr($readme, 0, $pos) . implode("\n", $this->playbookReadmeLines($mode)) . "\n\n" . substr($readme, $pos);
        if (false === file_put_contents($path, $updated)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * The "WSU values (PLAYBOOK)" README section, line by line, per mode.
     *
     * @return list<string>
     */
    public function playbookReadmeLines(PlaybookMode $mode): array
    {
        if (PlaybookMode::Require === $mode) {
            return $this->requireReadmeLines();
        }

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
     * The Require-mode section: Playbook is a prerequisite of composer
     * install, so the access steps come from PlaybookAccess::howTo(),
     * rendered into fenced code blocks here. The two never drift apart
     * because this reads the same lines the command prints.
     *
     * @return list<string>
     */
    private function requireReadmeLines(): array
    {
        $howTo = PlaybookAccess::howTo();
        // The last line is the closing sentence ("Then run this command
        // again."), not a command for the last block.
        $closing = (string) array_pop($howTo);

        $intro = [];
        $steps = [];
        $blocks = [];

        foreach ($howTo as $line) {
            if ('Windows PowerShell:' === $line || 'Linux or macOS:' === $line) {
                $blocks[] = [
                    'label' => $line,
                    'lang' => 'Windows PowerShell:' === $line ? 'powershell' : 'bash',
                    'commands' => [],
                ];
                continue;
            }

            if ([] === $blocks) {
                if ([] === $intro) {
                    $intro[] = $line;
                } else {
                    $steps[] = $line;
                }
                continue;
            }

            $last = count($blocks) - 1;
            $blocks[$last]['commands'][] = $line;
        }

        $lines = ['## WSU values (PLAYBOOK)', ''];
        foreach ($intro as $paragraph) {
            foreach (self::wrapLine($paragraph) as $wrapped) {
                $lines[] = $wrapped;
            }
            $lines[] = '';
        }

        $lines[] = 'You need read access before `composer install` works.';
        $lines[] = '';

        foreach ($steps as $step) {
            foreach (self::wrapLine($step) as $wrapped) {
                $lines[] = $wrapped;
            }
            $lines[] = '';
        }

        foreach ($blocks as $block) {
            $lines[] = $block['label'];
            $lines[] = '';
            $lines[] = '```' . $block['lang'];
            foreach ($block['commands'] as $command) {
                $lines[] = $command;
            }
            $lines[] = '```';
            $lines[] = '';
        }

        foreach (self::wrapLine($closing) as $wrapped) {
            $lines[] = $wrapped;
        }

        return $lines;
    }

    /**
     * Wrap a prose line at 72 columns, the width the other README sections
     * use. Commands are never wrapped: they are pasted verbatim.
     *
     * @return list<string>
     */
    private static function wrapLine(string $line): array
    {
        return preg_split('/\R/', (string) wordwrap($line, 72)) ?: [];
    }

    /**
     * Wire ADVISOR into the generated app, or remove the wiring when the
     * user passed --no-advisor.
     *
     * On: composer.json carries waynestate/advisor under require-dev,
     * .gitignore ignores .mcp.json, and the README gains an "ADVISOR"
     * section before the Handbook heading. Off: the require-dev entry and
     * the .gitignore line are removed again, so the template shipping the
     * wiring by default stays clean either way.
     *
     * Files the template does not ship are skipped, so a custom template
     * stays as-is. A README without a Handbook heading is left alone.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    public function applyAdvisor(string $targetDir, bool $advisor): void
    {
        $composerPath = $targetDir . '/composer.json';
        if (is_file($composerPath)) {
            $this->applyAdvisorComposer($composerPath, $advisor);
        }

        $gitignorePath = $targetDir . '/.gitignore';
        if (is_file($gitignorePath)) {
            $this->applyAdvisorGitignore($gitignorePath, $advisor);
        }

        $readmePath = $targetDir . '/README.md';
        if ($advisor && is_file($readmePath)) {
            $this->insertAdvisorReadmeSection($readmePath);
        }
    }

    /**
     * Add waynestate/advisor to the app's composer.json require-dev, or
     * remove it again. The file is only rewritten when something changes.
     *
     * @throws \RuntimeException when the file is not valid JSON or cannot be written
     */
    public function applyAdvisorComposer(string $path, bool $advisor): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }
        $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($composer)) {
            throw new \RuntimeException(sprintf('"%s" is not a composer.json object.', $path));
        }

        if ($advisor) {
            $requireDev = $composer['require-dev'] ?? [];
            if (!is_array($requireDev)) {
                throw new \RuntimeException(sprintf('"%s" has no usable require-dev section.', $path));
            }
            $requireDev[RawGenerator::ADVISOR_PACKAGE] = RawGenerator::ADVISOR_VERSION;
            $composer['require-dev'] = $requireDev;
        } elseif (isset($composer['require-dev'][RawGenerator::ADVISOR_PACKAGE])) {
            unset($composer['require-dev'][RawGenerator::ADVISOR_PACKAGE]);
        } else {
            return;
        }

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('Could not encode "%s".', $path));
        }
        if (false === file_put_contents($path, $encoded . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Keep .gitignore in step with the ADVISOR wiring: on, make sure it
     * ignores .mcp.json (the client config names local paths, so each
     * developer keeps their own); off, drop the line again. The file is
     * only rewritten when something changes.
     *
     * The Laravel shapes ignore /.mcp.json for Laravel Boost whatever the
     * ADVISOR answer is (gitignore-additions.txt): that anchored line already
     * covers ADVISOR, so on adds nothing next to it, and off leaves it alone,
     * because it belongs to Boost.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    public function applyAdvisorGitignore(string $path, bool $advisor): void
    {
        $existing = file_get_contents($path);
        if (false === $existing) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        $lines = preg_split('/\R/', $existing) ?: [];
        $hasLine = false;
        $coveredByBoost = false;
        foreach ($lines as $line) {
            if ('.mcp.json' === trim($line)) {
                $hasLine = true;
            }
            if ('/.mcp.json' === trim($line)) {
                $coveredByBoost = true;
            }
        }

        if ($advisor && ($hasLine || $coveredByBoost)) {
            return;
        }

        if ($advisor) {
            if ('' !== $existing && !str_ends_with($existing, "\n")) {
                $existing .= "\n";
            }
            $existing .= ".mcp.json\n";
        } elseif ($hasLine) {
            $kept = array_values(array_filter(
                $lines,
                static fn(string $line): bool => '.mcp.json' !== trim($line),
            ));
            $existing = implode("\n", $kept);
            if ('' !== $existing && !str_ends_with($existing, "\n")) {
                $existing .= "\n";
            }
        } else {
            return;
        }

        if (false === file_put_contents($path, $existing)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Insert the "ADVISOR" section into the README before the Handbook
     * heading. A README that already has the section, or one without the
     * heading, is left alone.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    public function insertAdvisorReadmeSection(string $path): void
    {
        $readme = file_get_contents($path);
        if (false === $readme) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        if (false !== strpos($readme, '## ADVISOR')) {
            return;
        }

        $pos = strpos($readme, '## Handbook');
        if (false === $pos) {
            return;
        }

        $updated = substr($readme, 0, $pos) . implode("\n", $this->advisorReadmeLines()) . "\n\n" . substr($readme, $pos);
        if (false === file_put_contents($path, $updated)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * The "ADVISOR" README section, line by line.
     *
     * @return list<string>
     */
    public function advisorReadmeLines(): array
    {
        return [
            '## ADVISOR',
            '',
            'This app ships waynestate/advisor in require-dev. ADVISOR is the',
            'stack\'s MCP server: it gives your coding agent read-only tools over',
            'the CREED standards, the version policy and the rest of the stack,',
            'and it answers from bundled data with the source and date named,',
            'not from what the model remembers.',
            '',
            'After `composer install`, wire it into your client:',
            '',
            '```bash',
            'php vendor/bin/advisor install --client=claude --write',
            '```',
            '',
            'Other clients take the same shape: `--client=cursor`,',
            '`--client=vscode`, `--client=warriorgpt`. If the server never shows',
            'up, run `php vendor/bin/advisor doctor`.',
            '',
            'The `.mcp.json` the command writes is git-ignored on purpose: a',
            'client config starts a program for whoever opens the project, so',
            'each developer keeps their own.',
        ];
    }

    /**
     * Write the PHP floor from CREED's policy (PhpFloor) into the baked app:
     * composer.json's require.php and config.platform.php, the
     * php_versions matrix and the "requires PHP" comment in .gitlab-ci.yml,
     * and the "test suite on PHP ..." line in the README.
     *
     * The templates carry the pinned fallback (PhpFloor::FALLBACK_*) as
     * plain literals, so they stay valid JSON and YAML; this step swaps in
     * the policy's answer. A file is only rewritten when its text changes,
     * so a floor that matches the fallback leaves the loaf byte for byte as
     * the template wrote it.
     *
     * @throws \RuntimeException when a file cannot be read, parsed or written
     */
    public function applyPhpFloor(string $targetDir, PhpFloor $floor): void
    {
        $composerPath = $targetDir . '/composer.json';
        if (is_file($composerPath)) {
            $this->applyPhpFloorComposer($composerPath, $floor);
        }

        $ciPath = $targetDir . '/.gitlab-ci.yml';
        if (is_file($ciPath)) {
            $this->rewrite($ciPath, static function (string $content) use ($floor): string {
                $content = (string) preg_replace(
                    '/^(\s*php_versions:\s*)\[[^\]\n]*\]/m',
                    '${1}' . $floor->yamlList(),
                    $content,
                );

                return (string) preg_replace(
                    '/requires PHP \^\d+\.\d+, so the test matrix starts at \d+\.\d+\./',
                    sprintf('requires PHP %s, so the test matrix starts at %s.', $floor->constraint(), $floor->floor),
                    $content,
                );
            });
        }

        $readmePath = $targetDir . '/README.md';
        if (is_file($readmePath)) {
            $fallback = 'the test suite on PHP ' . PhpFloor::proseOf(PhpFloor::FALLBACK_VERSIONS);
            $this->rewrite($readmePath, static fn(string $content): string => str_replace(
                $fallback,
                'the test suite on PHP ' . $floor->prose(),
                $content,
            ));
        }
    }

    /**
     * Write the support line into the baked README's "## Support" section
     * (BEST_PRACTICES 2.4): the same line `sourdough new` prints, with the
     * shape's status, owner and the end-of-security date its app inherits
     * from CREED's policy. A target without a README, or one whose README
     * carries no {{support_line}} placeholder, is left alone.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    public function applySupportLine(string $targetDir, Permutation $permutation, PhpFloor $floor): void
    {
        $readmePath = $targetDir . '/README.md';
        if (!is_file($readmePath) || !str_contains((string) file_get_contents($readmePath), '{{support_line}}')) {
            return;
        }

        $line = ShapeSupport::load()->line($permutation, $floor);
        $this->rewrite($readmePath, static fn(string $content): string => str_replace('{{support_line}}', $line, $content));
    }

    /**
     * Set require.php and config.platform.php in place (the keys keep their
     * position; a missing platform pin is added at the end of config).
     *
     * @throws \RuntimeException when the file is not a composer.json object or cannot be written
     */
    private function applyPhpFloorComposer(string $path, PhpFloor $floor): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }
        $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($composer)) {
            throw new \RuntimeException(sprintf('"%s" is not a composer.json object.', $path));
        }

        $require = $composer['require'] ?? [];
        $config = $composer['config'] ?? [];
        if (!is_array($require) || !is_array($config)) {
            throw new \RuntimeException(sprintf('"%s" has no usable require or config section.', $path));
        }
        $platform = $config['platform'] ?? [];
        if (!is_array($platform)) {
            throw new \RuntimeException(sprintf('"%s" has no usable config.platform section.', $path));
        }

        if (($require['php'] ?? null) === $floor->constraint() && ($platform['php'] ?? null) === $floor->platform()) {
            return;
        }

        $require['php'] = $floor->constraint();
        $platform['php'] = $floor->platform();
        $config['platform'] = $platform;
        $composer['require'] = $require;
        $composer['config'] = $config;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException(sprintf('Could not encode "%s".', $path));
        }
        if (false === file_put_contents($path, $encoded . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Run $edit over a text file and write it back only when it changed.
     *
     * @param callable(string): string $edit
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function rewrite(string $path, callable $edit): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }
        $changed = $edit($content);
        if ($changed !== $content && false === file_put_contents($path, $changed)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Read a JSON file and return it as an array.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the file is missing, unreadable or not valid JSON
     */
    public function readJson(string $path): array
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
