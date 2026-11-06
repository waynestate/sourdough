<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

final class FrontPageVersionTest extends TestCase
{
    public function testFrontPageTicketMatchesTheTopChangelogVersion(): void
    {
        $root = dirname(__DIR__);

        // Same rule as the docs builder (bin/docs-site.php versionStamp()): the first
        // released "## x.y.z" heading of CHANGELOG.md, CRLF normalised first.
        $changelog = str_replace("\r\n", "\n", (string) file_get_contents($root . '/CHANGELOG.md'));
        if (preg_match('/^##\s+\[?(\d+(?:\.\d+)*)\]?\b/m', $changelog, $version) !== 1) {
            self::fail('CHANGELOG.md has no released "## x.y.z" heading to compare against.');
        }

        $page = (string) file_get_contents($root . '/site/index.html');
        if (preg_match('/<span class="ticket"[^>]*>No\. (\d+(?:\.\d+)*)<\/span>/', $page, $ticket) !== 1) {
            self::fail('site/index.html has no <span class="ticket">No. x</span> version ticket.');
        }

        self::assertSame(
            $version[1],
            $ticket[1],
            'The front page ticket says No. ' . $ticket[1] . ', but the top released heading of CHANGELOG.md is '
            . $version[1] . '. Update the ticket in site/index.html so both show the same version.',
        );
    }
}
