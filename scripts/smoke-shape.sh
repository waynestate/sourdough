#!/bin/sh
# smoke-shape.sh: bake one Sourdough shape for real and run the generated
# app's own checks. A shape may only be claimed when this gate is green.
#
# Usage: smoke-shape.sh <raw|blade|blade-vue|inertia>
#
# Environment:
#   SMOKE_REPO  checkout of this repository to read from (default /repo)
#   SMOKE_WORK  scratch dir for the copy, the app and the logs
#               (default: a fresh mktemp -d, removed on exit)
#   SMOKE_KEEP  set to 1 to keep the scratch dir behind for inspection
#
# Needs php 8.5, composer 2 and (for blade, blade-vue and inertia) node 22 on
# PATH, plus
# network access for Composer and npm. No credentials, no Oracle connection.
set -u

shape=${1:-}
case "$shape" in
    raw | blade | blade-vue | inertia) ;;
    *)
        echo "usage: smoke-shape.sh <raw|blade|blade-vue|inertia>" >&2
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

php -v | head -n 1
composer --version
if [ "$shape" != raw ]; then
    node --version
    npm --version
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

# 2. Bake the shape for real, with this checkout's own CLI.
dir=$work
case "$shape" in
    raw)
        step "sourdough new raw" php "$src/bin/sourdough" new "$work/app" --raw --no-advisor --no-input
        ;;
    blade)
        step "sourdough new blade" php "$src/bin/sourdough" new "$work/app" --laravel --no-advisor --no-input
        ;;
    blade-vue)
        step "sourdough new blade-vue" php "$src/bin/sourdough" new "$work/app" --laravel --vue --no-advisor --no-input
        ;;
    inertia)
        step "sourdough new inertia" php "$src/bin/sourdough" new "$work/app" --inertia --no-advisor --no-input
        ;;
esac

# 3. Run the generated app's own checks.
dir=$work/app
case "$shape" in
    raw)
        step "composer install" composer install --no-interaction --no-progress
        step "composer ci" composer ci
        ;;
    blade)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
    blade-vue)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
    inertia)
        step "composer install" composer install --no-interaction --no-progress
        step "cp .env.example .env" cp .env.example .env
        step "php artisan key:generate" php artisan key:generate
        step "npm install" npm install --no-audit --no-fund
        step "npm run build" npm run build
        step "npm run typecheck" npm run typecheck
        step "php artisan test" php artisan test
        step "composer ci" composer ci
        ;;
esac

echo "ok smoke $shape: every step passed"
