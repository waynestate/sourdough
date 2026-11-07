#!/bin/sh
# proof.sh: proof every loaf. Bakes each Sourdough shape (and each Laravel
# shape in each oven) with this checkout, runs scripts/smoke-shape.sh on it,
# and writes the "Last run" table for docs/reference/proof.md. A baker who
# skips the proof gets a brick; this is the warm spot on top of the fridge.
#
# Usage:
#   sh scripts/proof.sh [run...]          Linux, macOS: runs it all in Docker
#   sh scripts/proof.sh --here [run...]   inside the smoke image, or on CI
#   (Windows: .\scripts\proof.ps1 [run...])
#
# A run is a shape (raw, raw-vue, blade, blade-vue, inertia) or a Laravel
# shape in an oven (blade+sail, blade+docker, blade-vue+sail,
# blade-vue+docker, inertia+sail, inertia+docker). No runs means all eleven.
#
# Environment:
#   PROOF_OUT        where the table, the summary and the logs go
#                    (default: ./proof-out)
#   PROOF_FEED_FROM  the released Sourdough to feed forward from (default: the
#                    newest release in CHANGELOG.md; "none" skips it)
#   PROOF_DATE       the date for the table (default: today)
#   PROOF_COMMIT     the commit for the table (default: git, then CI)
#
# Host mode needs git and docker. It proofs the committed tree plus any edits
# to tracked files (git stash create), never untracked files, and marks the
# commit "+ local edits" when there were any. --here needs what
# smoke-shape.sh needs: php, composer, git, curl, tar and node.
set -u

all_runs='raw raw-vue blade blade-vue inertia blade+sail blade+docker blade-vue+sail blade-vue+docker inertia+sail inertia+docker'
image=sourdough-smoke:local

here=0
if [ "${1:-}" = "--here" ]; then
    here=1
    shift
fi
runs=$*
if [ -z "$runs" ]; then
    runs=$all_runs
fi
for run in $runs; do
    case " $all_runs " in
        *" $run "*) ;;
        *)
            echo "proof.sh: unknown run \"$run\". Runs: $all_runs" >&2
            exit 2
            ;;
    esac
done

script_dir=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$script_dir/.." && pwd)

# Host mode: build the smoke image, hand it the committed tree as one tar
# file (no directory listing through a bind mount, which some Docker Desktop
# setups return partially) and run this script again inside, with --here.
if [ "$here" -eq 0 ]; then
    for tool in git docker; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            echo "proof.sh: $tool is not on PATH (or run it with --here inside the smoke image)" >&2
            exit 2
        fi
    done
    out=${PROOF_OUT:-$PWD/proof-out}
    mkdir -p "$out" || exit 2
    out=$(cd "$out" && pwd)
    commit=${PROOF_COMMIT:-$(git -C "$repo" rev-parse --short HEAD)}
    tree=$(git -C "$repo" stash create)
    if [ -n "$tree" ]; then
        commit="$commit + local edits"
    else
        tree=HEAD
    fi
    git -C "$repo" archive --format=tar -o "$out/source.tar" "$tree" || exit 2
    docker build -q -t "$image" - <"$script_dir/smoke.Dockerfile" >/dev/null || exit 2
    # shellcheck disable=SC2086 # runs are words on purpose
    docker run --rm \
        -v "$out:/out" \
        -e PROOF_OUT=/out \
        -e "PROOF_COMMIT=$commit" \
        -e "PROOF_DATE=${PROOF_DATE:-$(date +%Y-%m-%d)}" \
        -e "PROOF_FEED_FROM=${PROOF_FEED_FROM:-}" \
        "$image" \
        sh -c 'mkdir -p /src && tar -xf /out/source.tar -C /src && rm /out/source.tar && exec sh /src/scripts/proof.sh --here "$@"' proof $runs
    exit $?
fi

# --here: run every bake in this machine, one after another.
out=${PROOF_OUT:-$PWD/proof-out}
mkdir -p "$out/logs" || exit 2
out=$(cd "$out" && pwd)
work=${PROOF_WORK:-$(mktemp -d)}
mkdir -p "$work" || exit 2
trap 'rm -rf "$work"' EXIT

