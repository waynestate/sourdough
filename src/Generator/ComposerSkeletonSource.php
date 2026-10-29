<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Fetches the starter: a fresh Laravel skeleton baked by Composer itself.
 *
 * This is the production source for the Laravel shapes. It downloads
 * laravel/laravel from Packagist, so it needs the network; tests use
 * FixtureSkeletonSource instead and never touch the network.
 *
 * The command runs through proc_open as an argument array, never through a
 * shell string. The binary is "composer" on PATH, overridable with the
 * SOURDOUGH_COMPOSER environment variable (for example, when Composer is
 * installed somewhere off PATH).
 */
final class ComposerSkeletonSource implements SkeletonSource
{
    /**
     * The package and constraint create-project installs.
     */
    public const PACKAGE = 'laravel/laravel';

    public const PACKAGE_VERSION = '^13.0';

    /**
     * @param string|null $composerBinary the binary to run; null means the
     *   SOURDOUGH_COMPOSER environment variable, or "composer" when that is unset
     */
    public function __construct(private readonly ?string $composerBinary = null) {}

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException when the target exists and is not empty
     * @throws \RuntimeException         when the process cannot start or Composer exits non-zero
     */
    public function create(string $targetDir): void
    {
        $this->refuseNonEmptyTarget($targetDir);

        $command = $this->command($targetDir);

        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Could not start "%s".', $command[0]));
        }

        // The command runs with --no-interaction and --no-install, so its
        // output is small; drain both streams, then wait for the exit code.
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_get_status($process);
        while (is_array($status) && $status['running']) {
            usleep(50000);
            $status = proc_get_status($process);
        }
        proc_close($process);

        if (!is_array($status)) {
            throw new \RuntimeException(sprintf('Lost track of "%s".', $command[0]));
        }

        $exitCode = (int) $status['exitcode'];
        if (0 !== $exitCode) {
            $detail = '' !== trim($stderr) ? $stderr : $stdout;

            throw new \RuntimeException(sprintf(
                '"%s" failed with exit code %d:%s',
                $command[0],
                $exitCode,
                '' !== trim($detail) ? "\n" . trim($detail) : '',
            ));
        }
    }

    /**
     * The exact argument list create() would run for $targetDir. Small and
     * protected so a test can assert the arguments without calling Composer.
     *
     * @return list<string>
     */
    protected function command(string $targetDir): array
    {
        return [
            $this->binary(),
            'create-project',
            self::PACKAGE . ':' . self::PACKAGE_VERSION,
            $targetDir,
            '--no-install',
            '--no-scripts',
            '--no-interaction',
            '--prefer-dist',
        ];
    }

    private function binary(): string
    {
        if (null !== $this->composerBinary) {
            return $this->composerBinary;
        }

        $fromEnv = getenv('SOURDOUGH_COMPOSER');
        if (false !== $fromEnv && '' !== $fromEnv) {
            return $fromEnv;
        }

        return 'composer';
    }

    private function refuseNonEmptyTarget(string $targetDir): void
    {
        if (is_file($targetDir)) {
            throw new \InvalidArgumentException(sprintf('The target "%s" is a file, not a directory.', $targetDir));
        }

        if (!is_dir($targetDir)) {
            return;
        }

        foreach (scandir($targetDir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                throw new \InvalidArgumentException(sprintf(
                    'The target directory "%s" already exists and is not empty.',
                    $targetDir,
                ));
            }
        }
    }
}
