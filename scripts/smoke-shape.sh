#!/bin/sh
# smoke-shape.sh: bake one Sourdough shape for real and run the generated
# app's own checks. A shape may only be claimed when this gate is green.
#
# Usage: smoke-shape.sh <raw|raw-vue|blade|blade-vue|inertia>
#
# Environment:
#   SMOKE_REPO  checkout of this repository to read from (default /repo)
#   SMOKE_WORK  scratch dir for the copy, the app and the logs
#               (default: a fresh mktemp -d, removed on exit)
#   SMOKE_KEEP  set to 1 to keep the scratch dir behind for inspection
#
# Needs php 8.5, composer 2, (for raw and raw-vue) curl and (for every shape
# but raw) node 22 on PATH, plus network access for Composer, npm and the
# WayneUI release download. No credentials, no Oracle connection.
set -u

shape=${1:-}
case "$shape" in
    raw | raw-vue | blade | blade-vue | inertia) ;;
    *)
        echo "usage: smoke-shape.sh <raw|raw-vue|blade|blade-vue|inertia>" >&2
        exit 2
        ;;
esac

repo=${SMOKE_REPO:-/repo}
if [ ! -f "$repo/bin/sourdough" ]; then
    echo "FAIL repo: $repo does not look like a sourdough checkout (no bin/sourdough)"
    exit 1
fi

# The gate runs credential-free: drop anything the host leaked in.
unset ORACLE_USERNAME ORACLE_PASSWORD ORACLE_TNS ORACLE_HOST ORACLE_PORT \
    ORACLE_SERVICE_NAME SESAME_CAS_BASE_URL SESAME_CAS_SERVICE_URL \
    SESAME_FAKE_USERNAME APP_KEY COMPOSER_AUTH

for tool in php composer tar; do
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
if [ "$shape" = raw ] || [ "$shape" = raw-vue ]; then
    if ! command -v curl >/dev/null 2>&1; then
        echo "FAIL preflight: curl is not on PATH"
        exit 1
    fi
fi

php -v | head -n 1
composer --version
if [ "$shape" != raw ]; then
    node --version
    npm --version
fi
if [ "$shape" = raw ] || [ "$shape" = raw-vue ]; then
    curl --version | head -n 1
fi

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
if [ "$keep" -eq 1 ]; then
    trap '' EXIT
    echo "note: keeping $work behind"
else
    trap 'rm -rf "$work"' EXIT
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
echo "ok copy checkout"

export COMPOSER_HOME="$work"/composer-home
mkdir -p "$COMPOSER_HOME"
dir=$src
step "sourdough composer install" composer install --no-interaction --no-progress

# 2. Bake the shape for real, with this checkout's own CLI. Playbook is the
# default wiring, but its package is private on git.wayne.edu and this gate
# runs credential-free, so every bake passes --no-playbook.
dir=$work
case "$shape" in
    raw)
        step "sourdough new raw" php "$src/bin/sourdough" new "$work/app" --raw --no-advisor --no-playbook --no-input
        ;;
    raw-vue)
        step "sourdough new raw-vue" php "$src/bin/sourdough" new "$work/app" --raw --vue --no-advisor --no-playbook --no-input
        ;;
    blade)
        step "sourdough new blade" php "$src/bin/sourdough" new "$work/app" --laravel --no-advisor --no-playbook --no-input
        ;;
    blade-vue)
        step "sourdough new blade-vue" php "$src/bin/sourdough" new "$work/app" --laravel --vue --no-advisor --no-playbook --no-input
        ;;
    inertia)
        step "sourdough new inertia" php "$src/bin/sourdough" new "$work/app" --inertia --no-advisor --no-playbook --no-input
        ;;
esac

# 2b. A fresh loaf has nothing to feed: sourdough feed bakes the same shape
# again (same skeleton, same answers) and must find every file as it is.
# A bake that is not reproducible would turn every feed of it into a
# conflict. The scratch repository goes again before the app's own checks,
# so they run exactly as they did before.
dir=$work/app
step "git commit the fresh bake" sh -c 'git init -q && git add -A && git -c user.name=smoke -c user.email=smoke@example.invalid -c commit.gpgsign=false commit -q -m baked'
step "sourdough feed --dry-run finds nothing to feed" php "$src/bin/sourdough" feed --dry-run
step "drop the scratch repository" rm -rf .git

