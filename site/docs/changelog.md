---
title: "Changelog | Sourdough docs"
description: "All notable changes to this project."
url: https://sourdough.apps.wayne.edu/docs/changelog.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Changelog[#](https://sourdough.apps.wayne.edu/docs/changelog.html#changelog)

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic Versioning once 1.0 is out.

## Unreleased[#](https://sourdough.apps.wayne.edu/docs/changelog.html#unreleased)

- Fixed: `--sail` apps answer 200 on `/` and `/up` when the checkout is one Sail's `sail` user cannot write (a Windows drive under Docker Desktop, or files a root container wrote). Every page was a 500 naming `tempnam()`, because Blade could not write `storage/framework/views`. The compose file's entrypoint now opens the app's files to that user only when it cannot write them (`vendor/`, `node_modules/` and `.git` untouched), then starts Sail as usual.
- Fixed: `--docker` apps with a vite service (Blade + Vue, Inertia) serve `/` on the first `composer dev-up`. Nothing installed `node_modules`, so the vite service exited with `vite: not found` and `/` was a 500 (`Vite manifest not found`). The service now runs `npm install` when Vite is missing, then `npm run dev`.
- Changed: the Inertia shape draws with WayneUI instead of the Tailwind starter kit, the same wiring WELD ships. `@waynestate/wayne-ui-css` and `@waynestate/wayne-ui-vue` 2.0.8 are pinned exactly; every page wears WayneUI's app bar (shield, theme toggle), the sidebar that becomes a focus-trapping drawer on phones, breadcrumbs, cards and footer; a pre-paint script under HELMET's nonce stops the dark-mode flash; Ziggy's client comes from the `ziggy-js` npm package. The home page and the dashboard are new, in WayneUI's components and tokens. `eslint.config.js` refuses `components/ui` imports and the kit's helper packages, `.prettierrc` is the stack's, and a `frontend` CI job runs lint, Prettier, vue-tsc and the build.
- Removed: from the Inertia shape, `tailwind.config.js`, `cn()` (`resources/js/lib/utils.ts`), `tailwindcss`, `autoprefixer`, `tailwind-merge`, `clsx`, `lucide-vue-next`, `@vueuse/core`, `prettier-plugin-tailwindcss`, the Bunny Fonts link and the ten kit components they served.
- Fixed: the Laravel shapes' (Blade, Blade + Vue, Inertia) `.gitlab-ci.yml` runs the php-test matrix with `vendor/bin/pest`. The Preflight component defaults to `vendor/bin/phpunit`, which refuses the Pest example tests ("Please run ./vendor/bin/pest instead"), so every job in the matrix crashed with exit 255. Pest runs the PHPUnit class tests too.
- Fixed: `--docker` apps with a vite service (Blade + Vue, Inertia) load their pages. The service ran `npm run dev -- --host 0.0.0.0`, so laravel-vite-plugin wrote `http://0.0.0.0:5173` to `public/hot` and the browser got a blank page; it now runs `npm run dev` with `LARAVEL_SAIL=1`, which listens on every address and writes `http://localhost:5173`.
- Added: the Laravel shapes refuse to boot production on the tests' dummy APP_KEY (`app/Support/DummyAppKey.php`, called from `AppServiceProvider::boot()`), and `tests/Support/SkipEnvFile.php` hands the tests that one dummy key (base64 of 32 zero bytes) when nothing else sets one. WELD ships the same files.
- Fixed: the GitLab smoke jobs (raw, Blade, Blade + Vue, Inertia) failed before they started: the Composer installer's options were handed to `php` itself (`no argument for option -`). They now go after `--`, with Composer pinned to 2.10.3, and the image gets `unzip` so Composer can unpack the locked packages.
- Fixed: a freshly baked Laravel app passes its own `pint --test` again: the Pest example tests are written out over several lines and `tests/Support/SkipEnvFile.php` uses imports and Laravel's operator order, so `composer ci` no longer fails on the template's own style.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Changed: the Laravel shapes (Blade, Blade + Vue, Inertia) generate apps on Pest 4 (`tests/Pest.php`, a Pest example test, `composer test` and `composer ci` run Pest, PHPUnit tests unchanged, tests read `.env`, or `.env.example` when there is no `.env`); the raw shape is unchanged.
- Changed: the docs site wears Sourdough's own look: the front page header and menu (Docs marked current), the gold version ticket, Caprasimo titles, the baker's Kalam hand for labels, the recipe terminal with its gold Copy pill for commands, recipe-card tables, baker's-note callouts and the deep green footer, with a derived chalkboard dark mode. Source: `docs/_header.html`, `docs/theme/sourdough.css` and a `theme` key in `docs/docs.json`; the kit's `docs.css` is unchanged.
- Added: the docs site in `site/docs/` is built by the shared Squire docs-site builder (`bin/docs-site.php`) from the pages in `docs/`, and `composer ci` checks it with `@docs:check`.
- Fixed: raw apps start with `composer serve`. The old `php -S localhost:8000 -t public` line booted the app as production, because the raw front controller defaults `APP_ENV` to production when it is unset. The raw template's new `serve` script disables Composer's process timeout, sets `APP_ENV=local` cross-platform with `@putenv`, then starts `php -S localhost:8000 -t public`, so SESAME signs in with its fake driver; the raw Next steps, the raw README and the site recipe all point at `composer serve` now.
- Added: the smoke gate proves a live baked raw app serves: it fetches the pinned WayneUI release once into `public/wayne-ui.php`, starts the app with `APP_ENV=local` on `127.0.0.1:8099` and expects `/` 200, `/nope` 404 and `/up` 200. Every shape now bakes with `--no-playbook`, because Playbook is required by default and its private package cannot be installed credential-free.
- Added: `--docker` generates the app's own Docker setup for the Laravel shapes (`--laravel`, `--laravel --vue` and `--inertia`): a two-target `Dockerfile` (dev and prod) whose base images are pinned by manifest-list digest, so one pin resolves natively on amd64 and arm64, a `compose.yaml` with a MySQL 8.4 service (and a vite service for the Vue and Inertia shapes), a `.dockerignore`, the PHP ini files the image copies in, the `composer dev-up`, `dev-down` and `dev-shell` scripts, the database settings in `.env.example`, and a "Run it locally with Docker" README section. It works natively on Windows, macOS and Linux (no WSL2 needed). Playbook credentials reach a production build only as a BuildKit secret, never a build arg, and the `FROM` lines are the single place to swap in SQUIRE's Citadel-parity image when it exists.
- Added: `--sail` generates a Sail setup (compose.yaml, laravel/sail) for the Laravel shapes.
- Added: the raw template's `.gitlab-ci.yml` runs an explicit `composer-ci` job (`composer install`, then `composer ci`), mirroring the Laravel overlay, so PROCTOR sees a `composer ci` in the pipeline instead of warning that no job runs it.
- Fixed: the raw front controller answers `404` for any path but `/` (a short not-found page that keeps WayneUI's layout and links home) instead of rendering the home page for every URL. `/up` stays HELMET's and answers first.
- Added: generated apps in Require mode get a `composer playbook` script (`@composer show waynestate/playbook --no-interaction`) with its `scripts-descriptions` entry, so you can check any time whether Playbook (WSU shared values) is installed. Existing scripts keep their order and `playbook` is appended last; an existing `playbook` script is left alone.
- Changed: `sourdough new` now requires `waynestate/playbook` in every app it bakes: the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends the token to git.wayne.edu, the `.env.example` block, and a README section with the access how-to. The command checks read access before writing anything (a Composer credential, then a direct git probe) and refuses with exit `1` until the developer can read the package; an unreachable git.wayne.edu generates with a warning, and non-interactive and CI runs skip the check. `--playbook` stays as a no-op for old scripts, and `--no-playbook` is for CI and automated tests only.
- Added: the raw template ships Pinstripe's coding standard: `pint.json` (the per preset, with the fetched `public/wayne-ui.php` kept out and `vendor` excluded) and `phpstan.neon` (Pinstripe's raw preset, level 8 over `src/` and `public/`), `waynestate/pinstripe` in require-dev with the extension-installer plugin allowed, and `lint`, `fix` and `analyse` scripts, so `composer ci` runs test, lint, analyse, proctor and audit in that order.
- Changed: the raw template's code passes both of Pinstripe's tools: per-preset style fixes in `Oracle`, `OracleException`, `public/index.php` and `OracleTest`, precise closure and binds types in `Oracle`, a false guard for `oci_error()` results, and the connect-failure branch reporting the last driver error instead of passing the failed connection as its source. A freshly baked raw app passes its own checks.
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
- Added: the front page's main nav links the docs site.
- Fixed: the Playbook wiring no longer drops a template's own composer config keys.
- Fixed: the raw template ships `stubs/wayne-ui.php`, a static-analysis stub for the run-time WayneUI functions (`wsu_shell_start`, `wsu_shell_end`) that `phpstan.neon` scans through `scanFiles`, so `composer ci` in a freshly baked raw app no longer fails with function-not-found errors for functions that arrive only with the fetched `public/wayne-ui.php`.

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
