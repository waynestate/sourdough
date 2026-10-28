<?php

declare(strict_types=1);

use function Waynestate\Helmet\helmet_boot;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\UserResolver;

// Read the environment once, at the top. Everything below uses $appEnv.
// .env.example sets APP_ENV=local; production sets APP_ENV=production.
$appEnv = getenv('APP_ENV') ?: 'production';

// PHP errors go to stderr (stdout is the app's output) unless LOG_FILE
// points at a file. In production, errors are logged, never displayed.
ini_set('log_errors', '1');
ini_set('display_errors', $appEnv === 'production' ? '0' : '1');
ini_set('error_log', getenv('LOG_FILE') ?: 'php://stderr');

require __DIR__ . '/../vendor/autoload.php';

// HELMET sends the security headers, hardens the session, and answers the
// load balancer's /up ping before any of this runs.
//
// HELMET_PROXIES is a comma-separated list of proxy addresses to trust:
// on Citadel the value is 172.18.0.1; on other hosts, the address of the
// proxy in front of the app; empty (the default) trusts nothing, which is
// what a local machine wants.
$trustedProxies = [];
foreach (explode(',', getenv('HELMET_PROXIES') ?: '') as $proxy) {
    $proxy = trim($proxy);
    if ('' !== $proxy) {
        $trustedProxies[] = $proxy;
    }
}

$helmet = helmet_boot([
    'trusted_proxies' => $trustedProxies,
]);

// The session store comes from the environment so more than one replica can
// share it (for example SESSION_SAVE_HANDLER=redis and
// SESSION_SAVE_PATH=tcp://redis:6379). Unset keeps PHP's default.
// getenv() returns false, not '', when a variable is unset, so check the
// type before comparing to an empty string.
$sessionSaveHandler = getenv('SESSION_SAVE_HANDLER');
if (is_string($sessionSaveHandler) && '' !== $sessionSaveHandler) {
    ini_set('session.save_handler', $sessionSaveHandler);
}
$sessionSavePath = getenv('SESSION_SAVE_PATH');
if (is_string($sessionSavePath) && '' !== $sessionSavePath) {
    ini_set('session.save_path', $sessionSavePath);
}

// The app owns the session. HELMET set the cookie flags; start it here.
session_start();

// Local development signs in with SESAME's fake driver. It refuses to boot
// when the environment is production, so this is the only place a dev
// identity can exist. In production, wire up the cas driver instead.
$user = null;
if ($appEnv !== 'production') {
    $sesameConfig = [
        'driver' => 'fake',
        'environment' => $appEnv,
        'fake.username' => 'jdoe',
        'fake.attributes' => ['cn' => ['John Doe']],
    ];
    // Optional session limits, in minutes. Unset keeps the SESAME defaults.
    $idleMinutes = getenv('SESAME_IDLE_MINUTES');
    if (is_string($idleMinutes) && '' !== $idleMinutes) {
        $sesameConfig['session.idle_minutes'] = (int) $idleMinutes;
    }
    $absoluteMinutes = getenv('SESAME_ABSOLUTE_MINUTES');
    if (is_string($absoluteMinutes) && '' !== $absoluteMinutes) {
        $sesameConfig['session.absolute_minutes'] = (int) $absoluteMinutes;
    }

    $config = Config::fromArray($sesameConfig);

    $sesame = new Sesame(
        DriverFactory::make($config),
        new class implements UserResolver {
            public function findOrImportUser(Identity $identity): mixed
            {
                return $identity->username;
            }
        },
        new NativeSession(),
        $config,
    );
    sesame($sesame);
    sesame_login();
    $user = sesame_user();
}

// WayneUI's standalone file. Fetch it once per the WayneUI raw PHP guide:
//   curl -o public/wayne-ui.php \
//     https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php
require_once __DIR__ . '/wayne-ui.php';

echo wsu_shell_start([
    'app_name'  => '{{app_name}}',
    'assets'    => 'https://wayneui.apps.wayne.edu/v2',
    'csp_nonce' => $helmet['nonce'],
    'env'       => $appEnv,
    'user'      => $user,
]);
?>

<p class="wsu-prose">
    {{app_name}} is running.
</p>

<?php
echo wsu_shell_end();