# 3. Run the generated app's own checks.
dir=$work/app
case "$shape" in
    raw)
        step "composer install" composer install --no-interaction --no-progress
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

        n=$((n + 1))
        log=$logs/$n.log
        # Absolute docroot: the gate's own cwd is not the app dir.
        APP_ENV=local php -S 127.0.0.1:8099 -t "$dir/public" >"$log" 2>&1 &
        server_pid=$!

        up=''
        i=0
        while [ "$i" -lt 10 ]; do
            if curl -s -o /dev/null "http://127.0.0.1:8099/up"; then
                up=1
                break
            fi
            i=$((i + 1))
            sleep 1
        done
        if [ -z "$up" ]; then
            echo "FAIL raw server answers /up within 10s"
            echo "--- tail of raw server ($log) ---"
            tail -n 40 "$log"
            kill "$server_pid" 2>/dev/null
            exit 1
        fi
        echo "ok raw server answers /up within 10s"

        check_raw_http() {
            label=$1
            path=$2
            want=$3
            got=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8099$path")
            if [ "$got" = "$want" ]; then
                echo "ok $label"
                return 0
            fi
            echo "FAIL $label: got $got, want $want"
            echo "--- tail of raw server ($log) ---"
            tail -n 40 "$log"
            kill "$server_pid" 2>/dev/null
            exit 1
        }
        check_raw_http "raw GET / answers 200" "/" 200
        check_raw_http "raw GET /nope answers 404" "/nope" 404
        check_raw_http "raw GET /up answers 200" "/up" 200

        kill "$server_pid" 2>/dev/null
        ;;
    raw-vue)
        step "composer install" composer install --no-interaction --no-progress
        step "composer ci" composer ci
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build

        # Live check, as for raw: APP_ENV=local so SESAME uses its fake
        # driver. The page must load the built Vue app, and /api/me must
        # answer JSON on the session the page started (401 without one).
        n=$((n + 1))
        log=$logs/$n.log
        APP_ENV=local php -S 127.0.0.1:8099 -t "$dir/public" >"$log" 2>&1 &
        server_pid=$!
        jar=$work/cookies.txt

        up=''
        i=0
        while [ "$i" -lt 10 ]; do
            if curl -s -o /dev/null "http://127.0.0.1:8099/up"; then
                up=1
                break
            fi
            i=$((i + 1))
            sleep 1
        done
        if [ -z "$up" ]; then
            echo "FAIL raw-vue server answers /up within 10s"
            echo "--- tail of raw-vue server ($log) ---"
            tail -n 40 "$log"
            kill "$server_pid" 2>/dev/null
            exit 1
        fi
        echo "ok raw-vue server answers /up within 10s"

        # check_vue_http LABEL PATH WANT_CODE [WANT_BODY_TEXT]
        check_vue_http() {
            label=$1
            path=$2
            want=$3
            body=$work/body.txt
            got=$(curl -s -L -c "$jar" -b "$jar" -o "$body" -w '%{http_code}' "http://127.0.0.1:8099$path")
            if [ "$got" = "$want" ] && { [ -z "${4:-}" ] || grep -q -- "$4" "$body"; }; then
                echo "ok $label"
                return 0
            fi
            echo "FAIL $label: got $got, want $want${4:+ with \"$4\"}"
            head -c 2000 "$body"
            echo "--- tail of raw-vue server ($log) ---"
            tail -n 40 "$log"
            kill "$server_pid" 2>/dev/null
            exit 1
        }
        check_vue_http "raw-vue GET /api/me without a session answers 401" /api/me 401 '"message"'
        check_vue_http "raw-vue GET / answers 200 and loads the built app" / 200 'src="/build/assets/'
        check_vue_http "raw-vue GET /api/me answers the signed-in user" /api/me 200 '"username":"jdoe"'
        check_vue_http "raw-vue GET /nope answers 404 with the app" /nope 404 'id="app"'
        check_vue_http "raw-vue GET /up answers 200" /up 200

        kill "$server_pid" 2>/dev/null
        ;;
    blade)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "php artisan migrate" php artisan migrate
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
    blade-vue)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "php artisan migrate" php artisan migrate
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
    inertia)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "php artisan migrate" php artisan migrate
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build
        step "npm run typecheck" npm run typecheck
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
esac

echo "ok smoke $shape: every step passed"
