<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The smoke jobs' tools come from one cached, checksummed download: Composer
 * 2.10.3 and Node 22.23.4 land in .cache/smoke-tools/ once, each checked by
 * sha256, and every smoke job reuses them. A before_script that goes back to
 * piping the Composer installer into php, or to a Node tarball nobody has
 * verified, fails here before five pipelines pay the download again. A
 * starter that is fed well rises without a fuss.
 */
final class SmokeToolsCacheTest extends TestCase
{
    private const COMPOSER_SHA256 = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6';

    private const NODE_X64_SHA256 = '1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce';

    private const NODE_ARM64_SHA256 = '6287aa46b6c375d816e63ae66d024fbbfd70404825aabdfdf898a970871d2f4b';

    public function testTheSmokeToolsAreCachedUnderOneKey(): void
    {
        $ci = self::read();

        self::assertStringContainsString('key: smoke-tools-composer-2.10.3-node-22.23.4', $ci);
        self::assertStringContainsString('paths: [.cache/smoke-tools/]', $ci);
        self::assertStringContainsString('when: always', $ci);
    }

    public function testEveryToolDownloadIsCheckedBySha256(): void
    {
        $ci = self::read();

        self::assertStringContainsString(self::COMPOSER_SHA256, $ci);
        self::assertStringContainsString(self::NODE_X64_SHA256, $ci);
        self::assertStringContainsString(self::NODE_ARM64_SHA256, $ci);
        self::assertStringContainsString('sha256sum -c', $ci);
    }

    public function testComposerNoLongerComesFromTheInstallerPipe(): void
    {
        self::assertStringNotContainsString('getcomposer.org/installer', self::read());
    }

    private static function read(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/.gitlab-ci.yml'));
    }
}
