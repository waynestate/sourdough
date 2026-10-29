# MAINTAINING: feeding the starter

How to build, test, release and keep Sourdough alive. Sourdough makes a Wayne State app with the stack already wired
in and is meant to own that wiring for the app's whole life. Built today: `new` and `new --describe`, plus `manifest`.
Planned: `adopt` and `feed`. Like any starter, it
needs regular feeding, or it goes flat.

## What Sourdough is and what it depends on

- `waynestate/sourdough` on Packagist, with the CLI `bin/sourdough`.
- Runtime dependencies: `symfony/console`, `symfony/http-client`, `justinrainbow/json-schema`, `openai-php/client`
  (only for `new --describe`, which can ask an OpenAI-compatible model for a plan; optional). No other stack package
  at runtime.
- **Generated apps** require `waynestate/helmet`, `waynestate/sesame` and `waynestate/proctor` from Packagist.
- **It carries a copy of Squire's templates** in `templates/squire/` (`raw/`, `laravel/`, `static/`, `vue/`,
  `catalog.json`, `manifest.json`). Squire owns those files; Sourdough never hand-edits them. `composer squire-copy:sync`
  rewrites the copy from the installed `waynestate/squire` package (dev dependency `^0.3`), and
  `composer squire-copy:check` (part of `composer ci`) exits 1 when the committed copy has drifted.
- The planned `feed` will need only git on the developer's machine: it records the template commit an app came from
  and applies template changes with `git apply --3way`.

## Where it lives

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Fix code style | `composer fix` |
| Check the Squire copy | `composer squire-copy:check` |
| Refresh the Squire copy | `composer squire-copy:sync`, then commit `templates/squire/` |
| Try a generation | `php bin/sourdough new ./demo --raw --dry-run` |

With only Docker (the same lines work in PowerShell and sh):

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, the tests, Pint, PHPStan, the Squire copy check and `composer audit`.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.
- Green means `composer ci` passed on all three PHP versions on both.

## Refreshing the Squire templates

When Squire's templates change, regenerate the copy from a Squire checkout, then verify it:

```
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire --check
```

Commit the refreshed copy with the Squire commit in the message, and add a CHANGELOG line. Until `feed` exists, apps
generated earlier pick up template changes by hand.

## What belongs here

If every app should have something, it goes in Sourdough's templates. If only the Laravel + Inertia/Vue shape (WELD)
should have it, it lives in WELD. Version rules belong to CREED, checks to PROCTOR, Citadel scripts to Squire.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading.
2. `composer ci` and the Squire copy check green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.

## The front page

`site/` is the static front page, for `https://sourdough.apps.wayne.edu/` on Citadel, served through a small raw-PHP
`deploy/` layer generated with Squire.

## Things that trip people up

- In 0.1 only `--raw` generates; other permutations exit 1 for now.
- Commit `.sourdough/manifest.json` in generated apps; the planned `feed` will need it.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, deploy
access): ask Enterprise Applications.
