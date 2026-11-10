<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A placeholder such as <shape> written straight into the page's HTML is not
 * text to a browser: it is an unknown element, and it vanishes. The adopt
 * prompt once told agents to run `sourdough adopt --shape  --name  --dry-run`
 * that way, and the copy button, index.md and llms-full.txt all inherited the
 * gaps. These tests catch the cause (an element the site does not really use)
 * and the symptom (a flag left without its value), so the dough never goes
 * into the oven with holes in it.
 */
final class SitePlaceholdersTest extends TestCase
{
    private const SITE = __DIR__ . '/../site';

    /** Every element the site may write: HTML and the SVG it draws with. */
    private const ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'base', 'blockquote', 'body', 'br', 'button',
        'canvas', 'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn', 'dialog',
        'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label',
        'legend', 'li', 'link', 'main', 'mark', 'meta', 'meter', 'nav', 'noscript', 'ol', 'optgroup', 'option',
        'output', 'p', 'picture', 'pre', 'progress', 'q', 's', 'samp', 'script', 'section', 'select', 'slot',
        'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'template',
        'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr',
        // SVG
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs',
        'use', 'symbol', 'clippath', 'lineargradient', 'radialgradient', 'stop', 'mask', 'pattern', 'filter',
        'desc', 'fegaussianblur', 'feoffset', 'feblend', 'feflood', 'fecomposite', 'femerge', 'femergenode',
        'feturbulence', 'fedisplacementmap', 'fecolormatrix', 'foreignobject',
    ];

    /** The options of sourdough's commands that take a value. */
    private const VALUE_FLAGS = ['shape', 'name', 'plan', 'describe'];

    public function testTheSiteHtmlUsesOnlyRealElements(): void
    {
        $files = $this->files(['*.html', 'docs/*.html', 'docs/*/*.html']);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $html = (string) file_get_contents($file);
            // Script and style bodies and comments are not markup. Their
            // newlines stay, so the line numbers below stay true.
            $html = (string) preg_replace_callback(
                ['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is', '#<!--.*?-->#s'],
                static fn(array $block): string => str_repeat("\n", substr_count($block[0], "\n")),
                $html,
            );
            preg_match_all('#</?([A-Za-z][A-Za-z0-9-]*)#', $html, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[1] as [$tag, $offset]) {
                $line = substr_count($html, "\n", 0, $offset) + 1;
                self::assertContains(
                    strtolower($tag),
                    self::ELEMENTS,
                    sprintf('%s has <%s> near line %d: a placeholder in angle brackets must be written &lt;%s&gt;, or the browser drops it.', $this->short($file), $tag, $line, $tag),
                );
            }
        }
    }

    public function testNoCommandOnTheSiteLosesAFlagValue(): void
    {
        $files = $this->files(['index.md', 'llms.txt', 'llms-full.txt', 'docs/*.md', 'docs/*/*.md']);
        self::assertNotEmpty($files);
        $flags = implode('|', self::VALUE_FLAGS);

        foreach ($files as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $n => $line) {
                $where = sprintf('%s line %d: %s', $this->short($file), $n + 1, $line);

                self::assertStringNotContainsString('--shape  --name', $line, $where);
                // A flag followed by two spaces is the mark of a value that went missing.
                self::assertDoesNotMatchRegularExpression('/(?:^|[\s`])--[a-z][a-z-]*  \S/', $line, $where);

                // In a sourdough command, a value flag is never followed straight
                // by another flag, and never by an empty "=". (Naming a flag on
                // its own in prose, as in `sourdough new --describe`, is fine.)
                preg_match_all('/sourdough (?:new|adopt|feed|describe)\b[^`\n]*/', $line, $commands);
                foreach ($commands[0] as $command) {
                    self::assertDoesNotMatchRegularExpression('/--(?:' . $flags . ')(?:\s+--|=(?:\s|$))/', rtrim($command), $where);
                }
            }
        }
    }

    public function testTheAdoptPromptKeepsItsPlaceholders(): void
    {
        $page = (string) file_get_contents(self::SITE . '/index.html');
        $twin = (string) file_get_contents(self::SITE . '/index.md');

        self::assertStringContainsString('sourdough adopt --shape &lt;shape&gt; --name &lt;name&gt; --dry-run', $page);
        self::assertStringContainsString('sourdough adopt --shape <shape> --name <name> --dry-run', $twin);
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function files(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob(self::SITE . '/' . $pattern) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function short(string $file): string
    {
        return 'site/' . ltrim(substr($file, strlen(self::SITE)), '/');
    }
}
