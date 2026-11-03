# Changelog

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic
Versioning once 1.0 is out.

## Unreleased

## 0.1.5 (2026-11-03)

### Added

- Added: `sourdough new --laravel` bakes a Laravel + Blade app: a fresh Laravel skeleton from Composer, the laravel-blade overlay, the stack packages, Squire's laravel deploy files and the manifest. Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan serve`. One more loaf on the bench.
- Added: `sourdough new --inertia` bakes a Laravel + Inertia/Vue app: the same Laravel skeleton, the laravel-inertia overlay (Inertia pages rendered by Vue, Ziggy routes, the stack packages), Squire's laravel deploy files and the manifest. Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `npm install`, `npm run build`, `php artisan serve`. Three of the five loaves bake now.
- Added: `sourdough new --laravel --vue` bakes a Laravel + Blade + Vue islands app: the Laravel + Blade bake under a front-end layer (Vite with the Vue plugin, a `resources/js/app.js` registry that mounts a Vue 3 app on every `[data-vue-island]` element, and a Counter island live on the dashboard view). Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `npm install`, `npm run build`, `php artisan serve`. Four of the five loaves bake now.
- Added: the raw template ships `scripts` (`test`, `proctor`, `ci`) and `scripts-descriptions`, so a generated raw app can run its own gate locally with `composer ci`.
- Added: generated apps ship ADVISOR by default. `waynestate/advisor` lands in `require-dev` (raw template and the laravel-blade overlay), `.mcp.json` is git-ignored, the README gains an "ADVISOR" section, and the Next steps print `php vendor/bin/advisor install --client=claude --write`. `--no-advisor` skips all of it and records the choice in the manifest.
- Added: the smoke gate (`scripts/smoke-shape.sh`, `scripts/smoke-shape.ps1`, `scripts/smoke.Dockerfile`) bakes a shape with this checkout's own CLI and runs the generated app's own checks; CI runs one job per shape, all `allow_failure: true` until seen green once.

### Changed

