---
title: "Changelog | Sourdough docs"
description: "All notable changes to this project."
url: https://sourdough.apps.wayne.edu/docs/changelog.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Changelog | Sourdough docs

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic Versioning once 1.0 is out.

## Unreleased[#](https://sourdough.apps.wayne.edu/docs/changelog.html#unreleased)

- Added: the docs site in `site/docs/` is built by the shared Squire docs-site builder (`bin/docs-site.php`) from the pages in `docs/`, and `composer ci` checks it with `@docs:check`.
- Added: generated apps in Require mode get a `composer playbook` script (`@composer show waynestate/playbook --no-interaction`) with its `scripts-descriptions` entry, so you can check any time whether Playbook (WSU shared values) is installed. Existing scripts keep their order and `playbook` is appended last; an existing `playbook` script is left alone.
- Changed: `sourdough new` now requires `waynestate/playbook` in every app it bakes: the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends the token to git.wayne.edu, the `.env.example` block, and a README section with the access how-to. The command checks read access before writing anything (a Composer credential, then a direct git probe) and refuses with exit `1` until the developer can read the package; an unreachable git.wayne.edu generates with a warning, and non-interactive and CI runs skip the check. `--playbook` stays as a no-op for old scripts, and `--no-playbook` is for CI and automated tests only.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Changed: new apps now get HELMET `^0.3`, SESAME `^0.4` and PROCTOR `^0.4`, and their `.gitlab-ci.yml` pins the Preflight CI components to the 0.1.4 release SHA (`79029cc2300702d0d450c42cc699e12af1b731f8`, with the tag in a trailing comment) because PROCTOR 0.4's `preflight-pinned` check wants a 40-hex SHA.
- Fixed: the GitHub CI workflow was rejected as invalid (every run failed with "workflow file issue") because the smoke job used GitLab's `allow_failure`; it now uses `continue-on-error`.
- Fixed: the sourdough command now works after composer global require (bin/sourdough also looks for the autoloader three folders up, where a dependency install keeps it).
- Fixed: the Laravel Next steps now run `php artisan migrate` after `php artisan key:generate` (all three Laravel shapes), so a freshly baked app has its database before the first request instead of answering 500 until someone migrates by hand.
- Fixed: the Blade and Blade + Vue islands views read the signed-in user as an array (`$user['name']`), because SESAME's `Sesame::user()` returns an array, not an object; the dashboard and the layout no longer crash after sign-in.
- Fixed: the laravel-blade template ships `app/Providers/AppServiceProvider.php` with `Schema::defaultStringLength(191)` like the laravel-inertia one, so string columns stay inside Citadel's MySQL 5.6 index limit (PROCTOR `mysql56-string-default`).
- Fixed: the Oracle check command in the laravel-blade and laravel-inertia templates compares in Pint's non-Yoda order (`$script === null`), so a freshly baked app passes its own `composer ci` on the first run.
- Fixed: the laravel-blade-vue template ships `tests/Feature/ExampleTest.php` with `$this->withoutVite()`, so `composer ci` in a freshly baked islands app no longer fails on the missing Vite manifest before `npm run build` has run.
- Changed: the smoke gate runs `php artisan migrate` like the Next steps, and the Laravel template READMEs show `migrate` for local and `migrate --force` for deploys.

## 0.1.5 (2026-11-03)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-5-2026-11-03)

### Added[#](https://sourdough.apps.wayne.edu/docs/changelog.html#added)

