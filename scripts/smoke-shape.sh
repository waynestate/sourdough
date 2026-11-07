#!/bin/sh
# smoke-shape.sh: bake one Sourdough shape for real and run the generated
# app's own checks. A shape may only be claimed when this gate is green.
# scripts/proof.sh runs it for every shape and oven and keeps the score.
#
# Usage: smoke-shape.sh <raw|raw-vue|blade|blade-vue|inertia> [--sail|--docker]
#
# A shape on its own gets the full bake: install, its own test suite, PROCTOR,
# composer ci, then a live server that must answer its front page with the
# shape's own marker. With --sail or --docker (the Laravel shapes only) the
# gate proves the oven instead: the scaffold's files are there, the app still
# installs, passes its own suite and PROCTOR. Booting an oven needs a Docker
# daemon, which this gate never has.
#
# Environment:
#   SMOKE_REPO        checkout of this repository to read from (default /repo)
#   SMOKE_WORK        scratch dir for the copy, the app and the logs
#                     (default: a fresh mktemp -d, removed on exit)
#   SMOKE_KEEP        set to 1 to keep the scratch dir behind for inspection
#   SMOKE_FEED_FROM   a released Sourdough version (for example 0.1.6): also
#                     bake the shape with that release from Packagist, commit
#                     it, and feed it forward with this checkout's sourdough
#                     feed. Off when unset.
#   SMOKE_PREV_CACHE  where that release is installed, so several runs share
#                     one copy (default: inside SMOKE_WORK)
#   SMOKE_PORT        the port the live server listens on (default 8099)
#
# Needs php 8.5, composer 2, curl, git and (for every shape but raw) node 22
# on PATH, plus network access for Composer, npm and the WayneUI release
# download. No credentials, no Oracle connection.
set -u

shape=${1:-}
oven=${2:-}
usage="usage: smoke-shape.sh <raw|raw-vue|blade|blade-vue|inertia> [--sail|--docker]"
case "$shape" in
    raw | raw-vue | blade | blade-vue | inertia) ;;
    *)
        echo "$usage" >&2
        exit 2
        ;;
esac
case "$oven" in
    '') ;;
    --sail | --docker)
        case "$shape" in
            raw | raw-vue)
                echo "$oven is for the Laravel shapes: the raw shapes run on plain PHP." >&2
                exit 2
                ;;
        esac
        ;;
    *)
        echo "$usage" >&2
        exit 2
        ;;
esac
# The name the proof table uses: blade, or blade --docker.
run_name=$shape${oven:+ $oven}

repo=${SMOKE_REPO:-/repo}
if [ ! -f "$repo/bin/sourdough" ]; then
    echo "FAIL repo: $repo does not look like a sourdough checkout (no bin/sourdough)"
    exit 1
fi

# The gate runs credential-free: drop anything the host leaked in.
unset ORACLE_USERNAME ORACLE_PASSWORD ORACLE_TNS ORACLE_HOST ORACLE_PORT \
    ORACLE_SERVICE_NAME SESAME_CAS_BASE_URL SESAME_CAS_SERVICE_URL \
    SESAME_FAKE_USERNAME APP_KEY COMPOSER_AUTH

for tool in php composer tar curl git; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "FAIL preflight: $tool is not on PATH"
        exit 1
    fi
done
if [ "$shape" != raw ]; then
    for tool in node npm; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            echo "FAIL preflight: $tool is not on PATH"
            exit 1
        fi
    done
fi

php -v | head -n 1
composer --version
if [ "$shape" != raw ]; then
    node --version
    npm --version
fi
curl --version | head -n 1

keep=1
if [ -n "${SMOKE_WORK:-}" ]; then
    work=$SMOKE_WORK
    mkdir -p "$work" || exit 1
else
    work=$(mktemp -d) || exit 1
    keep=0
fi
if [ "${SMOKE_KEEP:-0}" = "1" ]; then
    keep=1
fi

port=${SMOKE_PORT:-8099}
server_pid=''
stop_server() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" 2>/dev/null
        wait "$server_pid" 2>/dev/null
        server_pid=''
    fi
}
if [ "$keep" -eq 1 ]; then
    trap 'stop_server' EXIT
    echo "note: keeping $work behind"
else
    trap 'stop_server; rm -rf "$work"' EXIT
fi

logs=$work/logs
mkdir -p "$logs"

n=0
dir=$work

