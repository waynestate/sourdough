<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Apply the app's own pinned Docker setup to a generated app dir.
 *
 * Writes a two-target Dockerfile (dev and prod) whose base images are
 * pinned by manifest-list digest, a compose.yaml for local work (with a
 * vite service when $withVite is true), a .dockerignore, the PHP ini
 * files the image copies in, the composer dev-up/dev-down/dev-shell
 * scripts, the database settings in .env.example, and a README section
 * that tells the developer how to run it.
 *
 * The file bodies live in templates/docker/; this class reads them and
 * writes the result into $dir. It runs no process: everything it does is
 * deterministic file work, so the same setup lands on Windows, macOS and
 * Linux.
 */
final class DockerScaffold
{
    private const TEMPLATES_DIR = __DIR__ . '/../../templates/docker';

    /**
     * The composer scripts the scaffold adds, with their descriptions, in
     * the order they are appended.
     *
     * @var array<string, array{list<string>|string, string}>
     */
    private const COMPOSER_ADDITIONS = [
        'dev-up' => [
            ['Composer\Config::disableProcessTimeout', 'docker compose up --build'],
            'Build and start the local Docker setup on http://localhost:8000.',
        ],
        'dev-down' => [
            'docker compose down',
            'Stop the local Docker setup.',
        ],
        'dev-shell' => [
            ['Composer\Config::disableProcessTimeout', 'docker compose exec app bash'],
            'Open a shell in the app container.',
        ],
    ];