- Changed: `--name` on the Laravel bakes reaches every place WELD applies it by hand: `composer.json`'s `name` becomes `app/<slug>` (an application is not a published package) with the description reading "<name>, a Wayne State app baked from Sourdough.", `.env.example`'s `APP_NAME` carries the app name (quoted when it holds a space), and the `config/app.php` default follows it.
- Changed: the raw template pins WayneUI 2.0.8 (`https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php`) instead of fetching the floating `main` branch copy.
- Changed: the raw template's `.gitlab-ci.yml` pins the Preflight components to released Preflight tags; the "not cut yet" note is gone.
- Changed: the Squire copy comes from Squire 0.4.0 (adds oracle-check.php to every target; the dev dependency is now ^0.4).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).
- Changed: the deploy layer's Squire kit is refreshed to 0.4.0 (deploy/oracle-check.php arrives; release.sh runs it without failing the release).
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Changed: the laravel-inertia template ships AppServiceProvider (string length 191 for Citadel's MySQL 5.6) and a tests/TestCase.php that writes a stub Vite manifest, so view tests boot without a build. WELD had both; now every Inertia app does.
- Changed: the laravel-inertia template's `.gitlab-ci.yml` gains the `ziggy` job WELD carries by hand: it regenerates `resources/js/ziggy.*` and fails on any diff, so the route list that ships inside the Vite bundle always matches the routes.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Changed: the front page tells the truth about the shapes. The Laravel + Blade, Laravel + Blade + Vue islands and Laravel + Inertia/Vue loaves come out of the oven with their real commands; the hero, the rack note and the two agent prompts name the four that bake in 0.1; and WELD reads as the Inertia/Vue shape already built from Sourdough, not the other starter. Only raw PHP with Vue stays proofing.

### Fixed

- Fixed: the laravel-inertia bake drops the skeleton leftovers before the manifest is written: `vite.config.js` (next to the overlay's `vite.config.ts` it breaks `npm run build`), the dead `resources/js/app.js` stub, and `resources/views/welcome.blade.php` (no route renders it); the manifest no longer lists a file that is gone.
- Fixed: `php artisan oracle:check` finds the kit where it lives, in both the laravel-blade and the laravel-inertia templates: `oracle-check.php` at the app root (Squire's Laravel layout) first, then `deploy/oracle-check.php` (WELD's home).
- Fixed: `composer install` in a freshly baked Laravel + Blade app no longer stops at the plugin gate; the overlay allows `phpstan/extension-installer` next to the pinned `ext-oci8`.
- Fixed: the laravel-inertia overlay now passes a real run of a generated app. The overlay allows `phpstan/extension-installer` in composer config so `composer install` succeeds, the appended SESAME and HELMET env keys ship commented out (except `SESAME_DRIVER=fake`) so package defaults hold instead of being overridden by empty values, the generator removes the skeleton's `vite.config.js` so it no longer sits next to the overlay's `vite.config.ts` and breaks `npm run build`, and the Oracle command, Oracle error helper and bootstrap are Pint-clean like their Blade twins.
- Fixed: a fresh Laravel + Blade app boots with nothing in its environment. `config/sesame.php` defaults `SESAME_FAKE_USERNAME` to `jane.doe` (SESAME wants a non-empty fake username), and the appended SESAME and HELMET env keys ship commented out so their defaults hold instead of being overridden by empty values.
- Fixed: the plan printed after `sourdough new --laravel` no longer carries the "(not available in this release)" suffix; only the permutations that really refuse keep it.
- Fixed: the generated Laravel + Blade app passes its own `composer ci`. The template's Oracle command, Oracle error helper and bootstrap are Pint-clean, and Pint skips the two Squire-owned root scripts (`health.php`, `oracle-check.php`) through `notPath`.
- Fixed: the agent-ready site twins (`site/index.md`, `site/llms-full.txt`) are resynced with the README, so the `site:agent-ready-check` step of `composer ci` passes.
- Fixed: the laravel-inertia template boots a fresh app out of the box: `config/sesame.php` defaults `SESAME_FAKE_USERNAME` to `jane.doe` so the fake driver has a non-empty username, and Pint skips the two verbatim Squire files (`health.php`, `oracle-check.php`) through `notPath`.

## 0.1.4 (2026-10-29)

### Added

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

### Changed

- The Squire template copy in `templates/squire/` now comes from the `waynestate/squire` Composer package (new dev
  dependency, `^0.2`) instead of a Squire checkout. `composer squire-copy:sync` rewrites the copy from
  `vendor/waynestate/squire`, and `composer squire-copy:check` (now part of `composer ci`) exits 1 when the committed
  copy has drifted. The refresh brings Squire 0.2.0's deploy files: `scripts/release.sh` gains the
  `SQUIRE_PROCTOR=enforce` handling, and the `static` target joins the catalog. Fresh flour, straight from the mill.
- Changed: project names written as words except acronyms.
- Changed: generated apps require SESAME ^0.2 (Entra sign-in).
- Changed: the Squire copy comes from Squire 0.3.0.

### Fixed

- Fixed: `--laravel --inertia` is accepted.
- Fixed: stale capability claims in the site and README.

## 0.1.3 (2026-10-29)

### Added

- `sourdough new` wires PLAYBOOK into generated apps. By default the app's `composer.json` gets a `suggest` for `waynestate/playbook`, `.env.example` lists the seven WSU shared setting names, the README gains a "WSU values (PLAYBOOK)" section, and the command prints a hint line pointing at it. `--playbook` adds the VCS repository and requires `waynestate/playbook` `^0.1`; `--no-playbook` skips all of it; the two flags together are refused with exit 1.

## 0.1.2 (2026-10-29)

### Added

- `sourdough new` validates the app name before writing anything: it must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters (`RawGenerator::assertValidName`). Hostile names (quotes, backslashes, newlines, NUL bytes, placeholders, path traversal, overlong or empty names) are refused with exit 1 and write nothing.

### Changed

- The raw template now requires HELMET `^0.2` and PROCTOR `^0.2`, so `sourdough new` installs the current stack releases instead of the 0.1 line. SESAME stays at `^0.1` until it cuts 0.2.

## 0.1.1 (2026-10-29)

### Changed

- PHPStan and Pint now take their settings from Pinstripe (`waynestate/pinstripe` ^0.1) instead of a hand
  copy: `phpstan.neon` includes the raw preset, and `pint.json` extends the per preset. Same cut, off the rack.

## 0.1.0 (2026-10-29)

### Added

- The raw app now installs SQUIRE's deploy files from a checked copy at `templates/squire/`: `build.sh`,
  `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`,
  `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. SQUIRE owns
  them; Sourdough copies them as-is and records them in the manifest.
- `composer squire-copy:check`: verify that `templates/squire/` still matches a fresh export from the SQUIRE checkout
  (`SQUIRE_PATH`, default `/squire`). Skips with exit 0 when no checkout is mounted.

### Changed

- Refreshed the SQUIRE template copy in `templates/squire/` from e66f07b to 2a893c0: the Vue layer's `.npmrc` and
  `docker-compose.squire.yml` joined the batch. Fresh flour, same recipe.

### Removed

- The raw template's own `deployments/release.sh` and `deployments/release.ps1`; the release steps now come from SQUIRE.
