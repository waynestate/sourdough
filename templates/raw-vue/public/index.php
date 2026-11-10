<?php

declare(strict_types=1);

use App\Vite;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Sesame\Config;
use Waynestate\Sesame\DriverFactory;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\Sesame;
use Waynestate\Sesame\Session\NativeSession;
use Waynestate\Sesame\UserResolver;

use function Waynestate\Helmet\helmet_boot;

// Read the environment once, at the top. Everything below uses $appEnv.
// .env.example sets APP_ENV=local; production sets APP_ENV=production.
$appEnv = getenv('APP_ENV') ?: 'production';

// PHP errors go to stderr (stdout is the app's output) unless LOG_FILE
// points at a file. In production, errors are logged, never displayed.
ini_set('log_errors', '1');
ini_set('display_errors', $appEnv === 'production' ? '0' : '1');
ini_set('error_log', getenv('LOG_FILE') ?: 'php://stderr');

require __DIR__ . '/../vendor/autoload.php';

// Vite serves the Vue app: from the dev server while `npm run dev` runs,
// from public/build once `npm run build` has. Production never asks the
// dev server, even if a stray public/hot made it onto the box.
$vite = new Vite(__DIR__, $appEnv !== 'production');

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
    // HELMET's default policy, plus the Vite dev server while it runs.
    'csp' => $vite->withDevServer(SecurityHeaders::defaultCspPolicy()),
    // GET /ready answers here, like /up: add checks for the database and other
    // dependencies under 'checks' (name => callable) as the app grows.
    'readiness' => ['enabled' => true],
    // In production an uncaught exception is logged in full and the visitor
    // gets HELMET's neutral error page. Locally, PHP shows the error.
    'error_handler' => ['enabled' => $appEnv === 'production'],
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

// SESAME signs the user in in every environment. The driver depends on the
// environment: the fake driver for local development, the cas driver in
// production. The fake driver refuses to boot in production, so the cas
// driver is the only one that can run there.
//
// Config::fromArray reads dotted keys as nested arrays, so each driver's
// keys live under the driver name and the session limits under 'session'.
if ($appEnv === 'production') {
    // The cas driver needs the CAS server URL and this app's service URL.
    // Both come from the environment and neither has a default, so a missing
    // value fails the boot with a clear message instead of a broken login.
    $failBoot = static function (string $message): never {
        error_log($message);
        exit(1);
    };

    $casUrl = getenv('SESAME_CAS_URL');
    if (!is_string($casUrl) || '' === $casUrl) {
        $failBoot('SESAME_CAS_URL is not set. Set it to the CAS server URL so the cas driver can sign users in.');
    }
    $serviceUrl = getenv('SESAME_SERVICE_URL');
    if (!is_string($serviceUrl) || '' === $serviceUrl) {
        $failBoot('SESAME_SERVICE_URL is not set. Set it to this app\'s public URL, the one the CAS server sends the user back to.');
    }
    $sesameConfig = [
        'driver' => 'cas',
        'environment' => $appEnv,
        'cas' => [
            'base_url' => $casUrl,
            'service_url' => $serviceUrl,
        ],
    ];
} else {
    // Local development signs in with the fake driver. It refuses to boot
    // when the environment is production, so this is the only place a dev
    // identity can exist.
    $sesameConfig = [
        'driver' => 'fake',
        'environment' => $appEnv,
        'fake' => [
            'username' => 'jdoe',
            'attributes' => ['cn' => ['John Doe']],
        ],
    ];
}

// Optional session limits, in minutes. Unset keeps the SESAME defaults;
// only the keys that are set are added.
$sessionLimits = [];
$idleMinutes = getenv('SESAME_SESSION_IDLE_MINUTES');
if (is_string($idleMinutes) && '' !== $idleMinutes) {
    $sessionLimits['idle_minutes'] = (int) $idleMinutes;
}
$absoluteMinutes = getenv('SESAME_SESSION_ABSOLUTE_MINUTES');
if (is_string($absoluteMinutes) && '' !== $absoluteMinutes) {
    $sessionLimits['absolute_minutes'] = (int) $absoluteMinutes;
}
if ([] !== $sessionLimits) {
    $sesameConfig['session'] = $sessionLimits;
}

$config = Config::fromArray($sesameConfig);

// One session port for SESAME and its driver: the cas driver keeps a
// pending-login marker in it before it sends the browser to CAS, so a
// ticket is only accepted for a sign-in this browser started (SESAME 0.5).
$session = new NativeSession();

// The cas driver checks each ticket with the CAS server over a PSR-18
// client. Guzzle has no total timeout by default, so set one; redirects
// stay off (SESAME treats any non-200 answer as a failed sign-in) and TLS
// verification stays on. The fake driver ignores both.
$http = new Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    'allow_redirects' => false,
    'verify' => true,
]);

$sesame = new Sesame(
    DriverFactory::make($config, http: $http, requests: new Psr17Factory(), session: $session),
    new class implements UserResolver {
        /**
         * The user the Vue app sees at /api/me. Keep it to what the page
         * needs: everything here reaches the browser.
         *
         * @return array{username: string, name: string}
         */
        public function findOrImportUser(Identity $identity): array
        {
            return [
                'username' => $identity->username,
                'name' => $identity->attribute('cn') ?? $identity->username,
            ];
        }
    },
    $session,
    $config,
);
sesame($sesame);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// The JSON API. The Vue app calls it with the session cookie, so it answers
// 401 instead of sending a fetch() off to the CAS server. Add an endpoint
// as one more case below; anything that writes should be a POST that
// checks the request's origin (see HELMET's csrf option).
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $user = sesame_user();
    if (null === $user) {
        http_response_code(401);
        echo json_encode(['message' => 'Sign in first.']);

        return;
    }

    switch ($path) {
        case '/api/me':
            echo json_encode($user, JSON_THROW_ON_ERROR);
            break;
        default:
            http_response_code(404);
            echo json_encode(['message' => 'No such endpoint.']);
    }

    return;
}

// Every page signs in first, then Vue takes over. One home page per app:
// any path but / answers 404, and the Vue app says so inside the layout.
// /up is HELMET's and answers before any of this runs.
sesame_user() ?? sesame_login();
if ('/' !== $path) {
    http_response_code(404);
}

$nonce = htmlspecialchars($helmet['nonce'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="wsu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <!-- Vite reads the nonce from here for the styles it injects. -->
    <meta property="csp-nonce" nonce="<?= $nonce ?>">
    <!-- WayneUI's pre-paint theme script: dark-mode readers never see a flash of light. -->
    <script nonce="<?= $nonce ?>">
        (function () {
            try {
                var stored = localStorage.getItem('wsu-theme');
                var dark = stored === 'wsu-dark' || (stored !== 'wsu' && matchMedia('(prefers-color-scheme: dark)').matches);
                var root = document.documentElement;
                root.setAttribute('data-theme', dark ? 'wsu-dark' : 'wsu');
                root.classList.add(dark ? 'dark' : 'light');
                root.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>
    <title>{{app_name}}</title>
    <?= $vite->tags('resources/js/app.js', $helmet['nonce']) ?>
</head>
<body>
    <div id="app" data-app-name="{{app_name}}" data-env="<?= htmlspecialchars($appEnv, ENT_QUOTES, 'UTF-8') ?>">
        <noscript>{{app_name}} needs JavaScript.</noscript>
    </div>
</body>
</html>
