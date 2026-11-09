---
title: "Maintaining"
description: "How to build, test, release and keep Sourdough alive: the commands, CI, the smoke gate and the release steps."
---

# MAINTAINING: feeding the starter

How to build, test, release and keep Sourdough alive. Sourdough makes a Wayne State app with the stack already wired
in and is meant to own that wiring for the app's whole life. Built today: `new` and `new --describe`, `manifest` and `feed`.
Planned: `adopt`. Like any starter, it
needs regular feeding, or it goes flat.

## What Sourdough is and what it depends on

- `waynestate/sourdough` on Packagist, with the CLI `bin/sourdough`.
- Runtime dependencies: `symfony/console`, `symfony/http-client`, `justinrainbow/json-schema`, `openai-php/client`
  (only for `new --describe`, which can ask an OpenAI-compatible model for a plan; optional). No other stack package
  at runtime.
- **Generated apps** require `waynestate/helmet`, `waynestate/sesame` and `waynestate/proctor` from Packagist.
- **It carries a copy of Squire's templates** in `templates/squire/` (`raw/`, `laravel/`, `static/`, `vue/`,
  `catalog.json`, `manifest.json`). Squire owns those files; Sourdough never hand-edits them. `composer squire-copy:sync`
  rewrites the copy from the installed `waynestate/squire` package (dev dependency `^0.7`), and
  `composer squire-copy:check` (part of `composer ci`) exits 1 when the committed copy has drifted.
- `feed` needs only git on the developer's machine: it bakes the app's shape again with the manifest's answers and
  three-way merges each file with `git merge-file`, the base found by its manifest hash in `.sourdough/base/` or the
  app's git history (see `docs/guides/keeping-current.md`). `new` and `feed` bake through the same `Baker`, so a
  change to a generator reaches both.

## Where it lives