- Added: `sourdough new --laravel` bakes a Laravel + Blade app: a fresh Laravel skeleton from Composer, the laravel-blade overlay, the stack packages, Squire's laravel deploy files and the manifest. Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan serve`. One more loaf on the bench.
- Added: `sourdough new --inertia` bakes a Laravel + Inertia/Vue app: the same Laravel skeleton, the laravel-inertia overlay (Inertia pages rendered by Vue, Ziggy routes, the stack packages), Squire's laravel deploy files and the manifest. Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `npm install`, `npm run build`, `php artisan serve`. Three of the five loaves bake now.
- Added: `sourdough new --laravel --vue` bakes a Laravel + Blade + Vue islands app: the Laravel + Blade bake under a front-end layer (Vite with the Vue plugin, a `resources/js/app.js` registry that mounts a Vue 3 app on every `[data-vue-island]` element, and a Counter island live on the dashboard view). Its Next steps suit the shape: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `npm install`, `npm run build`, `php artisan serve`. Four of the five loaves bake now.
- Added: the raw template ships `scripts` (`test`, `proctor`, `ci`) and `scripts-descriptions`, so a generated raw app can run its own gate locally with `composer ci`.
- Added: generated apps ship ADVISOR by default. `waynestate/advisor` lands in `require-dev` (raw template and the laravel-blade overlay), `.mcp.json` is git-ignored, the README gains an "ADVISOR" section, and the Next steps print `php vendor/bin/advisor install --client=claude --write`. `--no-advisor` skips all of it and records the choice in the manifest.
- Added: the smoke gate (`scripts/smoke-shape.sh`, `scripts/smoke-shape.ps1`, `scripts/smoke.Dockerfile`) bakes a shape with this checkout's own CLI and runs the generated app's own checks; CI runs one job per shape, all `allow_failure: true` until seen green once.

### Changed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changed)

- Changed: `--name` on the Laravel bakes reaches every place WELD applies it by hand: `composer.json`'s `name` becomes `app/<slug>` (an application is not a published package) with the description reading ", a Wayne State app baked from Sourdough.", `.env.example`'s `APP_NAME` carries the app name (quoted when it holds a space), and the `config/app.php` default follows it.
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

### Fixed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#fixed)

