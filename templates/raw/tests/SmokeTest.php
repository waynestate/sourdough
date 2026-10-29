<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The smoke test every fresh app ships with: the front door exists.
 * Replace it with real tests as the app grows.
 */
final class SmokeTest extends TestCase
{
    public function testPublicIndexExists(): void
    {
        self::assertFileExists(__DIR__ . '/../public/index.php');
    }
}
