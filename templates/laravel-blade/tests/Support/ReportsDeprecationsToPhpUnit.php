<?php

namespace Tests\Support;

use PHPUnit\Runner\ErrorHandler;

/**
 * Deprecations are failures (phpunit.xml sets failOnDeprecation), but Laravel
 * swaps in its own error handler when the app boots, and while testing that
 * handler drops every deprecation, so PHPUnit would never see one raised
 * inside a test. This hands deprecations back to PHPUnit's handler, the one
 * Laravel itself puts back after each test, which applies phpunit.xml's
 * <source> rules, the baseline and #[IgnoreDeprecations]. Every other error
 * still goes to Laravel's handler, so a warning still throws as it does in
 * the app. PHPUnit reports the deprecation, so PHP does not print it as
 * well. Laravel pops both handlers when the test ends.
 */
trait ReportsDeprecationsToPhpUnit
{
    protected function reportDeprecationsToPhpUnit(): void
    {
        if (! class_exists(ErrorHandler::class)) {
            return;
        }

        $laravel = null;
        $laravel = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$laravel) {
            if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
                ErrorHandler::instance()($level, $message, $file, $line);

                return true;
            }

            return $laravel === null ? false : $laravel($level, $message, $file, $line);
        });
    }
}
