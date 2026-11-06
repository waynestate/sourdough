<?php

declare(strict_types=1);

namespace App;

/**
 * Prints the tags that load the Vue app, the way Vite's backend integration
 * guide asks a backend to: from the dev server while `npm run dev` runs,
 * from public/build/manifest.json once `npm run build` has run.
 *
 * Laravel ships this as @vite; a raw PHP app gets this one small class
 * instead, so nobody hand-writes hashed file names. Every tag carries
 * HELMET's per-request nonce, because the Content-Security-Policy admits
 * nothing without it.
 *
 * `npm run dev` writes public/hot with the dev server's origin and removes
 * it again on exit (see vite.config.js). A leftover hot file in production
 * is ignored: the front controller turns the dev server off there.
 */
final class Vite
{
    public function __construct(
        private readonly string $publicDir,
        private readonly bool $useDevServer = true,
        private readonly string $buildDir = 'build',
    ) {}

    /**
     * The dev server's origin from public/hot, or null when the dev server
     * is off, not running, or the hot file holds anything but a bare
     * http(s)://host[:port] origin.
     */
    public function devOrigin(): ?string
    {
        if (!$this->useDevServer) {
            return null;
        }

        $hot = $this->publicDir . '/hot';
        if (!is_file($hot)) {
            return null;
        }

        $origin = rtrim(trim((string) file_get_contents($hot)), '/');

        return 1 === preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#', $origin) ? $origin : null;
    }

    /**
     * The script, stylesheet and preload tags for one entry point, such as
     * resources/js/app.js.
     *
     * @throws \RuntimeException when there is no dev server and no build, or the build does not know the entry
     */
    public function tags(string $entry, string $nonce): string
    {
        $origin = $this->devOrigin();
        if (null !== $origin) {
            return $this->script($origin . '/@vite/client', $nonce)
                . $this->script($origin . '/' . ltrim($entry, '/'), $nonce);
        }

        $manifest = $this->manifest();
        if (!isset($manifest[$entry])) {
            throw new \RuntimeException(sprintf(
                'The Vite build has no entry "%s". Check rollupOptions.input in vite.config.js, then run npm run build.',
                $entry,
            ));
        }

        $tags = '';
        foreach ($this->stylesheets($manifest, $entry) as $css) {
            $tags .= sprintf('<link rel="stylesheet" href="%s" nonce="%s">', $this->url($css), $this->escape($nonce)) . "\n";
        }
        foreach ($this->imports($manifest, $entry) as $chunk) {
            $tags .= sprintf('<link rel="modulepreload" href="%s" nonce="%s">', $this->url($chunk), $this->escape($nonce)) . "\n";
        }

        return $tags . $this->script($this->url($manifest[$entry]['file']), $nonce);
    }

    /**
     * Add the dev server to a Content-Security-Policy while it runs: its
     * scripts, styles, fonts and images, and its hot-reload websocket. With
     * no dev server the policy comes back unchanged.
     *
     * @param array<string, list<string>> $policy HELMET's policy, directive by directive
     *
     * @return array<string, list<string>>
     */
    public function withDevServer(array $policy): array
    {
        $origin = $this->devOrigin();
        if (null === $origin) {
            return $policy;
        }

        foreach (['script-src', 'style-src', 'style-src-elem', 'font-src', 'img-src', 'connect-src'] as $directive) {
            if (isset($policy[$directive])) {
                $policy[$directive][] = $origin;
            }
        }
        if (isset($policy['connect-src'])) {
            $policy['connect-src'][] = (string) preg_replace('#^http#', 'ws', $origin);
        }

        return $policy;
    }

    /**
     * @return array<string, array{file: string, css?: list<string>, imports?: list<string>}>
     */
    private function manifest(): array
    {
        $path = $this->publicDir . '/' . $this->buildDir . '/manifest.json';
        if (!is_file($path)) {
            throw new \RuntimeException('No Vite build and no dev server: run npm run build, or npm run dev while you work.');
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest)) {
            throw new \RuntimeException(sprintf('%s is not valid JSON: run npm run build again.', $path));
        }

        /** @var array<string, array{file: string, css?: list<string>, imports?: list<string>}> $manifest */
        return $manifest;
    }

    /**
     * The chunks an entry imports, all the way down, each once.
     *
     * @param array<string, array{file: string, css?: list<string>, imports?: list<string>}> $manifest
     * @param array<string, true>                                                             $seen
     *
     * @return list<string>
     */
    private function imports(array $manifest, string $key, array &$seen = []): array
    {
        $files = [];
        foreach ($manifest[$key]['imports'] ?? [] as $import) {
            if (isset($seen[$import]) || !isset($manifest[$import])) {
                continue;
            }
            $seen[$import] = true;
            $files[] = $manifest[$import]['file'];
            array_push($files, ...$this->imports($manifest, $import, $seen));
        }

        return $files;
    }

    /**
     * The stylesheets of an entry and of every chunk it imports, each once.
     *
     * @param array<string, array{file: string, css?: list<string>, imports?: list<string>}> $manifest
     *
     * @return list<string>
     */
    private function stylesheets(array $manifest, string $entry): array
    {
        $css = $manifest[$entry]['css'] ?? [];
        $seen = [];
        $this->imports($manifest, $entry, $seen);
        foreach (array_keys($seen) as $import) {
            array_push($css, ...($manifest[$import]['css'] ?? []));
        }

        return array_values(array_unique($css));
    }

    private function url(string $file): string
    {
        return $this->escape('/' . $this->buildDir . '/' . $file);
    }

    private function script(string $src, string $nonce): string
    {
        return sprintf('<script type="module" src="%s" nonce="%s"></script>', $this->escape($src), $this->escape($nonce)) . "\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
