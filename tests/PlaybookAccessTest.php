<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Waynestate\Sourdough\PlaybookAccess;

final class PlaybookAccessTest extends TestCase
{
    /** @var list<array{argv: list<string>, env: array<string, mixed>}> */
    private array $calls = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        $this->tempDirs = [];
        $this->calls = [];
        parent::tearDown();
    }

    public function testCiEnvSkipsTheNetworkCheck(): void
    {
        $run = $this->fakeRun(['composer' => [0, 'should-not-run'], 'git' => [0, 'should-not-run']]);
        $access = new PlaybookAccess($run, ['CI' => '1']);

        self::assertSame([
            'status' => 'skipped',
            'via' => null,
            'detail' => 'CI or non-interactive: network check skipped',
        ], $access->detect());
        self::assertSame([], $this->calls);
    }

    public function testComposerAuthEnvMeansAccessWithoutGit(): void
    {
        $run = $this->fakeRun(['git' => [0, '']]);
        $env = $this->cleanEnv();
        $env['COMPOSER_AUTH'] = '{"gitlab-token":{"git.wayne.edu":"x"}}';
        $result = (new PlaybookAccess($run, $env))->detect();

        self::assertSame('access', $result['status']);
        self::assertSame('composer-auth', $result['via']);
        foreach ($this->calls as $call) {
            self::assertNotSame('git', $call['argv'][0]);
        }
    }

    public function testEmptyComposerAuthFallsThroughToGit(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [0, '']]);
        $env = $this->cleanEnv();
        $env['COMPOSER_AUTH'] = '';
        $result = (new PlaybookAccess($run, $env))->detect();

        self::assertSame('access', $result['status']);
        self::assertSame('git', $result['via']);
        self::assertSame('composer', $this->calls[0]['argv'][0]);
        self::assertSame('git', $this->calls[1]['argv'][0]);
    }

    public function testAuthJsonInComposerHomeMeansAccess(): void
    {
        $home = $this->tempDir();
        file_put_contents($home . '/auth.json', '{"gitlab-token":{"git.wayne.edu":"x"}}');
        $run = $this->fakeRun(['composer' => [0, 'should-not-run']]);
        $result = (new PlaybookAccess($run, ['COMPOSER_HOME' => $home]))->detect();

        self::assertSame('access', $result['status']);
        self::assertSame('composer-auth', $result['via']);
        self::assertSame([], $this->calls);
    }

    public function testComposerConfigTokenMeansAccess(): void
    {
        $run = $this->fakeRun(['composer' => [0, "token\n"], 'git' => [0, 'should-not-run']]);
        $result = (new PlaybookAccess($run, $this->cleanEnv()))->detect();

        self::assertSame('access', $result['status']);
        self::assertSame('composer-auth', $result['via']);
        self::assertSame('composer', $this->calls[0]['argv'][0]);
    }

    public function testGitSuccessMeansAccessViaGit(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [0, '']]);
        $result = (new PlaybookAccess($run, $this->cleanEnv()))->detect();

        self::assertSame('access', $result['status']);
        self::assertSame('git', $result['via']);
    }

    public function testGitAuthFailureMeansNoAccess(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [128, 'fatal: Authentication failed']]);
        $result = (new PlaybookAccess($run, $this->cleanEnv()))->detect();

        self::assertSame('no-access', $result['status']);
        self::assertNull($result['via']);
    }

    public function testGitDnsFailureMeansOffline(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [128, 'Could not resolve host: git.wayne.edu']]);
        $result = (new PlaybookAccess($run, $this->cleanEnv()))->detect();

        self::assertSame('offline', $result['status']);
        self::assertNull($result['via']);
    }

    public function testGitTimeoutMeansOffline(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [124, 'timeout']]);
        $result = (new PlaybookAccess($run, $this->cleanEnv()))->detect();

        self::assertSame('offline', $result['status']);
        self::assertNull($result['via']);
    }

    public function testGitCallDisablesTerminalPrompts(): void
    {
        $run = $this->fakeRun(['composer' => [1, ''], 'git' => [0, '']]);
        (new PlaybookAccess($run, $this->cleanEnv(), 12))->detect();

        $gitCall = null;
        foreach ($this->calls as $call) {
            if ($call['argv'][0] === 'git') {
                $gitCall = $call;
            }
        }

        self::assertNotNull($gitCall);
        self::assertSame('0', $gitCall['env']['GIT_TERMINAL_PROMPT']);
        self::assertSame(['git', 'ls-remote'], array_slice($gitCall['argv'], 0, 2));
    }

    public function testInstalledLooksForTheVendorPackage(): void
    {
        $appDir = $this->tempDir();

        self::assertFalse(PlaybookAccess::installed($appDir));

        mkdir($appDir . '/vendor/waynestate/playbook', 0777, true);

        self::assertTrue(PlaybookAccess::installed($appDir));
    }

    public function testHowToCoversTheTokenAndBothShells(): void
    {
        $text = implode("\n", PlaybookAccess::howTo());

        self::assertStringContainsString('read_repository', $text);
        self::assertStringContainsString('gitlab-token.git.wayne.edu', $text);
        self::assertStringContainsString('Windows PowerShell:', $text);
        self::assertStringContainsString('Linux or macOS:', $text);
        self::assertStringNotContainsString("\u{2014}", $text);
        self::assertStringNotContainsString("\u{2013}", $text);
    }

    public function testIsCiRecognisesKnownVariables(): void
    {
        self::assertFalse(PlaybookAccess::isCi([]));
        self::assertFalse(PlaybookAccess::isCi(['CI' => '0']));
        self::assertFalse(PlaybookAccess::isCi(['CI' => 'false']));
        self::assertTrue(PlaybookAccess::isCi(['GITLAB_CI' => 'true']));
    }

    /**
     * A stand-in for the shell: records every call (argv and env) and
     * answers from a canned table keyed by the command name.
     *
     * @param array<string, array{0: int, 1: string}> $results
     */
    private function fakeRun(array $results): \Closure
    {
        $this->calls = [];

        return function (array $argv, array $env, int $timeoutSeconds) use ($results): array {
            $this->calls[] = ['argv' => $argv, 'env' => $env];

            return $results[$argv[0] ?? ''] ?? [1, ''];
        };
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/playbook-access-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * An env with no trace of the real machine's Composer setup, so no
     * real auth.json can leak into a test.
     */
    private function cleanEnv(): array
    {
        return ['COMPOSER_HOME' => $this->tempDir()];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
