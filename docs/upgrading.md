---
title: Upgrading
description: How to upgrade Sourdough itself and bring the apps it baked up to date with the manifest and sourdough feed.
---

# Upgrading

How to move the Sourdough CLI to a new release, and what each release changes for the apps it bakes. Upgrading the CLI does not rewrite apps you already baked: after the upgrade, run `sourdough feed --dry-run`, then `sourdough feed`, in each app to bring the new release's template changes in, merged with your own. The `.sourdough/manifest.json` in each app is the record it works from. See [Keeping apps current](guides/keeping-current.md).

## Upgrade the CLI

```bash tab="composer global require"
composer global require waynestate/sourdough
```

```bash tab="composer create-project"
composer create-project waynestate/sourdough
```

Verify with:

```bash
sourdough --version
```

Expected for the current release:

```
Sourdough 0.2.0
```

Then read the section for the version you are moving to.

## Unreleased

**Breaking:** for apps you feed, in two cases. First, the fed `composer.json` moves PROCTOR to `^0.8`, whose `crlf-deploy-script` check fails an app whose `build.sh`, `release.sh` or a `.sh` under `deployments/`, `deploy/` or `scripts/` has Windows line endings (CRLF). Such a script was already broken on the Linux host; convert it to LF and add `*.sh text eol=lf` to `.gitattributes`. PROCTOR 0.8 also adds `mysql-eol`, which only warns. Second, a front-end app's `frontend` job now fails on a lock file that does not match `package.json` and on a high or critical `npm audit` finding (below). **Deprecated:** none.

