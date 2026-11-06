<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\TestCase;

final class DockerDevContainerTest extends TestCase
{
    /**
     * The dev stage runs as root on purpose: Docker Desktop bind mounts are
     * root-owned, so a non-root user could not write .env, storage or
     * bootstrap/cache. No USER line may remain in the stage.
     */
    public function testDevStageRunsAsRootWithoutAUserLine(): void
    {
        $dev = $this->devStage();

        self::assertStringContainsString('ENV COMPOSER_ALLOW_SUPERUSER=1', $dev);
        self::assertStringNotContainsString('ENV COMPOSER_ALLOW_SUPERUSER=0', $dev);
        self::assertSame(0, preg_match_all('/^USER /m', $dev));
    }

    /**
     * The dev stage CMD bootstraps itself: composer install when vendor is
     * missing, .env from .env.example when missing, then artisan serve.
     */
    public function testDevStageCmdBootstrapsVendorAndEnvBeforeServing(): void
    {
        $dev = $this->devStage();

        self::assertStringContainsString('composer install', $dev);
        self::assertStringContainsString('vendor/autoload.php', $dev);
        self::assertStringContainsString('php artisan serve --host=0.0.0.0 --port=8000', $dev);
    }

    public function testProdStageStillRunsAsWwwData(): void
    {
        self::assertStringContainsString('USER www-data', $this->prodStage());
    }

    /**
     * The README steps no longer tell the developer to skip composer install
     * or run it inside the container; the first start does it for them.
     */
    public function testReadmeSectionDropsTheSkippableComposerInstall(): void
    {
        $readme = $this->readmeSection();

        self::assertStringNotContainsString('or skip it', $readme);
        self::assertStringNotContainsString('exec app composer install', $readme);
        self::assertStringContainsString('php artisan key:generate', $readme);
    }

    private function dockerfile(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/templates/docker/Dockerfile');
    }

    /**
     * The dev stage: the text between `AS dev` and the next FROM line.
     */
    private function devStage(): string
    {
        $dockerfile = $this->dockerfile();
        $start = strpos($dockerfile, 'AS dev');
        self::assertIsInt($start, 'Expected an AS dev stage in the Dockerfile.');
        $end = strpos($dockerfile, 'FROM', $start + strlen('AS dev'));
        self::assertIsInt($end, 'Expected a FROM line after the dev stage.');

        return substr($dockerfile, $start, $end - $start);
    }

    private function prodStage(): string
    {
        $dockerfile = $this->dockerfile();
        $start = strpos($dockerfile, 'AS prod');
        self::assertIsInt($start, 'Expected an AS prod stage in the Dockerfile.');

        return substr($dockerfile, $start);
    }

    private function readmeSection(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/templates/docker/README-section.md');
    }
}
