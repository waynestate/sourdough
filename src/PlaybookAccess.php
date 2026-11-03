<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

/**
 * Can this developer read Playbook, the private shared-values package on
 * git.wayne.edu? Asks the machine, never the human, and stores no
 * credentials: we are a starter, not a vault.
 */
final class PlaybookAccess
{
    public const REPO_URL = 'https://git.wayne.edu/enterprise-apps/playbook.git';

    public const PACKAGE = 'waynestate/playbook';

    public const HOST = 'git.wayne.edu';

    /** @var \Closure(list<string>, array<string, string>, int): array{0: int, 1: string} */
    private \Closure $run;

    /** @var array<string, string> */
    private array $env;

    private int $timeout;

    /**
     * @param array<string, string>|null $env
     */
    public function __construct(?\Closure $run = null, ?array $env = null, int $timeoutSeconds = 8)
    {
        $this->run = $run ?? $this->defaultRun(...);
        $this->env = $env ?? getenv();
        $this->timeout = $timeoutSeconds;
    }

    /**
     * @return array{status: string, via: ?string, detail: string}
     */
    public function detect(): array
    {
        if (self::isCi($this->env)) {
            return [
                'status' => 'skipped',
                'via' => null,
                'detail' => 'CI or non-interactive: network check skipped',
            ];
        }

        if ($this->composerHasCredential()) {
            return [
                'status' => 'access',
                'via' => 'composer-auth',
                'detail' => 'Composer already carries a credential for git.wayne.edu.',
            ];
        }

        $gitEnv = array_merge($this->env, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GCM_INTERACTIVE' => 'never',
            'GIT_ASKPASS' => '',
            'GIT_HTTP_LOW_SPEED_LIMIT' => '1000',
            'GIT_HTTP_LOW_SPEED_TIME' => (string) $this->timeout,
        ]);
        [$exitCode, $output] = ($this->run)(['git', 'ls-remote', '--exit-code', self::REPO_URL, 'HEAD'], $gitEnv, $this->timeout);

        if ($exitCode === 0) {
            return [
                'status' => 'access',
                'via' => 'git',
                'detail' => 'git can read the Playbook repository directly.',
            ];
        }

        $offline = $exitCode === 124
            || $exitCode === 127
            || preg_match('/could not resolve host|timed out|timeout|failed to connect|network is unreachable|connection refused/i', $output) === 1;

        if ($offline) {
            return [
                'status' => 'offline',
                'via' => null,
                'detail' => 'Could not reach git.wayne.edu from this machine.',
            ];
        }

        return [
            'status' => 'no-access',
            'via' => null,
            'detail' => 'git reached git.wayne.edu, but this account cannot read the repository.',
        ];
    }

    /**
     * @param array<string, string> $env
     */
    public static function isCi(array $env): bool
    {
        foreach (['CI', 'GITLAB_CI', 'GITHUB_ACTIONS', 'SOURDOUGH_NO_INTERACTION', 'COMPOSER_NO_INTERACTION'] as $key) {
            $value = $env[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if ($value === '0' || strcasecmp($value, 'false') === 0) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function installed(string $appDir): bool
    {
        return is_dir($appDir . '/vendor/waynestate/playbook');
    }

    /**
     * @return list<string>
     */
    public static function howTo(): array
    {
        return [
            "Playbook holds Wayne State's shared settings for apps; it is a private package on git.wayne.edu.",
            'Sign in to https://git.wayne.edu; if you cannot see enterprise-apps/playbook, ask Enterprise Applications for read access.',
            'Create a personal access token with the `read_repository` scope (Preferences, Access Tokens).',
            'Windows PowerShell:',
            'composer config --global --append gitlab-domains git.wayne.edu',
            'composer config --global gitlab-token.git.wayne.edu "<your-token>"',
            'Linux or macOS:',
            'composer config --global --append gitlab-domains git.wayne.edu',
            "composer config --global gitlab-token.git.wayne.edu '<your-token>'",
            'Then run this command again.',
        ];
    }

    /**
     * Run a command without a shell, capture stdout and stderr together,
     * and give up after the timeout.
     *
     * @param list<string> $argv
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function defaultRun(array $argv, array $env, int $timeoutSeconds): array
    {
        $pipes = [];
        $process = @proc_open($argv, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            return [127, 'not found'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (proc_get_status($process)['running']) {
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 15);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [124, 'timeout'];
            }

            usleep(50_000);
        }

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            while (($chunk = fread($pipe, 8192)) !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipe);
        }

        return [proc_close($process), $output];
    }

    private function composerHasCredential(): bool
    {
        if ($this->credentialIn(json_decode((string) ($this->env['COMPOSER_AUTH'] ?? ''), true))) {
            return true;
        }

        foreach ($this->authJsonDirs($this->env) as $dir) {
            $file = $dir . '/auth.json';
            if (!is_file($file)) {
                continue;
            }
            if ($this->credentialIn(json_decode((string) file_get_contents($file), true))) {
                return true;
            }
        }

        [$exitCode, $output] = ($this->run)(['composer', 'config', '--global', 'gitlab-token.git.wayne.edu'], $this->env, $this->timeout);

        return $exitCode === 0 && trim($output) !== '';
    }

    private function credentialIn(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $token = $data['gitlab-token'][self::HOST] ?? null;
        if (is_string($token) && $token !== '') {
            return true;
        }

        $basic = $data['http-basic'][self::HOST] ?? null;

        return is_array($basic) && $basic !== [];
    }

    /**
     * The constructor takes a plain array, so the checks below also
     * defend against non-string values at runtime.
     *
     * @param array<string, mixed> $env
     *
     * @return list<string>
     */
    private function authJsonDirs(array $env): array
    {
        $dirs = [];

        $composerHome = $env['COMPOSER_HOME'] ?? '';
        if (is_string($composerHome) && $composerHome !== '') {
            $dirs[] = $composerHome;
        } elseif (isset($env['APPDATA']) && is_string($env['APPDATA'])) {
            $dirs[] = $env['APPDATA'] . '/Composer';
        }

        if (isset($env['HOME']) && is_string($env['HOME'])) {
            $dirs[] = $env['HOME'] . '/.composer';
        }

        if (isset($env['XDG_CONFIG_HOME']) && is_string($env['XDG_CONFIG_HOME'])) {
            $dirs[] = $env['XDG_CONFIG_HOME'] . '/composer';
        }

        return $dirs;
    }
}