**Steps:** in each app, `sourdough feed --dry-run`, then `sourdough feed`, then `composer update waynestate/proctor --with-all-dependencies`, then `vendor/bin/proctor check`. See [PROCTOR's upgrading notes](https://proctor.apps.wayne.edu/docs/upgrading.html).

**Front-end shapes: the pipeline installs from the lock file.** Feed moves the `frontend` job to `npm ci --ignore-scripts` and `npm audit --audit-level=high`. `npm ci` needs a `package-lock.json` that matches `package.json`: an app that has none gets the template's (a seeded file, written only where you have none), and an app that already commits one keeps it. If you added or changed a dependency, run `npm install` once and commit both files before you push. A high or critical advisory now fails the job: update the package (`npm audit fix`, or `npm install <package>@<fixed version>`), then commit the lock.

**Laravel shapes: `config/helmet.php` shrinks to the app's own override.** Feed brings a short `config/helmet.php` that sets only `policy_path`; every other HELMET setting comes from the package's own config. If you never changed the file, feed replaces it and nothing else changes, except that the app now gets HELMET's current defaults: `HELMET_PRESET` can come from Playbook, a set-but-empty `HELMET_PROXIES` trusts no proxy (as HELMET documents), and the newer settings (`HELMET_SESSION_SECURE`, `HELMET_WARN_DEBUG`, the metrics storage) work. If you changed it, feed marks a conflict: keep only the keys you changed, each with the package's whole value for that key, and drop the rest. The full list is in `vendor/waynestate/helmet/config/helmet.php`.

**New bakes take their PHP floor from CREED.** Nothing changes for an app today: the floor is still `^8.3`, tested on 8.3, 8.4 and 8.5. When CREED's policy moves the floor, the next Sourdough release bakes the new one and feed brings it in. `sourdough new` prints the floor, where it came from and the shape's support line; see [Support and sunset](guides/support.md). **Deprecated:** none.

## 0.2.0 (2026-11-08)

**Breaking:** for apps you feed, in two cases only. The fed pipeline pins Preflight 0.3.0, which needs GitLab 18.7 or later (git.wayne.edu is newer), and the fed `composer.json` moves PROCTOR to `^0.7`, whose `node-eol` check fails an app that pins Node 20 or older. **Deprecated:** none.

This is a minor, not a patch: it adds the saved plan and YAML plans, and feeding it changes an app's pipeline, deploy kit and stack pins.

**What `sourdough feed` brings an app baked with 0.1.7 or earlier** (it merges each file with your edits; see the steps below):

- `.gitlab-ci.yml`: the Preflight components pinned to the 0.3.0 SHA with `image_prefix: "${IMAGE_PREFIX}"`, the `IMAGE_PREFIX` workflow rule, and the `.composer-cache` and `.npm-cache` download caches.
- The Squire 0.6.0 deploy kit (`scripts/release.sh` asks `proctor gate`, `.env.squire.example` lists `SQUIRE_PROCTOR_RECORD_DIR`), plus the `scripts/parity.ps1` and fallback image fixes for host uids and gids.
- `composer.json`: HELMET `^0.4`, SESAME `^0.5`, PROCTOR `^0.7` and Squire `^0.6`; the raw shapes add `guzzlehttp/guzzle` and `nyholm/psr7`.
- Raw shapes (`--raw`, `--raw --vue`): `public/index.php` hands the cas driver an HTTP client, a request factory and the session, so CAS sign-in works in production.
- Laravel shapes: `config/sesame.php` names SESAME 0.5's `cas.require_pending_login` (leave it on).
- Apps baked with `--docker`: the compose files publish the dev server and Vite on 127.0.0.1 only.

Feed does not bring the saved plan (`.sourdough/plan.yaml` is written by new bakes only) and does not touch your lock files, so the `composer update` in the steps is yours to run.

New bakes save their plan in the app as `.sourdough/plan.yaml`, next to the manifest, and `--plan` reads YAML plans as well as JSON, so the saved plan bakes the same loaf again. Commit it with the manifest. Apps you already baked have no saved plan, and feed does not write one: it is not in the manifest, so feed and `manifest --check` never touch it. See [The saved plan](reference/plan-format.md#the-saved-plan).

Every shape's `.gitlab-ci.yml` gains a `sourdough-feed` job that runs `sourdough manifest --check` and `sourdough feed --dry-run` on the newest Sourdough release. An app you already baked gets it with its next feed: `sourdough feed` adds the job to your `.gitlab-ci.yml`, merged with your own jobs. From then on a release the app has not fed shows as a warning in its pipeline (never a blocked deploy), hand edits to managed files are listed without failing, and a broken record (no manifest, conflict markers left from a feed) fails the job. `sourdough manifest --check` also forgives line endings now, as feed does, so a raw app's clone no longer reports `scripts/parity.ps1` (checked out with CRLF by the shape's `.gitattributes`) as changed. See [Feed in CI](guides/keeping-current.md#feed-in-ci).

Every shape's `.gitlab-ci.yml` now caches its downloads: Docker Hub images through `${IMAGE_PREFIX}` (the group's Dependency Proxy when the GitLab has one, Docker Hub otherwise) and Composer and npm installs per lock file. See [Dependency caching](guides/ci.md#dependency-caching).

Every shape pins the Preflight components to the 0.3.0 release SHA (`23038dfe767f6ef60e3b2efca1128f53d9acdff7`, was 0.1.4; Preflight 0.3.0 needs GitLab 18.7 or later). From 0.3.0 each Docker Hub component adds the registry prefix in front of its own image (a new `image_prefix` input), so the includes pass `image_prefix: "${IMAGE_PREFIX}"` and no `image` input: the components and the app's own jobs follow one proxy rule, and `IMAGE_PREFIX=docker.io` still sends both to Docker Hub. See [Preflight's upgrading notes](https://preflight.apps.wayne.edu/docs/upgrading.html).

New bakes start on the Squire 0.6.0 deploy kit (was 0.4.0): `scripts/release.sh` asks PROCTOR's deploy gate (`proctor gate`) for a go or no-go before the frontend build and the migrations and keeps the verdict in `storage/squire/` as the next release's baseline. Every shape requires HELMET `^0.4`, SESAME `^0.5` and PROCTOR `^0.7`. The raw shapes' `public/index.php` now hands the cas driver a Guzzle client, a request factory and the session, so they sign in with CAS in production (before, the cas driver refused to boot there).

**Steps:** in each app, on a clean git tree, `sourdough feed --dry-run`, then `sourdough feed`, then `composer update waynestate/helmet waynestate/sesame waynestate/proctor --with-all-dependencies` (the raw shapes add `guzzlehttp/guzzle nyholm/psr7` to that line). The feed also brings the Squire 0.6.0 kit files (they are in the manifest). Feed merges the new `.gitlab-ci.yml` with your own jobs; a job you added that pulls a Docker Hub image or installs packages takes the same pattern by hand (`image: ${IMAGE_PREFIX}/<image>` and `extends: .composer-cache` or `.npm-cache`). A Preflight include you added yourself takes the 0.3.0 SHA too, plus `image_prefix: "${IMAGE_PREFIX}"`; if it passes an `image` input, keep the plain name (`php:8.4-cli-trixie@sha256:<digest>`) and drop any `${IMAGE_PREFIX}/` or `${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/` in front of it, or the component adds the prefix a second time and the pull fails. If you changed the include lines feed manages and it stops on a conflict, take the template's side for the pin and the `image_prefix` line.

**How to verify:** the next pipeline's job logs show `Pulling docker image` with your GitLab's host in front, once, in the Preflight jobs as well as your own; each Preflight job's first line reads `Preflight component <name> 0.3.0`; and a second run logs `Successfully extracted cache`.

## 0.1.7 (2026-11-07)

**Breaking:** for new bakes, and for apps you feed. The Inertia shape moves to Inertia 3 (`inertiajs/inertia-laravel` `^3.0`, `@inertiajs/vue3` `^3.0`: PHP 8.2+, Laravel 11+, ES modules only, Axios no longer bundled). Every shape requires PROCTOR `^0.6`, and the Laravel shapes' `composer proctor` script drops `--policy`, because PROCTOR 0.6 refuses that option in CI.

**Deprecated:** none.

**Steps:** upgrade the CLI, then in each app, on a clean git tree:

```bash
sourdough feed --dry-run
sourdough feed
```

Feed merges the new pins into `composer.json` (and, in an Inertia app, `package.json`, `resources/views/app.blade.php` and `resources/js/app.ts`, which now hands HELMET's nonce to Inertia so the progress bar's styles pass the CSP) with your own edits. The lock files are yours, so move them next:

```bash tab="every shape"
composer update waynestate/proctor --with-all-dependencies
```

```bash tab="inertia"
composer update inertiajs/inertia-laravel waynestate/proctor --with-all-dependencies
npm install
php artisan view:clear
```

What to check by hand in an Inertia app, because feed only knows the template's code, not yours:

- Your own code imports `axios` (or `qs`, or `lodash-es`)? Inertia 3 no longer brings them along: `npm install axios` (or the one you use), or move the call to Inertia's `useHttp`.
- `Inertia::lazy()` becomes `Inertia::optional()`; `router.cancel()` becomes `router.cancelAll()`; the `invalid` and `exception` router events are now `httpException` and `networkError`.
- You published `config/inertia.php`? Its `testing.page_paths` and `testing.page_extensions` moved to `pages.paths` and `pages.extensions`. Republish with `php artisan vendor:publish --provider="Inertia\ServiceProvider" --force` and reapply your changes.
- Another head tag in your root view carries a bare `inertia` attribute? Rename it to `data-inertia`, as the template's `<title>` now is.

The full list is Inertia's own [upgrade guide](https://inertiajs.com/docs/v3/getting-started/upgrade-guide).

A pipeline that passes `--policy` or `--today` to PROCTOR fails with exit `2` on 0.6. Drop the option (PROCTOR finds CREED's policy in `vendor/waynestate/creed` by itself, or name it with the `PROCTOR_POLICY` variable), or set `PROCTOR_ALLOW_OVERRIDES: "1"` in a protected or group-level CI variable, never in the app's own `.gitlab-ci.yml`. PROCTOR 0.6 also fails `composer-audit` when there is no `composer.lock` or Composer is not on `PATH`, and when an audit ignore entry in `composer.json` has no matching override in `policy-overrides.json`.

**How to verify:** `composer proctor` passes. In an Inertia app, `npm ls @inertiajs/vue3` and `composer show inertiajs/inertia-laravel` both print a 3.x version, `npm run typecheck` and `npm run build` pass, and the page source of `/` carries `<script data-page="app" type="application/json">` instead of a `data-page` attribute on the root div.

## 0.1.6 (2026-11-06)

**Breaking:** for new bakes only; apps you already baked are not rewritten. `sourdough new` now requires Playbook (`waynestate/playbook`) in every app and checks that you can read it before writing anything, so a developer without Playbook access gets exit `1` until they have it (`--no-playbook` is for CI and automated tests only; `--playbook` is now a no-op kept for old scripts). The Inertia shape draws with WayneUI instead of the Tailwind starter kit, so a new Inertia app has no `tailwind.config.js`, `cn()` or kit components. The Laravel shapes generate apps on Pest 4.

**Deprecated:** none.

**Steps:** upgrade the CLI with `composer global require waynestate/sourdough`. The fifth loaf is out of the oven: `sourdough new --raw --vue` bakes a raw PHP + Vue SPA over JSON (Next steps: `composer install`, `npm install`, `npm run build`, `composer serve`), so all five shapes generate. Raw apps start with `composer serve` now; an app baked earlier can copy the raw template's `serve` script. Laravel apps baked earlier can pick up the fixes by hand: the `.gitlab-ci.yml` php-test matrix runs `vendor/bin/pest`, `--docker` apps run the vite service with `LARAVEL_SAIL=1` and install `node_modules` when Vite is missing, and `--sail` apps take the new compose entrypoint. See [Keeping apps current](guides/keeping-current.md).

**How to verify:** `sourdough --version` prints `Sourdough 0.1.6`. Bake `sourdough new --raw --vue`, run its Next steps, and `/`, `/api/me` and `/up` answer.

## 0.1.5 (2026-11-03)

**Breaking:** none. Three more loaves join the bench: `sourdough new --laravel` (Laravel + Blade), `sourdough new --inertia` (Laravel + Inertia/Vue) and `sourdough new --laravel --vue` (Laravel + Blade + Vue islands) now bake, so four of the five shapes generate. New bakes also carry ADVISOR by default (`waynestate/advisor` in `require-dev`, `.mcp.json` git-ignored, a README "ADVISOR" section); pass `--no-advisor` to skip it. The raw template pins WayneUI 2.0.8 instead of fetching the floating `main` branch copy, and the Squire copy comes from Squire 0.4.0 (dev dependency `^0.4`).

**Deprecated:** none.

**Steps:** upgrade the CLI with `composer global require waynestate/sourdough`. For maintainers of this repository, run `composer install` to pick up Squire 0.4.0, then `composer squire-copy:check`. `--name` on the Laravel bakes now reaches every place WELD applies it by hand: `composer.json`'s `name` becomes `app/<slug>` (an application is not a published package), `.env.example`'s `APP_NAME` carries the app name (quoted when it holds a space), and the `config/app.php` default follows it.

**How to verify:** `sourdough --version` prints `Sourdough 0.1.5`. Bake a shape and run its Next steps, for example `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan serve` for the Laravel shapes.

## 0.1.4 (2026-10-29)

**Breaking:** for maintainers of this repository, the Squire template copy in `templates/squire/` now comes from the `waynestate/squire` Composer package (new dev dependency, `^0.2`) instead of a Squire checkout: `composer squire-copy:sync` rewrites the copy from `vendor/waynestate/squire`, and `composer squire-copy:check` (now part of `composer ci`) exits 1 when the committed copy has drifted. Generated apps require SESAME `^0.2` (Entra sign-in).

**Deprecated:** none.

**Steps:** run `composer install` to pick up the new dev dependency, then `composer squire-copy:check`.

**How to verify:** `sourdough --version` prints `Sourdough 0.1.4`; `composer ci` is green; and `sourdough new my-app --laravel --inertia --dry-run` is accepted (the flag pair was fixed in this release).

## 0.1.3 (2026-10-29)

**Breaking:** none.

**Deprecated:** none.

**Steps:** upgrade the CLI. `sourdough new` now wires Playbook into generated apps: by default the app's `composer.json` gets a `suggest` for `waynestate/playbook`, `.env.example` lists the seven WSU shared setting names, the README gains a "WSU values (PLAYBOOK)" section, and the command prints a hint line pointing at it. `--playbook` adds the VCS repository and requires `waynestate/playbook` `^0.1`; `--no-playbook` skips all of it; the two flags together are refused with exit 1.

**How to verify:** `sourdough --version` prints `Sourdough 0.1.3`; bake with `sourdough new my-app --raw --dry-run` and inspect the plan for the Playbook entries.

## 0.1.2 (2026-10-29)

**Breaking:** none declared. Two behaviors tighten for new bakes: the raw template now requires HELMET `^0.2` and PROCTOR `^0.2` (SESAME stays at `^0.1` until it cuts 0.2), and app names that fail validation are refused with exit 1 and write nothing.

**Deprecated:** none.

**Steps:** upgrade the CLI. If a script passed an unusual name before, rename it: the name must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters.

**How to verify:** `sourdough --version` prints `Sourdough 0.1.2`; `sourdough new my-app --raw --dry-run` prints the plan, and a hostile name (for example one with a slash or over 63 characters) is refused with exit 1 and writes nothing.

## 0.1.1 (2026-10-29)

**Breaking:** none.

**Deprecated:** none.

**Steps:** run `composer install` to pick up the new dev dependency `waynestate/pinstripe` `^0.1`. PHPStan and Pint now take their settings from Pinstripe instead of a hand copy: `phpstan.neon` includes the raw preset, and `pint.json` extends the per preset. Same cut, off the rack.

**How to verify:** `composer lint` (Pint) and `composer analyse` (PHPStan) pass, and `composer ci` is green.

## 0.1.0 (2026-10-29)

**Breaking:** the raw template's own `deployments/release.sh` and `deployments/release.ps1` were removed; the release steps now come from Squire. The raw app installs Squire's deploy files from a checked copy at `templates/squire/`: `build.sh`, `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`, `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. Squire owns them; Sourdough copies them as-is and records them in the manifest.

**Deprecated:** none.

**Steps:** run `composer install`. For maintainers of this repository, mount a Squire checkout (or set `SQUIRE_PATH`, default `/squire`) so the copy check can compare against it; the check skips with exit 0 when no checkout is mounted. The Squire template copy was refreshed from e66f07b to 2a893c0 (the Vue layer's `.npmrc` and `docker-compose.squire.yml` joined the batch).

**How to verify:** `composer squire-copy:check` exits 0 when the copy matches a fresh export from the Squire checkout.

> [!TIP]
> Commit `.sourdough/manifest.json` in every app you bake, and `.sourdough/base/` once a feed writes it. They are the record that keeps `feed` honest, and the base it merges against.

## Related

- [Commands](reference/commands.md)
- [Templates](reference/templates.md)
- [Keeping apps current](guides/keeping-current.md)
- [Troubleshooting](troubleshooting.md)
- [Changelog](changelog.md)
