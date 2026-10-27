# Sourdough

A starter you feed: `sourdough new` makes a Wayne State app with the stack already wired in.

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

## Testing

From the repo root:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate --strict
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli php bin/sourdough new /tmp/demo --dry-run
```

## CI

Strongly recommended: a git.wayne.edu instance runner. See `design/instance-runner-case.md` in the coordination repo (`the stack`).
