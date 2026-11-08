<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\PlaybookMode;

final class RawGeneratorTest extends TestCase
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

    public function testGeneratesAppWithReplacedNamesAndSlugs(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        self::assertFileExists($target . '/README.md');
        self::assertFileExists($target . '/my-cool-app.txt');
        self::assertFileExists($target . '/config/my-cool-app.ini');

        self::assertSame("Welcome to My Cool App (my-cool-app).\n", file_get_contents($target . '/README.md'));
        self::assertSame("Slug file for My Cool App.\n", file_get_contents($target . '/my-cool-app.txt'));
        self::assertSame("app=My Cool App\n", file_get_contents($target . '/config/my-cool-app.ini'));

        // No placeholder survives anywhere in the generated tree.
        foreach ($this->allFiles($target) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('{{app_name}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', $content, $file);
            self::assertStringNotContainsString('{{app_slug}}', basename($file), $file);
        }
    }

    public function testWritesTheManifestAfterGeneration(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        self::assertFileExists($target . '/.sourdough/manifest.json');

        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema']);
        self::assertSame('raw', $manifest['template']);
        self::assertSame(['name' => 'My Cool App'], $manifest['answers']);
        self::assertSame('seeded', $manifest['files']['README.md']['class']);
        self::assertSame('managed', $manifest['files']['my-cool-app.txt']['class']);
        self::assertSame('managed', $manifest['files']['config/my-cool-app.ini']['class']);
    }

    public function testRefusesNonEmptyTarget(): void
    {
        $target = $this->makeTempDir();
        file_put_contents($target . '/existing.txt', 'here');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists and is not empty');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testRefusesTargetThatIsAFile(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/a-file';
        file_put_contents($target, 'i am a file');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a file, not a directory');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');
    }

    public function testWritesNothingOutsideTheTarget(): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/app';

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, 'My Cool App');

        // The parent should contain only the target dir, nothing else.
        $entries = array_values(array_diff(scandir($parent) ?: [], ['.', '..']));
        self::assertSame(['app'], $entries);
    }

    public function testMissingTemplateDirThrows(): void
    {
        $target = $this->makeTempDir();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new RawGenerator($target . '/no-such-template'))->generate($target, 'My Cool App');
    }

    public function testNameWithOnlySymbolsThrows(): void
    {
        $target = $this->makeTempDir();

        // The name allow-list rejects this before the slug check runs.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with a letter or number');

        (new RawGenerator($this->fixtureTemplateDir()))->generate($target, '!!!');
    }

    #[DataProvider('hostileNames')]
    public function testHostileNamesThrowAndWriteNothing(string $name): void
    {
        $parent = $this->makeTempDir();
        $target = $parent . '/app';

        try {
            (new RawGenerator($this->fixtureTemplateDir()))->generate($target, $name);
            self::fail('Expected \InvalidArgumentException for ' . var_export($name, true));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must start with a letter or number', $e->getMessage());
        }

        // The name check runs before anything touches the disk, so the target
        // is absent, or empty if something created it anyway.
        self::assertFalse(is_file($target), var_export($name, true));
        if (is_dir($target)) {
            self::assertSame(['.', '..'], scandir($target) ?: [], var_export($name, true));
        }
    }

    public function testRealTemplateAcceptsANameWithSpacesDotsAndUnderscores(): void
    {
        $target = $this->makeTempDir();

        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My App v2.1_beta');

        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('My App v2.1_beta', $composer['description']);
    }

    public function testGeneratedIndexUsesNewSesameConstructor(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The config is built from an array with the fake driver's keys,
        // nested the way Config::fromArray reads them.
        self::assertStringContainsString("'driver' => 'fake'", $index);
        self::assertStringContainsString("'environment' => \$appEnv", $index);
        self::assertStringContainsString("'fake' => [", $index);
        self::assertStringContainsString("'username' => 'jdoe'", $index);
        self::assertStringContainsString("'attributes' => ['cn' => ['John Doe']]", $index);
        self::assertStringContainsString('Config::fromArray(', $index);

        // The driver comes from the factory, not a direct FakeDriver. The
        // cas driver needs a PSR-18 client and a PSR-17 factory to check the
        // ticket, and (SESAME 0.5) the app's session for its pending-login
        // marker, so the factory gets all three.
        self::assertStringContainsString('DriverFactory::make($config, http: $http, requests: new Psr17Factory(), session: $session)', $index);
        self::assertStringContainsString("'timeout' => 10", $index);
        self::assertStringContainsString("'allow_redirects' => false", $index);
        self::assertStringNotContainsString('new FakeDriver(', $index);

        // new Sesame(driver, resolver, session, config): four arguments, and
        // the session is the one the driver got.
        self::assertStringContainsString('$session = new NativeSession();', $index);
        self::assertMatchesRegularExpression(
            '/new Sesame\(\s*DriverFactory::make\(\$config, [^
]*session: \$session\),\s*new class implements UserResolver \{[\s\S]*?\},\s*\$session,\s*\$config,\s*\);/',
            $index,
        );

        // The optional session limits come from the environment, cast to int,
        // and are nested under 'session'.
        self::assertStringContainsString("getenv('SESAME_SESSION_IDLE_MINUTES')", $index);
        self::assertStringContainsString("getenv('SESAME_SESSION_ABSOLUTE_MINUTES')", $index);
        self::assertStringContainsString("'idle_minutes'] = (int)", $index);
        self::assertStringContainsString("'absolute_minutes'] = (int)", $index);
        self::assertStringContainsString("\$sesameConfig['session'] = \$sessionLimits", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESAME_SESSION_IDLE_MINUTES=', $env);
        self::assertStringContainsString('# SESAME_SESSION_ABSOLUTE_MINUTES=', $env);
    }

    public function testGeneratedIndexWiresSesameInEveryEnvironment(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // Log in only when needed: use the signed-in user, or log in.
        self::assertStringContainsString('$user = sesame_user() ?? sesame_login();', $index);

        // The whole SESAME block is no longer gated on a non-production
        // environment; the driver is chosen per environment instead.
        self::assertStringNotContainsString("!== 'production'", $index);

        // The cas driver is wired for production, with both URLs from the
        // environment, nested the way Config::fromArray reads them.
        self::assertStringContainsString("getenv('SESAME_CAS_URL')", $index);
        self::assertStringContainsString("getenv('SESAME_SERVICE_URL')", $index);
        self::assertStringContainsString("'driver' => 'cas'", $index);
        self::assertStringContainsString("'cas' => [", $index);
        self::assertStringContainsString("'base_url' => \$casUrl", $index);
        self::assertStringContainsString("'service_url' => \$serviceUrl", $index);

        // The fake driver is still wired for non-production.
        self::assertStringContainsString("'driver' => 'fake'", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESAME_CAS_URL=', $env);
        self::assertStringContainsString('# SESAME_SERVICE_URL=', $env);
    }

    public function testGeneratedIndexHandlesUnsetEnvVars(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // getenv() returns false, not '', when a variable is unset, so the
        // generated code must never compare its result to '' directly.
        self::assertStringNotContainsString("'' !== getenv(", $index);

        // Config::fromArray reads dotted keys as nested arrays, so the fake
        // driver's keys must be nested, not dotted.
        self::assertStringNotContainsString("'fake.username' =>", $index);

        // The session limits use the SESAME_SESSION_* names.
        self::assertStringContainsString('SESAME_SESSION_IDLE_MINUTES', $index);
    }

    public function testGeneratedIndexReadsTrustedProxiesFromEnv(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        // The hard-coded empty list and its Citadel comment are gone.
        self::assertStringNotContainsString("'trusted_proxies' => []", $index);
        self::assertStringNotContainsString('HA-Proxy', $index);

        // HELMET_PROXIES is comma-separated, trimmed, empty entries dropped.
        self::assertStringContainsString("getenv('HELMET_PROXIES')", $index);
        self::assertStringContainsString("explode(',',", $index);
        self::assertStringContainsString('trim($proxy)', $index);
        self::assertStringContainsString("'trusted_proxies' => \$trustedProxies", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# HELMET_PROXIES=', $env);
        self::assertStringContainsString('172.18.0.1', $env);
    }

    public function testGeneratedIndexLogsToStderrByDefault(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        self::assertStringContainsString("ini_set('log_errors', '1')", $index);
        self::assertStringContainsString("ini_set('error_log', getenv('LOG_FILE') ?: 'php://stderr')", $index);
        self::assertStringContainsString("ini_set('display_errors', \$appEnv === 'production' ? '0' : '1')", $index);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# LOG_FILE=', $env);
    }

    public function testGeneratedIndexAppliesSessionStoreFromEnv(): void
    {
        $target = $this->generateFromRealTemplate();
        $index = (string) file_get_contents($target . '/public/index.php');

        self::assertStringContainsString("getenv('SESSION_SAVE_HANDLER')", $index);
        self::assertStringContainsString("getenv('SESSION_SAVE_PATH')", $index);
        self::assertStringContainsString("ini_set('session.save_handler', \$sessionSaveHandler)", $index);
        self::assertStringContainsString("ini_set('session.save_path', \$sessionSavePath)", $index);

        // The ini_set calls must come before session_start().
        $iniPos = strpos($index, "ini_set('session.save_handler'");
        $startPos = strpos($index, 'session_start()');
        self::assertIsInt($iniPos);
        self::assertIsInt($startPos);
        self::assertLessThan($startPos, $iniPos);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringContainsString('# SESSION_SAVE_HANDLER=', $env);
        self::assertStringContainsString('# SESSION_SAVE_PATH=', $env);
        self::assertStringContainsString('shared store', $env);
    }

    public function testGeneratedReadmeHasOffCitadelSection(): void
    {
        $target = $this->generateFromRealTemplate();
        $readme = (string) file_get_contents($target . '/README.md');

        self::assertStringContainsString('## Running off Citadel', $readme);
        foreach (['APP_ENV', 'HELMET_PROXIES', 'LOG_FILE', 'SESSION_SAVE_HANDLER', 'SESSION_SAVE_PATH', 'SESAME_SESSION_IDLE_MINUTES', 'SESAME_SESSION_ABSOLUTE_MINUTES'] as $var) {
            self::assertStringContainsString('`' . $var . '`', $readme);
        }
        self::assertStringContainsString('scripts/release.sh', $readme);
        self::assertStringContainsString('Dockerfile comes later', $readme);
    }

    public function testInstallsSquireDeployFilesAtCatalogPaths(): void
    {
        $target = $this->generateFromRealTemplate();

        // The raw deploy files from Squire's checked copy are installed at
        // their catalog paths, as-is.
        foreach ([
            'build.sh',
            'deployments/001-first-release.sh',
            'scripts/release.sh',
            'scripts/parity.sh',
            'scripts/parity.ps1',
            'health.php',
            'public/up.php',
            'docker-compose.squire.yml',
            '.env.squire.example',
            '.squire/Dockerfile',
            '.squire/Dockerfile.dockerignore',
            '.squire/base-fallback.Dockerfile',
            '.squire/haproxy.cfg',
            '.squire/php.ini',
            '.squire/vhost.conf',
            '.squire/fallback/citadel-apache-entrypoint',
            '.squire/fallback/citadel-deploy',
        ] as $path) {
            self::assertFileExists($target . '/' . $path, $path);
        }
    }

    public function testSquireReleaseScriptIsExecutable(): void
    {
        $target = $this->generateFromRealTemplate();
        $path = $target . '/scripts/release.sh';

        self::assertFileExists($path);
        // The mode comes from Squire's manifest (755). This needs a Unix
        // filesystem; the test suite runs in Docker.
        self::assertNotSame(0, fileperms($path) & 0111, 'scripts/release.sh should be executable');
    }

    public function testGeneratedAppHasNoSourdoughReleaseScripts(): void
    {
        $target = $this->generateFromRealTemplate();

        // The old Sourdough release scripts are gone; Squire's are the only ones.
        self::assertFileDoesNotExist($target . '/deployments/release.sh');
        self::assertFileDoesNotExist($target . '/deployments/release.ps1');
        self::assertFileExists($target . '/scripts/release.sh');
        self::assertFileExists($target . '/scripts/parity.ps1');
    }

    public function testManifestListsSquireFilesAsManaged(): void
    {
        $target = $this->generateFromRealTemplate();
        $manifest = json_decode((string) file_get_contents($target . '/.sourdough/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['build.sh', 'scripts/release.sh', 'deployments/001-first-release.sh', '.squire/Dockerfile'] as $path) {
            self::assertArrayHasKey($path, $manifest['files'], $path);
            self::assertSame('managed', $manifest['files'][$path]['class'], $path);
        }
    }

    public function testSuggestModeComposerHasNoRepositories(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::Suggest);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('repositories', $composer);
    }

    public function testGeneratedComposerHasTheCiScripts(): void
    {
        $target = $this->generateFromRealTemplate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('phpunit', $composer['scripts']['test']);
        self::assertSame('pint --test', $composer['scripts']['lint']);
        self::assertSame('phpstan analyse', $composer['scripts']['analyse']);
        self::assertSame('proctor check', $composer['scripts']['proctor']);
        self::assertSame(['@test', '@lint', '@analyse', '@proctor', 'composer audit'], $composer['scripts']['ci']);
        self::assertArrayHasKey('scripts-descriptions', $composer);
    }

    public function testBakedGitlabCiPinsPreflightToTheReleaseSha(): void
    {
        $target = $this->generateFromRealTemplate();
        $ci = (string) file_get_contents($target . '/.gitlab-ci.yml');

        // PROCTOR's preflight-pinned check wants a 40-hex SHA or a release
        // tag, so the components pin Preflight's release commit, with the
        // tag in a trailing comment. From 0.3.0 the components add the
        // registry prefix themselves (image_prefix, here the shape's IMAGE_PREFIX),
        // and the baked includes keep the components' default images.
        self::assertStringContainsString('23038dfe767f6ef60e3b2efca1128f53d9acdff7 # 0.3.0', $ci);
        self::assertStringNotContainsString('7d4f6dad9c90c4f2b2aaf50fdde2a43dbdc5def1', $ci);
        self::assertStringNotContainsString('79029cc2300702d0d450c42cc699e12af1b731f8', $ci);
        self::assertStringContainsString('      image_prefix: "${IMAGE_PREFIX}"', $ci);
        self::assertStringNotContainsString('image: "${IMAGE_PREFIX}/', $ci);
    }

    public function testGeneratedAppShipsItsPhpunitFiles(): void
    {
        $target = $this->generateFromRealTemplate();

        // The CI job runs vendor/bin/phpunit, so the template must require it.
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);

        self::assertFileExists($target . '/phpunit.xml.dist');
        self::assertFileExists($target . '/tests/SmokeTest.php');
    }

    public function testGeneratedAppShipsTheOracleHelper(): void
    {
        $target = $this->generateFromRealTemplate();

        self::assertFileExists($target . '/src/Oracle.php');
        self::assertFileExists($target . '/src/OracleException.php');
        self::assertFileExists($target . '/tests/OracleTest.php');

        // The helper is autoloaded from src/, and no oci8 requirement is added.
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['App\\' => 'src/'], $composer['autoload']['psr-4']);
        self::assertArrayNotHasKey('ext-oci8', $composer['require'] ?? []);

        // The keys are present but commented out, so nothing connects until set.
        $env = (string) file_get_contents($target . '/.env.example');
        foreach (['# ORACLE_USERNAME=', '# ORACLE_PASSWORD=', '# ORACLE_TNS=', '# ORACLE_HOST=', '# ORACLE_PORT=', '# ORACLE_SERVICE_NAME='] as $line) {
            self::assertStringContainsString($line, $env, $line);
        }
        self::assertStringNotContainsString("ORACLE_USERNAME='", $env);
    }

    public function testDefaultGenerationRequiresPlaybook(): void
    {
        $target = $this->generateFromRealTemplate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // Playbook is required by default now: the package, the VCS
        // repository and the gitlab-domains entry that lets composer send
        // the token to git.wayne.edu.
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/playbook.git'],
        ], $composer['repositories']);
        self::assertSame(['git.wayne.edu'], $composer['config']['gitlab-domains']);
        // The suggest stays, so a developer who removes the require still
        // sees what the package is for.
        self::assertSame("WSU's shared values; private, git.wayne.edu", $composer['suggest']['waynestate/playbook']);

        // All seven setting names, each empty and marked as coming from Playbook.
        $env = (string) file_get_contents($target . '/.env.example');
        foreach (RawGenerator::PLAYBOOK_ENV_VARS as $name) {
            self::assertStringContainsString("# {$name}= # from PLAYBOOK when unset", $env, $name);
        }

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringContainsString('## WSU values (PLAYBOOK)', $readme);
        self::assertStringContainsString('You need read access before `composer install` works.', $readme);
        self::assertStringContainsString('Windows PowerShell:', $readme);
        self::assertStringContainsString('Linux or macOS:', $readme);
        self::assertStringContainsString('composer config --global --append gitlab-domains git.wayne.edu', $readme);
        self::assertStringContainsString('composer config --global gitlab-token.git.wayne.edu "<your-token>"', $readme);
        self::assertStringContainsString("composer config --global gitlab-token.git.wayne.edu '<your-token>'", $readme);
        self::assertStringContainsString('Then run this command again.', $readme);
        // The old fetch-by-curl instructions are gone with Suggest mode.
        self::assertStringNotContainsString('curl --fail', $readme);
        self::assertStringNotContainsString('$env:GITLAB_TOKEN', $readme);
        self::assertStringNotContainsString('git clone https://git.wayne.edu/enterprise-apps/playbook.git', $readme);

        // The section sits before the Handbook section, which stays last.
        $sectionPos = strpos($readme, '## WSU values (PLAYBOOK)');
        $handbookPos = strpos($readme, '## Handbook');
        self::assertIsInt($sectionPos);
        self::assertIsInt($handbookPos);
        self::assertLessThan($handbookPos, $sectionPos);
    }

    public function testRequireModeAddsTheRepositoryAndTheRequire(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::Require);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            ['type' => 'vcs', 'url' => 'https://git.wayne.edu/enterprise-apps/playbook.git'],
        ], $composer['repositories']);
        self::assertSame('^0.1', $composer['require']['waynestate/playbook']);
        // The token is only sent to git.wayne.edu, never to Packagist.
        self::assertSame(['git.wayne.edu'], $composer['config']['gitlab-domains']);
        // The template's own config keys survive the merge.
        self::assertSame(['phpstan/extension-installer' => true], $composer['config']['allow-plugins']);
        // The default wiring stays in place under Require mode.
        self::assertSame("WSU's shared values; private, git.wayne.edu", $composer['suggest']['waynestate/playbook']);

        // The rest of the require section is untouched.
        self::assertSame('^8.3', $composer['require']['php']);
        self::assertSame('^1.0', $composer['require']['waynestate/helmet']);
        self::assertSame('^0.5', $composer['require']['waynestate/sesame']);
        // The cas driver's PSR-18 client and PSR-17 factory.
        self::assertSame('^7.9 || ^8.0', $composer['require']['guzzlehttp/guzzle']);
        self::assertSame('^1.8', $composer['require']['nyholm/psr7']);
    }

    public function testRequireModeAddsComposerPlaybookScript(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::Require);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('@composer show waynestate/playbook --no-interaction', $composer['scripts']['playbook']);
        self::assertSame('Check that Playbook (WSU shared values) is installed', $composer['scripts-descriptions']['playbook']);
    }

    public function testComposerPlaybookScriptKeepsExistingScripts(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::Require);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // The template's own scripts stay, in their order, with playbook appended last.
        self::assertSame(['test', 'serve', 'lint', 'fix', 'analyse', 'proctor', 'ci', 'playbook'], array_keys($composer['scripts']));
        self::assertSame('phpunit', $composer['scripts']['test']);
        self::assertSame('proctor check', $composer['scripts']['proctor']);
        self::assertSame(['@test', '@lint', '@analyse', '@proctor', 'composer audit'], $composer['scripts']['ci']);
        self::assertSame('@composer show waynestate/playbook --no-interaction', $composer['scripts']['playbook']);
    }

    public function testNoPlaybookModeAddsNoPlaybookScript(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::None);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('playbook', $composer['scripts'] ?? []);
        self::assertArrayNotHasKey('playbook', $composer['scripts-descriptions'] ?? []);
    }

    public function testNoneModeLeavesTheAppWithoutPlaybook(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::None);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('suggest', $composer);
        self::assertArrayNotHasKey('repositories', $composer);
        self::assertArrayNotHasKey('waynestate/playbook', $composer['require']);

        $env = (string) file_get_contents($target . '/.env.example');
        self::assertStringNotContainsString('PLAYBOOK', $env);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('WSU values (PLAYBOOK)', $readme);
    }

    public function testDefaultGenerationWiresAdvisor(): void
    {
        $target = $this->generateFromRealTemplate();
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('^0.1', $composer['require-dev']['waynestate/advisor']);

        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertStringContainsString('.mcp.json', $gitignore);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringContainsString('## ADVISOR', $readme);
        self::assertStringContainsString('php vendor/bin/advisor install --client=claude --write', $readme);

        // The section sits before the Handbook section, which stays last.
        $sectionPos = strpos($readme, '## ADVISOR');
        $handbookPos = strpos($readme, '## Handbook');
        self::assertIsInt($sectionPos);
        self::assertIsInt($handbookPos);
        self::assertLessThan($handbookPos, $sectionPos);
    }

    public function testNoAdvisorRemovesTheWiring(): void
    {
        $target = $this->generateFromRealTemplate(PlaybookMode::Suggest, false);
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('waynestate/advisor', $composer['require-dev']);

        $gitignore = (string) file_get_contents($target . '/.gitignore');
        self::assertStringNotContainsString('.mcp.json', $gitignore);

        $readme = (string) file_get_contents($target . '/README.md');
        self::assertStringNotContainsString('ADVISOR', $readme);
    }

    /**
     * Names that used to be pasted into file names and contents unescaped.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'double quote' => ['a"b'];
        yield 'single quote' => ["a'b"];
        yield 'backslash' => ['a\\b'];
        yield 'newline' => ["a\nb"];
        yield 'trailing newline' => ["My App\n"];
        yield 'NUL byte' => ["a\x00b"];
        yield 'placeholder' => ['{{app_slug}}'];
        yield 'path traversal' => ['../x'];
        yield 'too long' => [str_repeat('a', 64)];
        yield 'empty' => [''];
        yield 'only dashes' => ['---'];
        yield 'leading dash' => ['-my-app'];
        yield 'leading dot' => ['.my-app'];
        yield 'unicode look-alike' => ["\u{430}pp"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugs(): iterable
    {
        yield 'plain' => ['My App', 'my-app'];
        yield 'upper case' => ['MY APP', 'my-app'];
        yield 'spaces' => ['My  App', 'my-app'];
        yield 'underscores' => ['my_app', 'my-app'];
        yield 'dots' => ['my.app', 'my-app'];
        yield 'keeps dashes' => ['my-app', 'my-app'];
        yield 'keeps numbers' => ['app2', 'app2'];
        yield 'trims leading dash' => ['-my-app', 'my-app'];
        yield 'trims trailing dash' => ['my-app-', 'my-app'];
        yield 'slashes' => ['my/app', 'my-app'];
    }

    #[DataProvider('slugs')]
    public function testSlugify(string $name, string $expected): void
    {
        self::assertSame($expected, RawGenerator::slugify($name));
    }

    private function fixtureTemplateDir(): string
    {
        return __DIR__ . '/fixtures/template';
    }

    /**
     * Generate an app from the real templates/raw dir and return the target.
     */
    private function generateFromRealTemplate(PlaybookMode $playbook = PlaybookMode::Require, bool $advisor = true): string
    {
        $target = $this->makeTempDir();
        (new RawGenerator(__DIR__ . '/../templates/raw'))->generate($target, 'My Cool App', [], $playbook, $advisor);

        return $target;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sourdough-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function allFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->allFiles($path));
            } else {
                $files[] = $path;
            }
        }

        return $files;
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
