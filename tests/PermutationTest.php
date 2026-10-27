<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Permutation;

final class PermutationTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool, bool, Permutation}>
     */
    public static function validFlags(): iterable
    {
        yield 'no flags is raw' => [false, false, false, false, Permutation::Raw];
        yield '--raw alone is raw' => [true, false, false, false, Permutation::Raw];
        yield '--raw --vue is raw vue' => [true, false, true, false, Permutation::RawVue];
        yield '--vue alone is raw vue' => [false, false, true, false, Permutation::RawVue];
        yield '--laravel is laravel blade' => [false, true, false, false, Permutation::LaravelBlade];
        yield '--laravel --vue is laravel blade vue' => [false, true, true, false, Permutation::LaravelBladeVue];
        yield '--laravel --inertia is laravel inertia' => [false, true, false, true, Permutation::LaravelInertia];
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool, string}>
     */
    public static function invalidFlags(): iterable
    {
        yield 'inertia without laravel' => [false, false, false, true, '--inertia needs --laravel'];
        yield 'raw with laravel' => [true, true, false, false, '--raw and --laravel are mutually exclusive'];
        yield 'vue with inertia' => [false, true, true, true, '--vue is not needed with --inertia'];
    }

    #[DataProvider('validFlags')]
    public function testValidFlagsResolveToTheirPermutation(bool $raw, bool $laravel, bool $vue, bool $inertia, Permutation $expected): void
    {
        self::assertSame($expected, Permutation::fromFlags($raw, $laravel, $vue, $inertia));
    }

    #[DataProvider('invalidFlags')]
    public function testInvalidFlagsThrow(bool $raw, bool $laravel, bool $vue, bool $inertia, string $messagePart): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');

        Permutation::fromFlags($raw, $laravel, $vue, $inertia);
    }

    public function testLabelsMatchTheSpecTable(): void
    {
        self::assertSame("Raw PHP with WayneUI's standalone path", Permutation::Raw->label());
        self::assertSame('Raw PHP + Vue SPA over JSON', Permutation::RawVue->label());
        self::assertSame('Laravel + Blade', Permutation::LaravelBlade->label());
        self::assertSame('Laravel + Blade + Vue islands', Permutation::LaravelBladeVue->label());
        self::assertSame('Laravel + Inertia/Vue', Permutation::LaravelInertia->label());
    }
}
