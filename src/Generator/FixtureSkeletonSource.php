<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Generator;

/**
 * Copies a checked-in skeleton dir into the target.
 *
 * This is the offline source for the Laravel shapes: it copies the trimmed
 * fixture at tests/fixtures/laravel-skeleton, so tests never touch the
 * network. The production path is ComposerSkeletonSource.
 *
 * Files are copied byte for byte; nothing in the fixture is rewritten.
 */
final class FixtureSkeletonSource implements SkeletonSource
{
    public function __construct(
        private readonly string $fixtureDir,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException when the target exists and is not empty
     * @throws \RuntimeException         when the fixture dir is missing or a file cannot be read or written
     */
    public function create(string $targetDir): void
    {
        if (!is_dir($this->fixtureDir)) {
            throw new \RuntimeException(sprintf('The fixture directory "%s" does not exist.', $this->fixtureDir));
        }

        $this->refuseNonEmptyTarget($targetDir);

        $this->copyDir($this->fixtureDir, $targetDir);
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

    private function copyDir(string $from, string $to): void
    {
        if (!is_dir($to)) {
            if (!mkdir($to, 0777, true) && !is_dir($to)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s".', $to));
            }
        }

        foreach (scandir($from) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $fromPath = $from . DIRECTORY_SEPARATOR . $item;
            $toPath = $to . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fromPath)) {
                $this->copyDir($fromPath, $toPath);
                continue;
            }

            $content = file_get_contents($fromPath);
            if (false === $content) {
                throw new \RuntimeException(sprintf('Could not read "%s".', $fromPath));
            }

            if (false === file_put_contents($toPath, $content)) {
                throw new \RuntimeException(sprintf('Could not write "%s".', $toPath));
            }
        }
    }
}