# step LABEL COMMAND...
# Runs COMMAND in $dir, tees output to $logs/$n.log and prints one ok/FAIL
# line. Stops the gate at the first failure with the command's tail.
step() {
    label=$1
    shift
    n=$((n + 1))
    log=$logs/$n.log
    if (cd "$dir" && "$@" >"$log" 2>&1); then
        echo "ok $label"
    else
        echo "FAIL $label"
        echo "--- tail of $label ($log) ---"
        tail -n 40 "$log"
        exit 1
    fi
}

# suite_step LABEL COMMAND...
# A step that runs a test suite: on success its ok line carries the suite's
# own count, read from PHPUnit's "OK (5 tests, 9 assertions)" or Pest's
# "Tests: 5 passed (9 assertions)", so the proof table can quote it.
suite_step() {
    step "$@"
    counts=$(sed -n -E \
        -e 's/.*OK \(([0-9]+) tests?, ([0-9]+) assertions?\).*/\1 tests, \2 assertions/p' \
        -e 's/.*Tests:.* ([0-9]+) passed \(([0-9]+) assertions?\).*/\1 tests, \2 assertions/p' \
        "$log" | tail -n 1)
    if [ -n "$counts" ]; then
        echo "note suite: $counts"
    fi
}

# start_server LABEL COMMAND...
# Starts the app's server in the background from $dir, then waits up to 20s
# for /up to answer.
start_server() {
    label=$1
    shift
    n=$((n + 1))
    server_log=$logs/$n.log
    (cd "$dir" && exec "$@") >"$server_log" 2>&1 &
    server_pid=$!
    i=0
    while [ "$i" -lt 20 ]; do
        if curl -s -o /dev/null "http://127.0.0.1:$port/up"; then
            echo "ok $label answers /up within 20s"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    echo "FAIL $label answers /up within 20s"
    echo "--- tail of $label ($server_log) ---"
    tail -n 40 "$server_log"
    exit 1
}

# check_http LABEL PATH WANT_CODE [WANT_BODY_TEXT]
# Fetches PATH from the live server (following redirects, with one cookie
# jar per run so a fake sign-in sticks) and checks the status and, when
# given, a fixed string in the body.
jar=$work/cookies.txt
check_http() {
    label=$1
    path=$2
    want=$3
    body=$work/body.txt
    got=$(curl -s -L -c "$jar" -b "$jar" -o "$body" -w '%{http_code}' "http://127.0.0.1:$port$path")
    if [ "$got" = "$want" ] && { [ -z "${4:-}" ] || grep -F -q -- "$4" "$body"; }; then
        echo "ok $label"
        return 0
    fi
    echo "FAIL $label: got $got, want $want${4:+ with \"$4\"}"
    head -c 2000 "$body"
    echo
    echo "--- tail of the server ($server_log) ---"
    tail -n 40 "$server_log"
    exit 1
}

# 1. Copy the checkout to scratch (the mount is read-only) and install it,
# so the CLI that bakes the shape is the one under test.
src=$work/sourdough
mkdir -p "$src"
if ! tar -C "$repo" --exclude=.git --exclude=vendor --exclude=node_modules \
        --exclude=.delegate-context -cf "$logs/copy.tar" . 2>"$logs/copy.err"; then
    echo "FAIL copy checkout"
    tail -n 40 "$logs/copy.err"
    exit 1
fi
if ! tar -C "$src" -xf "$logs/copy.tar" 2>>"$logs/copy.err"; then
    echo "FAIL copy checkout"
    tail -n 40 "$logs/copy.err"
    exit 1
fi
rm -f "$logs/copy.tar"
echo "ok copy checkout"

# COMPOSER_CACHE_DIR, when the caller sets one, still wins over this home,
# so the proof's runs share one download cache.
export COMPOSER_HOME="$work"/composer-home
mkdir -p "$COMPOSER_HOME"
dir=$src
step "sourdough composer install" composer install --no-interaction --no-progress

# The flags that pick the shape, without the oven. Playbook is the default
# wiring, but its package is private on git.wayne.edu and this gate runs
# credential-free, so every bake passes --no-playbook.
case "$shape" in
    raw) shape_flags='--raw' ;;
    raw-vue) shape_flags='--raw --vue' ;;
    blade) shape_flags='--laravel' ;;
    blade-vue) shape_flags='--laravel --vue' ;;
    inertia) shape_flags='--inertia' ;;
esac

# 2. Bake the shape for real, with this checkout's own CLI.
dir=$work
# shellcheck disable=SC2086 # the flags are words on purpose
step "sourdough new $run_name" php "$src/bin/sourdough" new "$work/app" $shape_flags $oven --no-advisor --no-playbook --no-input