- Fixed: the laravel-inertia bake drops the skeleton leftovers before the manifest is written: `vite.config.js` (next to the overlay's `vite.config.ts` it breaks `npm run build`), the dead `resources/js/app.js` stub, and `resources/views/welcome.blade.php` (no route renders it); the manifest no longer lists a file that is gone.
- Fixed: `php artisan oracle:check` finds the kit where it lives, in both the laravel-blade and the laravel-inertia templates: `oracle-check.php` at the app root (Squire's Laravel layout) first, then `deploy/oracle-check.php` (WELD's home).
- Fixed: `composer install` in a freshly baked Laravel + Blade app no longer stops at the plugin gate; the overlay allows `phpstan/extension-installer` next to the pinned `ext-oci8`.
- Fixed: the laravel-inertia overlay now passes a real run of a generated app. The overlay allows `phpstan/extension-installer` in composer config so `composer install` succeeds, the appended SESAME and HELMET env keys ship commented out (except `SESAME_DRIVER=fake`) so package defaults hold instead of being overridden by empty values, the generator removes the skeleton's `vite.config.js` so it no longer sits next to the overlay's `vite.config.ts` and breaks `npm run build`, and the Oracle command, Oracle error helper and bootstrap are Pint-clean like their Blade twins.
- Fixed: a fresh Laravel + Blade app boots with nothing in its environment. `config/sesame.php` defaults `SESAME_FAKE_USERNAME` to `jane.doe` (SESAME wants a non-empty fake username), and the appended SESAME and HELMET env keys ship commented out so their defaults hold instead of being overridden by empty values.
- Fixed: the plan printed after `sourdough new --laravel` no longer carries the "(not available in this release)" suffix; only the permutations that really refuse keep it.
- Fixed: the generated Laravel + Blade app passes its own `composer ci`. The template's Oracle command, Oracle error helper and bootstrap are Pint-clean, and Pint skips the two Squire-owned root scripts (`health.php`, `oracle-check.php`) through `notPath`.
- Fixed: the agent-ready site twins (`site/index.md`, `site/llms-full.txt`) are resynced with the README, so the `site:agent-ready-check` step of `composer ci` passes.
- Fixed: the laravel-inertia template boots a fresh app out of the box: `config/sesame.php` defaults `SESAME_FAKE_USERNAME` to `jane.doe` so the fake driver has a non-empty username, and Pint skips the two verbatim Squire files (`health.php`, `oracle-check.php`) through `notPath`.

## 0.1.4 (2026-10-29)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-4-2026-10-29)

### Added[#](https://sourdough.apps.wayne.edu/docs/changelog.html#added-1)

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

### Changed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changed-1)

- The Squire template copy in `templates/squire/` now comes from the `waynestate/squire` Composer package (new dev dependency, `^0.2`) instead of a Squire checkout. `composer squire-copy:sync` rewrites the copy from `vendor/waynestate/squire`, and `composer squire-copy:check` (now part of `composer ci`) exits 1 when the committed copy has drifted. The refresh brings Squire 0.2.0's deploy files: `scripts/release.sh` gains the `SQUIRE_PROCTOR=enforce` handling, and the `static` target joins the catalog. Fresh flour, straight from the mill.
- Changed: project names written as words except acronyms.
- Changed: generated apps require SESAME ^0.2 (Entra sign-in).
- Changed: the Squire copy comes from Squire 0.3.0.

### Fixed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#fixed-1)

- Fixed: `--laravel --inertia` is accepted.
- Fixed: stale capability claims in the site and README.

## 0.1.3 (2026-10-29)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-3-2026-10-29)

### Added[#](https://sourdough.apps.wayne.edu/docs/changelog.html#added-2)

- `sourdough new` wires PLAYBOOK into generated apps. By default the app's `composer.json` gets a `suggest` for `waynestate/playbook`, `.env.example` lists the seven WSU shared setting names, the README gains a "WSU values (PLAYBOOK)" section, and the command prints a hint line pointing at it. `--playbook` adds the VCS repository and requires `waynestate/playbook` `^0.1`; `--no-playbook` skips all of it; the two flags together are refused with exit 1.

## 0.1.2 (2026-10-29)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-2-2026-10-29)

### Added[#](https://sourdough.apps.wayne.edu/docs/changelog.html#added-3)

- `sourdough new` validates the app name before writing anything: it must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters (`RawGenerator::assertValidName`). Hostile names (quotes, backslashes, newlines, NUL bytes, placeholders, path traversal, overlong or empty names) are refused with exit 1 and write nothing.

### Changed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changed-2)

- The raw template now requires HELMET `^0.2` and PROCTOR `^0.2`, so `sourdough new` installs the current stack releases instead of the 0.1 line. SESAME stays at `^0.1` until it cuts 0.2.

## 0.1.1 (2026-10-29)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-1-2026-10-29)

### Changed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changed-3)

- PHPStan and Pint now take their settings from Pinstripe (`waynestate/pinstripe` ^0.1) instead of a hand copy: `phpstan.neon` includes the raw preset, and `pint.json` extends the per preset. Same cut, off the rack.

## 0.1.0 (2026-10-29)[#](https://sourdough.apps.wayne.edu/docs/changelog.html#0-1-0-2026-10-29)

### Added[#](https://sourdough.apps.wayne.edu/docs/changelog.html#added-4)

- The raw app now installs SQUIRE's deploy files from a checked copy at `templates/squire/`: `build.sh`, `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`, `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. SQUIRE owns them; Sourdough copies them as-is and records them in the manifest.
- `composer squire-copy:check`: verify that `templates/squire/` still matches a fresh export from the SQUIRE checkout (`SQUIRE_PATH`, default `/squire`). Skips with exit 0 when no checkout is mounted.

### Changed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changed-4)

- Refreshed the SQUIRE template copy in `templates/squire/` from e66f07b to 2a893c0: the Vue layer's `.npmrc` and `docker-compose.squire.yml` joined the batch. Fresh flour, same recipe.

### Removed[#](https://sourdough.apps.wayne.edu/docs/changelog.html#removed)

- The raw template's own `deployments/release.sh` and `deployments/release.ps1`; the release steps now come from SQUIRE.