    /**
     * @throws \RuntimeException when a file cannot be read or written, when
     *         composer.json is missing or invalid JSON, or when Dockerfile,
     *         compose.yaml or .dockerignore already exists in $dir
     */
    public function apply(string $dir, bool $withVite): void
    {
        foreach (['Dockerfile', 'compose.yaml', '.dockerignore'] as $name) {
            if (is_file($dir . '/' . $name)) {
                throw new \RuntimeException(sprintf(
                    'The file "%s" already exists in "%s"; refusing to overwrite it.',
                    $name,
                    $dir,
                ));
            }
        }

        $this->writeFile($dir . '/Dockerfile', $this->readTemplate('Dockerfile'));

        $compose = $this->readTemplate('compose.yaml');
        if ($withVite) {
            // Insert the vite service just before the top-level volumes:
            // line, so it sits with the other services.
            $part = rtrim($this->readTemplate('compose.vite.yaml.part'), "\r\n");
            $pos = strrpos($compose, "\nvolumes:");
            if (false === $pos) {
                throw new \RuntimeException('The compose.yaml template has no top-level "volumes:" section to insert the vite service before.');
            }
            $compose = substr($compose, 0, $pos + 1) . $part . "\n" . substr($compose, $pos + 1);
        }
        $this->writeFile($dir . '/compose.yaml', $compose);

        $this->writeFile($dir . '/.dockerignore', $this->readTemplate('dockerignore'));

        $dockerDir = $dir . '/docker';
        if (!is_dir($dockerDir) && !mkdir($dockerDir, 0777, true) && !is_dir($dockerDir)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $dockerDir));
        }
        $this->writeFile($dockerDir . '/php.ini', $this->readTemplate('php.ini'));
        $this->writeFile($dockerDir . '/opcache.ini', $this->readTemplate('opcache.ini'));

        $this->applyComposerScripts($dir . '/composer.json');
        $this->applyEnvExample($dir . '/.env.example');
        $this->appendReadmeSection($dir . '/README.md');
    }

    /**
     * Add the dev-up, dev-down and dev-shell scripts (and their
     * descriptions) to composer.json. Existing scripts keep their order;
     * the three land at the end, and an existing entry is left alone.
     *
     * They are not named up: Composer treats `composer up` as `update`.
     *
     * @throws \RuntimeException when composer.json is missing, is not valid
     *         JSON or cannot be written
     */
    private function applyComposerScripts(string $path): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('The file "%s" does not exist or could not be read.', $path));
        }
        $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($composer)) {
            throw new \RuntimeException(sprintf('"%s" is not valid JSON.', $path));
        }

        $encoded = $this->redoComposer($composer);
        if (null === $encoded) {
            return;
        }
        if (false === file_put_contents($path, $encoded)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * The pre-scaffold composer data behind $content: the dev-up, dev-down
     * and dev-shell scripts and descriptions removed, and a section the
     * removal empties dropped, since the file Sourdough 0.1.6 hashed never
     * had them. Null when $content is not a JSON object with object script
     * sections: nothing to verify.
     *
     * @return array<string, mixed>|null
     */
    public function undoComposer(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        foreach (['scripts', 'scripts-descriptions'] as $section) {
            $value = $decoded[$section] ?? null;
            if (null === $value) {
                continue;
            }
            if (!is_array($value)) {
                return null;
            }
            foreach (array_keys(self::COMPOSER_ADDITIONS) as $name) {
                unset($value[$name]);
            }
            if ([] === $value) {
                unset($decoded[$section]);
            } else {
                $decoded[$section] = $value;
            }
        }

        return $decoded;
    }

    /**
     * The bytes the scaffold writes for $composer: the dev-up, dev-down and
     * dev-shell scripts and descriptions appended, existing entries left
     * alone, pretty-printed with unescaped slashes and unicode and a
     * trailing newline. Null when $composer already carries every entry:
     * the scaffold would leave the file alone. The inverse of
     * undoComposer() on the data it returned, so feed can rebuild the exact
     * file Sourdough 0.1.6 left behind.
     *
     * @param array<string, mixed> $composer
     *
     * @throws \RuntimeException when a scripts section is not an object or the data will not encode
     */
    public function redoComposer(array $composer): ?string
    {
        $scripts = $composer['scripts'] ?? [];
        if (!is_array($scripts)) {
            throw new \RuntimeException('composer.json has no usable scripts section.');
        }
        $descriptions = $composer['scripts-descriptions'] ?? [];
        if (!is_array($descriptions)) {
            throw new \RuntimeException('composer.json has no usable scripts-descriptions section.');
        }

        $changed = false;
        foreach (self::COMPOSER_ADDITIONS as $name => [$command, $description]) {
            if (!array_key_exists($name, $scripts)) {
                $scripts[$name] = $command;
                $changed = true;
            }
            if (!array_key_exists($name, $descriptions)) {
                $descriptions[$name] = $description;
                $changed = true;
            }
        }
        if (!$changed) {
            return null;
        }

        $composer['scripts'] = $scripts;
        $composer['scripts-descriptions'] = $descriptions;

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException('Could not encode composer.json.');
        }

        return $encoded . "\n";
    }

    /**
     * Set the Docker database and port settings in .env.example: each key
     * replaces its existing line (commented out or not), else the line is
     * appended. The file is created when absent. LF line endings; every
     * other line stays untouched.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function applyEnvExample(string $path): void
    {
        $existing = is_file($path) ? file_get_contents($path) : false;
        if (false === $existing) {
            $existing = '';
        }

        $settings = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'laravel',
            'DB_USERNAME' => 'app',
            'DB_PASSWORD' => 'password',
            'APP_PORT' => '8000',
            'VITE_PORT' => '5173',
        ];

        $lines = '' === $existing ? [] : (preg_split('/\R/', $existing) ?: []);
        $appended = [];

        foreach ($settings as $key => $value) {
            $pattern = '/^#?\s*' . preg_quote($key, '/') . '=/';
            $replaced = false;
            foreach ($lines as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $lines[$i] = $key . '=' . $value;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $appended[] = $key . '=' . $value;
            }
        }

        if ([] !== $appended) {
            if ('' !== $existing && !str_ends_with($existing, "\n")) {
                $lines[] = '';
            }
            foreach ($appended as $line) {
                $lines[] = $line;
            }
        }

        // Drop trailing blank lines so a file that already ends with a
        // newline does not gain one; the final newline is added below.
        while ([] !== $lines && '' === $lines[count($lines) - 1]) {
            array_pop($lines);
        }

        if (false === file_put_contents($path, implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Append the "Run it locally with Docker" section to README.md once.
     * The README is created when absent.
     *
     * @throws \RuntimeException when the file cannot be read or written
     */
    private function appendReadmeSection(string $path): void
    {
        $existing = is_file($path) ? file_get_contents($path) : false;
        if (false === $existing) {
            $existing = '';
        }

        $marker = '## Run it locally with Docker';
        if (str_contains($existing, $marker)) {
            return;
        }

        $section = rtrim($this->readTemplate('README-section.md'), "\r\n");
        $updated = $existing;
        if ('' !== $updated) {
            if (!str_ends_with($updated, "\n")) {
                $updated .= "\n";
            }
            $updated .= "\n";
        }
        $updated .= $section . "\n";

        if (false === file_put_contents($path, $updated)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }

    /**
     * Read one of the checked-in Docker template files.
     *
     * @throws \RuntimeException when the template is missing or unreadable
     */
    private function readTemplate(string $name): string
    {
        $path = self::TEMPLATES_DIR . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('The Docker template file "%s" does not exist.', $path));
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Could not read "%s".', $path));
        }

        return $content;
    }

    /**
     * @throws \RuntimeException when the file cannot be written
     */
    private function writeFile(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $path));
        }
    }
}
