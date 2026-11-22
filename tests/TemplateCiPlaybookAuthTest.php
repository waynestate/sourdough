<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every app shape's pipeline can install the private Playbook package:
 * the top-level COMPOSER_AUTH variable tells Composer to sign in to
 * git.wayne.edu with the job's own CI token, sent as GitLab's JOB-TOKEN
 * header (D-169). No stored secret: the token is the job's, it dies with
 * the pipeline, and the project must sit in a group Playbook's job-token
 * allowlist admits (enterprise-apps is). A shape that loses the variable
 * fails here, before its composer install is turned away at the door.
 */
final class TemplateCiPlaybookAuthTest extends TestCase
{
    private const COMPOSER_AUTH_LINE = "  COMPOSER_AUTH: '{\"gitlab-token\":{\"git.wayne.edu\":{\"username\":\"gitlab-ci-token\",\"token\":\"$CI_JOB_TOKEN\"}}}'";

    /** @return iterable<string, array{string}> */
    public static function pipelines(): iterable
    {
        foreach (glob(dirname(__DIR__) . '/templates/*/.gitlab-ci.yml') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    #[DataProvider('pipelines')]
    public function testComposerSignsInToGitWayneEduWithTheJobToken(string $file): void
    {
        $ci = self::read($file);
        $shape = basename(dirname($file));
        $block = self::topLevelVariables($ci);

        self::assertNotSame('', $block, "$shape: the pipeline has no top-level variables: block");
        self::assertSame(
            1,
            self::countLines($block, self::COMPOSER_AUTH_LINE),
            "$shape: the COMPOSER_AUTH line must appear exactly once in the top-level variables: block",
        );
        self::assertSame(
            1,
            self::countLines($ci, self::COMPOSER_AUTH_LINE),
            "$shape: the COMPOSER_AUTH line must not appear outside the top-level variables: block",
        );
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /**
     * The top-level variables: block: the lines after the line `variables:`
     * and before the next line that starts with a non-space character.
     * The workflow rule's nested `variables:` is indented, so it never
     * matches.
     */
    private static function topLevelVariables(string $ci): string
    {
        if (1 !== preg_match('/^variables:\n((?:[ ].*\n|\n)*)/m', $ci, $m)) {
            return '';
        }

        return $m[1];
    }

    private static function countLines(string $text, string $line): int
    {
        $count = 0;
        foreach (explode("\n", $text) as $candidate) {
            if ($line === $candidate) {
                $count++;
            }
        }

        return $count;
    }
}