# 2a. The manifest is written after the last file lands, so a fresh loaf
# matches it exactly.
dir=$work/app
step "sourdough manifest --check is clean after the bake" php "$src/bin/sourdough" manifest --check

# 2b. A fresh loaf has nothing to feed: sourdough feed bakes the same shape
# again (same skeleton, same answers) and must find every file as it is.
# A bake that is not reproducible would turn every feed of it into a
# conflict. The scratch repository goes again before the app's own checks,
# so they run exactly as they did before.
step "git commit the fresh bake" sh -c 'git init -q && git add -A && git -c user.name=smoke -c user.email=smoke@example.invalid -c commit.gpgsign=false commit -q -m baked'
step "sourdough feed --dry-run finds nothing to feed" php "$src/bin/sourdough" feed --dry-run
step "drop the scratch repository" rm -rf .git

# 2c. Feed forward from the last release: bake the same shape with the
# released Sourdough from Packagist, commit it as an app would, and let this
# checkout's sourdough feed bring it up to date. Yesterday's loaf, today's
# starter: the feed must land without a conflict, the manifest must check
# clean afterwards, and a second feed must find nothing left to do.
if [ -n "${SMOKE_FEED_FROM:-}" ]; then
    prev_root=${SMOKE_PREV_CACHE:-$work/prev}
    prev=$prev_root/sourdough-$SMOKE_FEED_FROM
    mkdir -p "$prev_root"
    dir=$prev_root
    if [ ! -f "$prev/bin/sourdough" ]; then
        rm -rf "$prev"
        step "install sourdough $SMOKE_FEED_FROM from Packagist" composer create-project "waynestate/sourdough:$SMOKE_FEED_FROM" "$prev" --no-dev --no-interaction --no-progress
    else
        echo "ok sourdough $SMOKE_FEED_FROM is already installed"
    fi
    dir=$work
    # shellcheck disable=SC2086
    step "sourdough $SMOKE_FEED_FROM new $run_name" php "$prev/bin/sourdough" new "$work/prev-app" $shape_flags $oven --no-advisor --no-playbook --no-input
    dir=$work/prev-app
    step "git commit the $SMOKE_FEED_FROM bake" sh -c 'git init -q && git add -A && git -c user.name=smoke -c user.email=smoke@example.invalid -c commit.gpgsign=false commit -q -m baked'
    step "sourdough feed brings the $SMOKE_FEED_FROM bake forward without a conflict" php "$src/bin/sourdough" feed
    # The feed's own summary line: "Fed: 1 update, 2 add."
    feed_counts=$(sed -n 's/^Fed: \(.*\)\.$/\1/p' "$log" | tail -n 1)
    if grep -q '^Fresh:' "$log"; then
        echo "note feed: nothing to feed from $SMOKE_FEED_FROM"
    elif [ -n "$feed_counts" ]; then
        echo "note feed: $feed_counts"
    fi
    step "sourdough manifest --check is clean after the feed" php "$src/bin/sourdough" manifest --check
    step "git commit the feed" sh -c 'git add -A && git -c user.name=smoke -c user.email=smoke@example.invalid -c commit.gpgsign=false commit -q --allow-empty -m fed'
    step "sourdough feed --dry-run finds nothing left to feed" php "$src/bin/sourdough" feed --dry-run
fi

# 3. Run the generated app's own checks.
dir=$work/app

# has FILE LABEL: the oven's scaffold wrote FILE.
has() {
    n=$((n + 1))
    if [ -f "$dir/$1" ]; then
        echo "ok $2"
    else
        echo "FAIL $2: $1 is missing"
        exit 1
    fi
}

# laravel_setup: the Next steps every Laravel shape prints, minus serve.
laravel_setup() {
    step "composer install" composer install --no-interaction --no-progress
    step "cp .env.example .env" cp .env.example .env
    step "php artisan key:generate" php artisan key:generate
}

if [ -n "$oven" ]; then
    case "$oven" in
        --sail)
            has compose.yaml "--sail wrote compose.yaml"
            step "--sail put laravel/sail in require-dev" grep -q '"laravel/sail"' composer.json
            ;;
        --docker)
            has Dockerfile "--docker wrote the Dockerfile"
            has compose.yaml "--docker wrote compose.yaml"
            has .dockerignore "--docker wrote .dockerignore"
            step "--docker added the dev-up script" grep -q '"dev-up"' composer.json
            ;;
    esac
    # The oven points .env.example at MySQL, which this gate does not run;
    # the test suite keeps to SQLite in memory, as phpunit.xml says.
    laravel_setup
    suite_step "php artisan test" php artisan test
    step "composer proctor" composer proctor
    echo "ok smoke $run_name: every step passed"
    exit 0