Development happens on git.wayne.edu, the canonical repository ([enterprise-apps/sourdough](https://git.wayne.edu/enterprise-apps/sourdough)). GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) is a read-only push mirror: `main` and tags are copied automatically, nobody pushes to it, and Packagist picks releases up from there. Changes, issues and merge requests go to git.wayne.edu.

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
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

The `stack-composer-cache` volume keeps Composer's downloads between runs; Docker creates it the first time.

`composer ci` runs `composer validate --strict`, the tests, Pint, PHPStan, the Squire copy check, the agent-ready files check, the docs site check and `composer audit`.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, pinned to Preflight 0.1.4: GitHub's copy of Preflight has no later tag yet, so the GitHub side stays there while GitLab runs 0.3.1.
- Green means `composer ci` passed on all three PHP versions on both.

## Smoke gate

The smoke gate is the proofing bench: it proofs one shape at a time before that shape may be claimed. It bakes the app with this checkout's own `bin/sourdough new <dir> --no-advisor --no-playbook --no-input` (the CLI under test is the one being shipped). Every bake passes `--no-playbook`, because Playbook is the default wiring but its package is private on git.wayne.edu and the gate must run credential-free.

Right after the bake, every shape proves its manifest and its bake: `sourdough manifest --check` must be clean, and with the fresh app committed to a scratch git repository `sourdough feed --dry-run` must find nothing to feed (a bake that is not stable would make every feed of it a conflict). The repository is dropped again before the app's own checks:

- `raw`: `composer install`, `composer test`, `composer proctor` and `composer ci`, then a live check: fetch the pinned WayneUI release once into `public/wayne-ui.php`, start the app with `APP_ENV=local` and expect `/` 200 with the app's home page in WayneUI's shell, `/nope` 404 and `/up` 200 (the front controller defaults `APP_ENV` to production when it is unset, so the server must set it)
- `raw-vue`: the raw steps plus `npm ci --ignore-scripts` (from the shipped `package-lock.json`) and `npm run build`, then the same live server, which must answer `/` with the built app mounted on `id="app"`, `/api/me` with the signed-in user (and `401` without a session), `/nope` with `404` and the app, and `/up` with `200`
- `blade`: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan test`, `composer proctor` and `composer ci`, then Laravel's own router (the one `php artisan serve` runs) must answer `/` with the Blade home page, `/dashboard` after SESAME's fake sign-in, `/nope` with `404` and `/up` with `200`
- `blade-vue`: the blade steps with `npm ci --ignore-scripts` and `npm run build` before the tests; `/` must also load the built assets and `/dashboard` carry the Vue island
- `inertia`: the blade-vue steps plus `npm run typecheck`; `/` must hand Vue the Inertia `Home` page and `/dashboard` the `Dashboard` page

With `--sail` or `--docker` after the shape (`smoke-shape.sh blade --docker`), a Laravel shape proves its oven instead: the scaffold's files are there, then `composer install`, `php artisan key:generate`, `php artisan test` and `composer proctor`. The gate never lights the oven: it has no Docker daemon.

With `SMOKE_FEED_FROM` set to a release (for example `0.1.6`), the gate also bakes the shape with that release from Packagist, commits it, and feeds it forward with this checkout: no conflict, a clean manifest afterwards, nothing left to feed. `scripts/proof.sh` (`scripts/proof.ps1` on Windows) runs every shape and every oven this way, one after another, and writes the Last run table on the [Proof](reference/proof.md) page.

Each step prints `ok`; the first `FAIL` stops the run and shows the command's tail. A shape is claimed in the docs only when its smoke is green.

Run it per OS. The image (`scripts/smoke.Dockerfile`) pins php 8.5, composer 2 and node 22, so the bake is identical everywhere:

Linux and macOS:

```
docker build -f scripts/smoke.Dockerfile -t sourdough-smoke:local .
docker run --rm -v "$PWD:/repo:ro" -v /work -e SMOKE_WORK=/work sourdough-smoke:local sh /repo/scripts/smoke-shape.sh <shape>
```

Windows PowerShell:

```
.\scripts\smoke-shape.ps1 <shape>
```

The PowerShell wrapper builds the same image once and reuses it. Pass `-WorkDir <dir>` to bind a local folder to `/work` so the scratch copy, the generated app and the per-step logs survive the run.

Environment variables:

- `SMOKE_WORK`: the scratch dir for the copy, the app and the logs. Defaults to a fresh temp dir that is removed on exit.
- `SMOKE_KEEP`: set to `1` to keep the scratch dir behind for inspection.
- `SMOKE_FEED_FROM`: a released Sourdough to bake and feed forward (off when unset).
- `SMOKE_PREV_CACHE`: where that release is installed, so several runs share one copy.
- `SMOKE_PORT`: the live server's port (default `8099`).

On CI the gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof. The whole proof runs in the GitLab `proof` job, by hand or on a schedule that sets `SOURDOUGH_PROOF` to `1`, never on a push.

## The local runtime output (Sail and Docker)

The `--sail` and `--docker` output is checked by tests and by the proof's oven runs: `tests/SailScaffoldTest.php` and `tests/DockerScaffoldTest.php` assert on the files each scaffold writes, including real-binary runs through `bin/sourdough`, and `scripts/proof.sh` bakes every Laravel shape in each oven, installs it and runs its suite and PROCTOR. No Docker daemon is part of either, so a bake with `--docker` is not proven to boot here. The plan is to add Docker smoke runs to the smoke gate once it can drive a compose stack (build the app image, start MySQL, run `php artisan migrate` and expect `/up` 200); until then the docs claim what the tests prove.

The base images in `templates/docker/Dockerfile` are pinned by manifest-list digest, so one pin resolves natively on amd64 and arm64. To bump a pin:

1. Run `docker buildx imagetools inspect <tag>` for the tag you want (for example `php:8.5-cli-trixie`) and copy the manifest-list digest, the `sha256:` value on the top `Digest:` line, not the per-architecture digests listed below it.
2. Replace the digest on the matching `FROM` line, add a CHANGELOG line, and commit.

A digest pin never moves on its own, so schedule these bumps (for example when a new patch release lands on the line) to pick up security fixes inside the image.

## Refreshing the Squire templates

When Squire's templates change, regenerate the copy from a Squire checkout, then verify it:

```
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire --check
```

Commit the refreshed copy with the Squire commit in the message, and add a CHANGELOG line. Apps generated earlier
pick up the refreshed files with their next `sourdough feed`.

## Refreshing a template's lock file

Each template that ships a `package.json` (`raw-vue`, `laravel-blade-vue`, `laravel-inertia`) ships the
`package-lock.json` that goes with it, so a fresh app passes `npm ci` on its first pipeline.
`tests/TemplateNpmLockTest.php` fails when the two drift. After editing a template's `package.json`, write its lock
again in the pinned Node image the pipelines use (`node:22.21.1-alpine3.22`, by digest), in a scratch folder that
holds a copy of that `package.json` and of `templates/squire/vue/.npmrc`:

```
sed -i 's/{{app_slug}}/app-slug-placeholder/' package.json
npm install --package-lock-only --ignore-scripts --no-audit --no-fund --before=<yesterday, as 2026-11-07T00:00:00Z>
sed 's/"app-slug-placeholder"/"{{app_slug}}"/g' package-lock.json > <repo>/templates/<shape>/package-lock.json
```

The stand-in name keeps npm away from the `{{app_slug}}` placeholder, which is not a valid package name. `--before`
keeps the one-day release cooldown the app's `.npmrc` sets, which npm 10 ignores. Then run `npm ci --ignore-scripts`,
`npm audit --audit-level=high` and `npm run build` on a bake of the shape (the smoke jobs run the install and the
build), and commit the lock with its `package.json`. Apps already baked keep their own lock: it is seeded.

## What belongs here

If every app should have something, it goes in Sourdough's templates. If only the Laravel + Inertia/Vue shape (WELD)
should have it, it lives in WELD. Version rules belong to CREED, checks to PROCTOR, Citadel scripts to Squire.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading.
2. `composer ci` and the Squire copy check green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu, with an approver. The push mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.

## The front page

`site/` is the static front page, for `https://sourdough.apps.wayne.edu/` on Citadel, served through a small raw-PHP
`deploy/` layer generated with Squire.

## Things that trip people up

- In 0.1 all five shapes generate. `--raw --vue` apps need `npm run build` (or `npm run dev`) before `/` renders: `src/Vite.php` refuses with a plain message when there is neither a build nor a dev server.
- Commit `.sourdough/manifest.json` (and `.sourdough/base/`) in generated apps; `feed` needs them.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, deploy
access): ask Enterprise Applications.
