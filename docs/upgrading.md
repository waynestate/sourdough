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
Sourdough 0.1.7
```

Then read the section for the version you are moving to.

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

**Breaking:** the raw template's own `deployments/release.sh` and `deployments/release.ps1` were removed; the release steps now come from SQUIRE. The raw app installs SQUIRE's deploy files from a checked copy at `templates/squire/`: `build.sh`, `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`, `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. SQUIRE owns them; Sourdough copies them as-is and records them in the manifest.

**Deprecated:** none.

**Steps:** run `composer install`. For maintainers of this repository, mount a Squire checkout (or set `SQUIRE_PATH`, default `/squire`) so the copy check can compare against it; the check skips with exit 0 when no checkout is mounted. The SQUIRE template copy was refreshed from e66f07b to 2a893c0 (the Vue layer's `.npmrc` and `docker-compose.squire.yml` joined the batch).

**How to verify:** `composer squire-copy:check` exits 0 when the copy matches a fresh export from the SQUIRE checkout.

> [!TIP]
> Commit `.sourdough/manifest.json` in every app you bake, and `.sourdough/base/` once a feed writes it. They are the record that keeps `feed` honest, and the base it merges against.

## Related

- [Commands](reference/commands.md)
- [Templates](reference/templates.md)
- [Keeping apps current](guides/keeping-current.md)
- [Troubleshooting](troubleshooting.md)
- [Changelog](changelog.md)