fi

case "$shape" in
    raw)
        step "composer install" composer install --no-interaction --no-progress
        suite_step "composer test" composer test
        step "composer proctor" composer proctor
        step "composer ci" composer ci

        # Live check: a baked raw app must actually serve. The front
        # controller defaults APP_ENV to production when it is unset, so the
        # server runs with APP_ENV=local, where SESAME uses its fake driver.
        #
        # public/wayne-ui.php is fetched once, as the app's README says. The
        # release URL is pinned in templates/raw/public/index.php; read it
        # from there so the gate and the template cannot drift apart.
        wayne_url=$(sed -n 's#.*\(https://wayneui\.apps\.wayne\.edu/v2/releases/[0-9][0-9.]*\/wayne-ui\.php\).*#\1#p' "$repo/templates/raw/public/index.php" 2>/dev/null | head -n 1)
        if [ -z "$wayne_url" ]; then
            # Fallback: the pinned 2.0.8 release copy.
            wayne_url='https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php'
        fi
        step "fetch WayneUI" curl -fsSL -o public/wayne-ui.php "$wayne_url"

        # Absolute docroot: the gate's own cwd is not the app dir.
        start_server "raw server" env APP_ENV=local php -S "127.0.0.1:$port" -t "$dir/public"
        check_http "raw GET / answers 200 with the app's home page" / 200 'app is running.'
        check_http "raw GET / wears WayneUI's shell" / 200 'wsu-'
        check_http "raw GET /nope answers 404" /nope 404
        check_http "raw GET /up answers 200" /up 200
        stop_server
        ;;
    raw-vue)
        step "composer install" composer install --no-interaction --no-progress
        suite_step "composer test" composer test
        step "composer proctor" composer proctor
        step "composer ci" composer ci
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build

        # Live check, as for raw: APP_ENV=local so SESAME uses its fake
        # driver. The page must load the built Vue app, and /api/me must
        # answer JSON on the session the page started (401 without one).
        start_server "raw-vue server" env APP_ENV=local php -S "127.0.0.1:$port" -t "$dir/public"
        check_http "raw-vue GET /api/me without a session answers 401" /api/me 401 '"message"'
        check_http "raw-vue GET / answers 200 and loads the built app" / 200 'src="/build/assets/'
        check_http "raw-vue GET / mounts the Vue app" / 200 'id="app"'
        check_http "raw-vue GET /api/me answers the signed-in user" /api/me 200 '"username":"jdoe"'
        check_http "raw-vue GET /nope answers 404 with the app" /nope 404 'id="app"'
        check_http "raw-vue GET /up answers 200" /up 200
        stop_server
        ;;
    blade | blade-vue | inertia)
        laravel_setup
        step "php artisan migrate" php artisan migrate
        if [ "$shape" != blade ]; then
            step "npm install" npm install --no-audit --no-fund
            step "npm run build" npm run build
        fi
        if [ "$shape" = inertia ]; then
            step "npm run typecheck" npm run typecheck
        fi
        suite_step "php artisan test" php artisan test
        step "composer proctor" composer proctor
        step "composer ci" composer ci

        # Live check: the same router php artisan serve starts, run directly
        # so the gate holds one process it can stop. APP_ENV=local comes from
        # .env, so SESAME signs in with its fake driver.
        start_server "$shape server" php -S "127.0.0.1:$port" -t "$dir/public" "$dir/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
        case "$shape" in
            blade)
                check_http "blade GET / answers 200 with the Blade home page" / 200 'baked fresh by Sourdough'
                check_http "blade GET /dashboard signs in with the fake driver" /dashboard 200 'jdoe'
                ;;
            blade-vue)
                check_http "blade-vue GET / answers 200 with the Blade home page" / 200 'baked fresh by Sourdough'
                check_http "blade-vue GET / loads the built islands" / 200 '/build/assets/'
                check_http "blade-vue GET /dashboard carries the Vue island" /dashboard 200 'data-vue-island="counter"'
                ;;
            inertia)
                check_http "inertia GET / answers 200 with the Inertia Home page" / 200 'Home'
                check_http "inertia GET / loads the built app" / 200 '/build/assets/'
                check_http "inertia GET /dashboard signs in with the fake driver" /dashboard 200 'Dashboard'
                ;;
        esac
        check_http "$shape GET /nope answers 404" /nope 404
        check_http "$shape GET /up answers 200" /up 200
        stop_server
        ;;
esac

echo "ok smoke $run_name: every step passed"
