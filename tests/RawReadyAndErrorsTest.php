<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The raw templates' HELMET boot options: both front controllers answer
 * GET /ready through the readiness option, and the error handler is on
 * only in production, so a local developer keeps seeing PHP's own error
 * output.
 */
final class RawReadyAndErrorsTest extends TestCase
{
    #[DataProvider('frontControllers')]
    public function testEnablesReadinessAndTheProductionOnlyErrorHandler(string $path): void
    {
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);

        self::assertStringContainsString("'readiness' => ['enabled' => true]", $contents);
        self::assertStringContainsString("'error_handler' => ['enabled' => \$appEnv === 'production']", $contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frontControllers(): iterable
    {
        yield 'raw' => [__DIR__ . '/../templates/raw/public/index.php'];
        yield 'raw-vue' => [__DIR__ . '/../templates/raw-vue/public/index.php'];
    }
}