date_today=${PROOF_DATE:-$(date +%Y-%m-%d)}
commit=${PROOF_COMMIT:-}
if [ -z "$commit" ]; then
    commit=$(git -C "$repo" rev-parse --short HEAD 2>/dev/null || true)
fi
if [ -z "$commit" ]; then
    commit=${CI_COMMIT_SHORT_SHA:-unknown}
fi

# The release to feed forward from: the newest "## x.y.z (date)" heading in
# CHANGELOG.md, so the proof moves on with every release by itself.
feed_from=${PROOF_FEED_FROM:-}
if [ -z "$feed_from" ]; then
    feed_from=$(sed -n 's/^## \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\) .*/\1/p' "$repo/CHANGELOG.md" | head -n 1)
fi
if [ "$feed_from" = none ]; then
    feed_from=''
fi

# One download cache for every run: the ovens bake the same Laravel
# skeleton the shapes do.
export COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$work/cache/composer}"
export npm_config_cache="${npm_config_cache:-$work/cache/npm}"

table=$out/last-run.md
summary=$out/summary.txt
: >"$summary"
{
    echo "| Run | Date | Commit | Result | Duration |"
    echo "| --- | --- | --- | --- | --- |"
} >"$table"

{
    echo "Sourdough proof, $date_today, commit $commit"
    php -v | head -n 1
    composer --version 2>/dev/null | head -n 1
    echo "node $(node --version)"
    if [ -n "$feed_from" ]; then
        echo "feed forward from Sourdough $feed_from"
    fi
} | tee -a "$summary"

passed=0
failed=0
total_start=$(date +%s)
i=0
for run in $runs; do
    i=$((i + 1))
    shape=${run%%+*}
    oven=''
    case "$run" in
        *+*) oven="--${run#*+}" ;;
    esac
    slug=$(echo "$run" | tr '+' '-')
    label="$shape${oven:+ $oven}"
    run_log=$out/logs/$slug.txt
    run_work=$work/runs/$slug

    echo "== $label"
    start=$(date +%s)
    # shellcheck disable=SC2086 # the oven is one word or none
    SMOKE_REPO=$repo SMOKE_WORK=$run_work SMOKE_PORT=$((8100 + i)) \
        SMOKE_FEED_FROM=$feed_from SMOKE_PREV_CACHE=$work/prev \
        sh "$repo/scripts/smoke-shape.sh" "$shape" $oven >"$run_log" 2>&1
    status=$?
    seconds=$(($(date +%s) - start))

    oks=$(grep -c '^ok ' "$run_log")
    fails=$(grep -c '^FAIL' "$run_log")
    steps=$((oks + fails))
    suite=$(sed -n 's/^note suite: //p' "$run_log" | tail -n 1)
    feed=$(sed -n 's/^note feed: //p' "$run_log" | tail -n 1)
    if [ "$status" -eq 0 ]; then
        passed=$((passed + 1))
        result="pass: $oks of $steps steps"
    else
        failed=$((failed + 1))
        first_fail=$(grep -m 1 '^FAIL' "$run_log" | sed 's/^FAIL //')
        result="FAIL at \"$first_fail\" ($oks steps ok before it)"
    fi
    if [ -n "$suite" ]; then
        result="$result; suite: $suite"
    fi
    if [ -n "$feed_from" ] && [ "$status" -eq 0 ]; then
        result="$result; fed from $feed_from: ${feed:-ok}"
    fi

    command="sh scripts/smoke-shape.sh $label"
    echo "| \`$command\` | $date_today | \`$commit\` | $result | $seconds s |" >>"$table"
    echo "$label: $result ($seconds s)" | tee -a "$summary"
    if [ "$status" -ne 0 ]; then
        tail -n 45 "$run_log"
    fi

    # Keep the step logs, drop the loaves (node_modules weighs more than the
    # whole proof).
    if [ -d "$run_work/logs" ]; then
        mkdir -p "$out/logs/$slug"
        cp "$run_work"/logs/*.log "$out/logs/$slug/" 2>/dev/null
    fi
    rm -rf "$run_work"
done

total=$(($(date +%s) - total_start))
echo "proof: $passed passed, $failed failed, $total s in all" | tee -a "$summary"
echo "table: $table"
if [ "$failed" -gt 0 ]; then
    exit 1
fi
exit 0
