<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\FixtureSkeletonSource;
use Waynestate\Sourdough\Generator\LaravelBladeGenerator;
use Waynestate\Sourdough\Generator\LaravelInertiaGenerator;
use Waynestate\Sourdough\PlaybookMode;

/**
 * D-166: the web guard runs on SESAME's "sesame" driver, so $request->user(),
 * @can, policies and $this->authorize() see the SESAME user inside
 * auth.sesame routes. A template change that drops config/auth.php or puts
 * the web guard back on the skeleton's session driver must fail here.
 * Static checks on purpose: evaluating the config needs Laravel's env()
 * helper, which the unit suite avoids. The starter keeps its own keys.
 */
final class SesameWebGuardTemplateTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @return array<string, array{0: class-string<LaravelBladeGenerator|LaravelInertiaGenerator>}>
     */
    public static function laravelShapes(): array
    {
        return [
            'blade' => [LaravelBladeGenerator::class],
            'inertia' => [LaravelInertiaGenerator::class],
        ];
    }

    /**
     * @param class-string<LaravelBladeGenerator|LaravelInertiaGenerator> $generator
     */
    #[DataProvider('laravelShapes')]
    public function testTheWebGuardRunsOnTheSesameDriver(string $generator): void
    {
        $target = $this->makeTempDir();
        (new $generator(new FixtureSkeletonSource(__DIR__ . '/fixtures/laravel-skeleton')))
            ->generate($target, 'My Cool App', [], PlaybookMode::Suggest, true);

        self::assertFileExists($target . '/config/auth.php');
        $auth = (string) file_get_contents($target . '/config/auth.php');

        // The file still parses: TOKEN_PARSE makes token_get_all() throw a
        // ParseError on bad syntax.
        self::assertNotSame([], token_get_all($auth, TOKEN_PARSE));

        // Strip the comments, then read the web guard's own block: a sesame
        // driver anywhere else in the file must not satisfy this check.
        $guards = $this->stripComments($auth);
        preg_match("/'web'\s*=>\s*\[(.*?)\]/s", $guards, $match);
        self::assertNotEmpty($match, 'the web guard is defined');
        self::assertMatchesRegularExpression("/'driver'\s*=>\s*'sesame'/", $match[1]);
    }

    /**
     * Drop T_COMMENT and T_DOC_COMMENT tokens, keep everything else.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $out .= $token[1];
                }
            } else {
                $out .= (string) $token;
            }
        }

        return $out;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
