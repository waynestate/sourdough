[![GitHub CI](https://github.com/waynestate/sourdough/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/sourdough/actions/workflows/ci.yml)

# Sourdough

A starter you feed: `sourdough new` makes a Wayne State app with the stack already wired in.

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Every loaf gets proofed before it ships.

This repository is published from Wayne State University's internal GitLab and mirrored here. Changes land there first; Wayne State developers open issues and merge requests on the internal project.

Sourdough is the app starter for the WSU stack. One command scaffolds a new app with SESAME (auth), HELMET (baseline runtime), PROCTOR (checks), and the PREFLIGHT CI components already in place. It is one PHP command, so it works on Windows, macOS, and Linux.

## Install

```
composer global require waynestate/sourdough
```

Or scaffold without installing, with `composer create-project waynestate/sourdough`.

## Usage

```
sourdough new <dir> [options]
```

Options:

- `--raw` use the raw PHP base
- `--laravel` use the Laravel base
- `--vue` add a Vue front end
- `--inertia` use Inertia (Laravel only)
- `--sail` use Laravel Sail for the local runtime (Laravel paths)
- `--docker` use a Docker image for the local runtime (Laravel paths)
- `--dry-run` print the plan and write nothing
- `--name` app name (default: the basename of `<dir>`)
- `--no-input` never prompt (Sourdough 0.1 has no prompts)

Exit codes: `0` done, `1` refused (invalid flags, non-empty target, not available), `2` tool error.

## Permutations

| Flags | Permutation |
|---|---|
| (none) or `--raw` | Raw PHP with WayneUI's standalone path |
| `--raw --vue` | Raw PHP + Vue SPA over JSON |
| `--laravel` | Laravel + Blade |
| `--laravel --vue` | Laravel + Blade + Vue islands |
| `--laravel --inertia` | Laravel + Inertia/Vue |

Only `--raw` generates in 0.1. The other permutations print the plan and exit `1` with "not available in this release"; later steps add them.

## The manifest

Every app Sourdough generates carries a `.sourdough/manifest.json` recording what was generated: the Sourdough version, the template, the answers you chose, and a sha256 hash of every file, each marked `managed` (Sourdough owns it and will update it) or `seeded` (Sourdough wrote it once and you own it now — `.env.example` and `README.md`).

The manifest is the starter's memory: it remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own. A later `sourdough feed` reads it to bring template updates into your app without clobbering what you've changed.

Until `feed` ships, `sourdough manifest --check` is the gate. Run it in your app to list the managed files that have drifted from the manifest:

```
sourdough manifest --check
```

Exit codes: `0` clean, `1` a managed file changed or is missing, `2` tool error (no manifest, or no `--check`). Seeded files are never reported — they're yours.

Commit the manifest. It's the record that keeps `feed` honest.

## Testing

From the repo root:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate --strict
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli php bin/sourdough new /tmp/demo --dry-run
```

## CI

Strongly recommended: a git.wayne.edu instance runner.
