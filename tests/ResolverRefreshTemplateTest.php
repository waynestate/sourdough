<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Laravel 13 stores sessions as JSON, so the user SESAME saved at sign-in
 * comes back as a plain array on the next request. The baked resolvers must
 * implement RefreshesUser and read the row again by username, or every
 * signed-in page refuses the user after the first one.
 */
final class ResolverRefreshTemplateTest extends TestCase
{
    public function testLaravelResolversRefreshTheUser(): void
    {
        $templates = ['laravel-blade', 'laravel-inertia'];
        $contents = [];

        foreach ($templates as $template) {
            $path = dirname(__DIR__) . '/templates/' . $template . '/app/Sesame/UserResolver.php';
            $this->assertFileExists($path, $template . ' is missing app/Sesame/UserResolver.php');

            $contents[$template] = file_get_contents($path);
            $this->assertNotFalse($contents[$template], $path . ' could not be read');

            $source = $contents[$template];

            // The class joins the implements list with RefreshesUser.
            $this->assertMatchesRegularExpression(
                '/class\s+UserResolver\s+implements[^{]*\bRefreshesUser\b/',
                $source,
                $path . ' does not implement RefreshesUser',
            );

            // It imports the interface from SESAME.
            $this->assertStringContainsString(
                'use Waynestate\Sesame\RefreshesUser;',
                $source,
                $path . ' does not import Waynestate\Sesame\RefreshesUser',
            );

            // The refresh hook reads the user back by username.
            $this->assertStringContainsString(
                'public function refreshUser(mixed $user): mixed',
                $source,
                $path . ' does not define refreshUser(mixed $user): mixed',
            );

            // The session may hand back the user as a plain array.
            $this->assertStringContainsString(
                'is_array($user)',
                $source,
                $path . ' does not handle the user coming back as an array',
            );
        }

        // The blade and inertia copies stay in step.
        $this->assertSame(
            $contents['laravel-blade'],
            $contents['laravel-inertia'],
            'The laravel-blade and laravel-inertia UserResolver copies differ',
        );
    }
}
