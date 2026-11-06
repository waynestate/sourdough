<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

use Waynestate\Sourdough\PlaybookMode;

/**
 * Bake the raw PHP + Vue SPA shape: the raw template with the raw-vue
 * overlay copied over it. PHP keeps the raw shape's HELMET, session and
 * SESAME boot and answers JSON under /api/; Vue 3, built by Vite, draws the
 * page with WayneUI's Vue components. src/Vite.php reads Vite's manifest in
 * production and points at the dev server while `npm run dev` runs.
 *
 * Everything else (the name rules, Squire's deploy files, Playbook,
 * ADVISOR, the manifest) is RawGenerator's, so the two raw shapes cannot
 * drift apart. Same dough, different crumb.
 */
final class RawVueGenerator
{
    /**
     * Raw template files this shape has no use for: Vue draws the chrome,
     * so there is no fetched public/wayne-ui.php for PHPStan to stub.
     */
    public const LEFTOVERS = [
        'stubs/wayne-ui.php',
    ];

    private readonly RawGenerator $raw;

    public function __construct(
        string $templateDir = __DIR__ . '/../../templates/raw',
        string $overlayDir = __DIR__ . '/../../templates/raw-vue',
        string $squireDir = __DIR__ . '/../../templates/squire',
    ) {
        $this->raw = new RawGenerator($templateDir, $squireDir, $overlayDir, 'raw-vue', self::LEFTOVERS);
    }

    /**
     * Generate the app named $name into $targetDir. See RawGenerator::generate().
     *
     * @param array<string, string|bool> $answers each option the user chose, recorded in the manifest
     *
     * @throws \InvalidArgumentException when the name is invalid or the target exists and is not empty
     * @throws \RuntimeException         when a template is missing or a file cannot be read or written
     */
    public function generate(string $targetDir, string $name, array $answers = [], PlaybookMode $playbook = PlaybookMode::Require, bool $advisor = true): void
    {
        $this->raw->generate($targetDir, $name, $answers, $playbook, $advisor);
    }
}
