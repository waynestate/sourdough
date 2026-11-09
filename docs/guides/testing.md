---
title: Testing
description: Run Sourdough's PHPUnit suite on PHP 8.3 and 8.4, validate the package, and fire the smoke gate that bakes a shape for real.
---

# Testing

This page is the proofing bench: how to run Sourdough's own checks day to day, and how to fire the smoke gate that bakes a shape for real before it may be claimed. Like any starter, Sourdough needs regular feeding, or it goes flat. For the wider runbook, continue to [Maintaining](../maintaining.md).

> [!NOTE]
> Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](../maintaining.md).

## Day-to-day commands

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Fix code style | `composer fix` |
| Check the Squire copy | `composer squire-copy:check` |
| Refresh the Squire copy | `composer squire-copy:sync`, then commit `templates/squire/` |
| Try a generation | `php bin/sourdough new ./demo --raw --dry-run` |

`composer ci` runs `composer validate --strict`, the tests, Pint, PHPStan, the Squire copy check, the agent-ready files check, the docs site check and `composer audit`. The tests fail on any PHP or PHPUnit deprecation (`failOnDeprecation` and `failOnPhpunitDeprecation` in `phpunit.xml.dist`), and the report names the test that raised it, the same gate every baked app gets.

## With only Docker

The same lines work in PowerShell and sh:

```
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

The `stack-composer-cache` volume keeps Composer's downloads between runs; Docker creates it the first time.

## From the repo root

```
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer validate --strict
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli php bin/sourdough new /tmp/demo --dry-run
```

## The smoke gate

The smoke gate is the proofing bench: it proofs one shape at a time before that shape may be claimed. It bakes the app with this checkout's own `bin/sourdough new <dir> --no-advisor --no-playbook --no-input` (the CLI under test is the one being shipped). Every bake passes `--no-playbook`, because Playbook is the default wiring but its package is private on git.wayne.edu and the gate must run credential-free.

Right after the bake, every shape proves its manifest and its bake: `sourdough manifest --check` must be clean, and with the fresh app committed to a scratch git repository `sourdough feed --dry-run` must find nothing to feed (a bake that is not stable would make every feed of it a conflict). The repository is dropped again before the app's own checks:

- `raw`: `composer install`, `composer test`, `composer proctor` and `composer ci`, then a live check: fetch the pinned WayneUI release once into `public/wayne-ui.php`, start the app with `APP_ENV=local` and expect `/` 200 with the app's home page in WayneUI's shell, `/nope` 404 and `/up` 200 (the front controller defaults `APP_ENV` to production when it is unset, so the server must set it)
- `raw-vue`: the raw steps plus `npm ci --ignore-scripts` (from the shipped `package-lock.json`) and `npm run build`, then the same live server, which must answer `/` with the built app mounted on `id="app"`, `/api/me` with the signed-in user (and `401` without a session), `/nope` with `404` and the app, and `/up` with `200`
- `blade`: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan test`, `composer proctor` and `composer ci`, then Laravel's own router (the one `php artisan serve` runs) must answer `/` with the Blade home page, `/dashboard` after SESAME's fake sign-in, `/nope` with `404` and `/up` with `200`
- `blade-vue`: the blade steps with `npm ci --ignore-scripts` and `npm run build` before the tests; `/` must also load the built assets and `/dashboard` carry the Vue island
- `inertia`: the blade-vue steps plus `npm run typecheck`; `/` must hand Vue the Inertia `Home` page and `/dashboard` the `Dashboard` page

With `--sail` or `--docker` after the shape (`smoke-shape.sh blade --docker`), a Laravel shape proves its oven instead: the scaffold's files are there, then `composer install`, `php artisan key:generate`, `php artisan test` and `composer proctor`. The gate never lights the oven: it has no Docker daemon.

With `SMOKE_FEED_FROM` set to a release (for example `0.1.6`), the gate also bakes the shape with that release from Packagist, commits it, and feeds it forward with this checkout: no conflict, a clean manifest afterwards, nothing left to feed. `scripts/proof.sh` (`scripts/proof.ps1` on Windows) runs every shape and every oven this way, one after another, and writes the Last run table on the [Proof](../reference/proof.md) page.

Each step prints `ok`; the first `FAIL` stops the run and shows the command's tail. A shape is claimed in the docs only when its smoke is green.

`tests/FeedCommandTest.php` covers `sourdough feed` itself on every shape (and with `--sail` and `--docker`), offline, in the PHPUnit suite: an app aged to an older template, one with local edits that merge cleanly, one with a conflict, a file that already has the template's new version, an oven app baked by 0.1.6, and the refusals. It needs git on PATH and skips without it.

Run it per OS. The image (`scripts/smoke.Dockerfile`) pins php 8.5, composer 2 and node 22, so the bake is identical everywhere:

```bash tab="linux"
docker build -f scripts/smoke.Dockerfile -t sourdough-smoke:local .
docker run --rm -v "$PWD:/repo:ro" -v /work -e SMOKE_WORK=/work sourdough-smoke:local sh /repo/scripts/smoke-shape.sh <shape>
```

```powershell tab="windows"
.\scripts\smoke-shape.ps1 <shape>
```

The PowerShell wrapper builds the same image once and reuses it. Pass `-WorkDir <dir>` to bind a local folder to `/work` so the scratch copy, the generated app and the per-step logs survive the run, `-Oven sail` or `-Oven docker` for an oven run, and `-FeedFrom <version>` to feed a release forward. On Windows, `.\scripts\smoke-shape.ps1 <shape>` runs it in one line.

Environment variables:

- `SMOKE_WORK`: the scratch dir for the copy, the app and the logs. Defaults to a fresh temp dir that is removed on exit.
- `SMOKE_KEEP`: set to `1` to keep the scratch dir behind for inspection.
- `SMOKE_FEED_FROM`: a released Sourdough to bake and feed forward (off when unset).
- `SMOKE_PREV_CACHE`: where that release is installed, so several runs share one copy.
- `SMOKE_PORT`: the live server's port (default `8099`).

## Proof the lot

`sh scripts/proof.sh` (Linux, macOS) and `.\scripts\proof.ps1` (Windows) run the gate on all eleven runs (five shapes, six ovens), feed the last release forward in each, and write the Last run table. What each run proves and the latest results are on the [Proof](../reference/proof.md) page.

> [!NOTE]
> On CI the gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof. The whole proof runs in the GitLab `proof` job, by hand or on a schedule that sets `SOURDOUGH_PROOF` to `1`, never on a push.

## Related

- [CI](./ci.md)
- [Pick a shape](./shapes.md)
- [Same kitchen, many ovens](./ovens.md)
- [Maintaining](../maintaining.md)
