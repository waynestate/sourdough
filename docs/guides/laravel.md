---
title: Building on Laravel
description: What a Laravel bake adds over the raw base, including a fresh skeleton from Composer, Sail or Docker for the local runtime, artisan Next steps, and the Oracle check command.
---

# Building on Laravel

This page covers what a Laravel bake adds over the raw base and the practices that keep Laravel shapes sound. Start by picking your shape on [Pick a shape](./shapes.md), then come back here for what the bake gives you and how to keep it healthy.

A Laravel loaf bakes from a fresh Laravel skeleton, not from the raw base: `sourdough new --laravel` starts from Composer's official installer and layers the stack on top, so the app inherits Laravel's upgrades to the skeleton.

## What a Laravel bake adds

- `--laravel` bakes a Laravel + Blade app: a fresh Laravel skeleton from Composer, the laravel-blade overlay, the stack packages, Squire's laravel deploy files and the manifest.
- `--laravel --vue` bakes a Laravel + Blade + Vue islands app: the Laravel + Blade bake under a front-end layer (Vite with the Vue plugin, a `resources/js/app.js` registry that mounts a Vue 3 app on every `[data-vue-island]` element, and a Counter island live on the dashboard view).
- `--inertia` bakes a Laravel + Inertia/Vue app: the same Laravel skeleton, the laravel-inertia overlay (Inertia 3 pages rendered by Vue and drawn by WayneUI: its app bar, sidebar, drawer, footer and components from `@waynestate/wayne-ui-css` and `@waynestate/wayne-ui-vue` 2.0.8, pinned exactly; no Tailwind; ESLint bans the old kit's `components/ui` imports; Ziggy routes; the stack packages; a `frontend` CI job that lints, type-checks and builds), Squire's laravel deploy files and the manifest.

All three Laravel shapes also ship Laravel Boost as a dev-only tool, with the stack's guidelines and starter rules in `.ai/` (see [Laravel Boost, dev only](#laravel-boost-dev-only)).

All five permutations generate in 0.1; the raw ones (`--raw`, `--raw --vue`) start from the raw base instead of a Laravel skeleton.

## Build on the official installer

For Laravel paths, wrap the supported entry points instead of copying Laravel's skeleton by hand. `composer create-project laravel/laravel` picks the newest major, which is right. The installer also accepts a community starter kit published on Packagist:

```bash
laravel new my-app --using=vendor/kit
```

A kit declares its environment variables in `.env.example` and its setup steps in the `post-create-project-cmd` list of its `composer.json`. There is no plain Blade kit, so the `--laravel` (Blade) and Blade-plus-Vue-islands permutations are a bare Laravel install plus Sourdough's own layer, not a vendor kit.

## Local runtime: Sail or Docker

The Laravel paths take a flag for the local runtime, and the two are mutually exclusive: together they are refused with `Choose one of --sail or --docker, not both.`

- `--sail` runs the app on Laravel Sail. The bake adds `laravel/sail` to require-dev, writes a `compose.yaml`, sets the database settings in `.env.example` and appends a "Run it locally with Sail" README section. The database is MySQL 8.4 LTS. Sail serves as its own `sail` user, so when the checkout is one that user cannot write (a Windows drive, or files a root container wrote), the compose file opens the app's files to it before Sail starts (`vendor/`, `node_modules/` and `.git` are left alone). Without that, every page was a 500 naming `tempnam()`. On a normal Linux or macOS checkout nothing changes.
- `--docker` writes the app's own Docker setup after the shape is baked: a two-target `Dockerfile` (dev and prod) whose base images are pinned by manifest-list digest, a `compose.yaml` with a MySQL 8.4 service (and a vite service for the Vue and Inertia shapes, which runs `npm install` on its first start and then `npm run dev` with `LARAVEL_SAIL=1` so `public/hot` says `http://localhost:5173`, an address your browser can load), a `.dockerignore`, the PHP ini files the image copies in, the `composer dev-up`, `dev-down` and `dev-shell` scripts, the database settings in `.env.example`, and a "Run it locally with Docker" README section. It works natively on Windows, macOS and Linux (no WSL2 needed) and is checked on amd64 and arm64.

Sail is Laravel's Docker development environment. On Windows it needs WSL2 and its `sail` script is shell, so it is an option where it fits, never the only Windows route; `--docker` is the route that works natively. See [Every developer, every operating system](./every-os.md) for the full picture.

With `--docker`, the production build takes Playbook's credentials as a BuildKit secret, never a build arg:

```bash
docker build --target prod --secret id=composer_auth,src=auth.json -t myapp .
```

The `FROM` lines in the generated `Dockerfile` are the single place to swap in Squire's Citadel-parity image when it exists.

## Sign-in stays SESAME

Laravel's kits ship registration, password reset, email verification and two-factor login through Fortify. A WSU app signs in through SESAME, so turn off the features you do not use, and remove their routes from the front end too. In the Vue, React and Svelte kits a leftover reference to a disabled route breaks the build, because routes are generated at build time.

> [!WARNING]
> A live `/register` route in an app that is supposed to use central sign-in is a security hole and a support burden. The kits also set a login rate limit (5 a minute per email and IP in the docs' example), and the WorkOS variants would add a second hosted identity provider beside SESAME, so leave them out.

## Laravel Boost, dev only

Every Laravel bake (Blade, Blade with Vue, Inertia) ships [Laravel Boost](https://laravel.com/docs/boost) in `require-dev`: an MCP server, guidelines and skills that teach a coding agent the app's Laravel. Boost is used as published, through its own commands and file layout. Sourdough adds the stack's guidance and the guard rails around it. [ADVISOR](https://github.com/waynestate/advisor) complements Boost, never replaces it: Boost knows Laravel, ADVISOR knows the Wayne State stack.

After `composer install`, each developer wires Boost into their own agent once per checkout:

```bash
php artisan boost:install
```

What the bake gives you:

| Piece | Where | In git? |
| --- | --- | --- |
| Boost itself | `laravel/boost ^2.10` in `require-dev` | yes (`composer.json`) |
| The stack's conventions (SESAME, WayneUI, HELMET, MySQL 5.6, Oracle, Squire) | `.ai/guidelines/wayne-state.md`, folded into every agent's file by Boost | yes, managed: `sourdough feed` refreshes it |
| Starter project rules | `.ai/rules/migrations.md`, `.ai/rules/routes.md` and Boost's `.ai/rules/index.md` | yes, seeded: the team's from the first commit |
| What `boost:install` writes | `CLAUDE.md`, `AGENTS.md`, `.mcp.json`, `.claude/skills/`, `.junie/`, `boost.json` | no: a `.gitignore` block keeps them out |
| A refresh after `composer update` | `composer boost-update`, appended to Laravel's `post-update-cmd` | yes |
| A check that Boost stays a dev tool | `tests/Feature/BoostDevOnlyTest.php` | yes |

Why each choice:

- **`boost.json` is ignored.** It records the agents each developer picked, so it is personal. Boost's docs allow ignoring it.
- **The refresh is guarded.** Boost's docs suggest `@php artisan boost:update --ansi` in `post-update-cmd`, but `boost:update` exits 1 until `boost:install` has written `boost.json`, and under `composer update --no-dev` the command does not exist. `composer boost-update` runs it only when `boost.json` and `vendor/laravel/boost` are both there, and passes its exit code through, so a real failure still fails `composer update`.
- **The agent files are ignored whole.** Git cannot ignore the generated block inside `CLAUDE.md`, and Boost rewrites that block on every `boost:update`. Team guidance goes in `.ai/guidelines/`, which Boost folds into every agent's file, so nothing is lost.
- **The skeleton's `CLAUDE.md` and `AGENTS.md` are dropped.** Laravel 13 ships them holding only a bootstrap block that tells an agent to `composer require laravel/boost --dev` (already done) and to install PHP with a remote PowerShell one-liner the stack does not copy. `boost:install` writes them again for the agents you pick. A skeleton file with anything else in it is kept.
- **`.agents/` stays tracked.** Booster keeps its playbooks in `.agents/skills/`, and Boost writes its Codex, OpenCode, Amp and Antigravity skills there too, so commit what you want to keep from that folder.
- **Record rules through your agent.** Ask it to "remember" something and Boost's `record-rule` tool files it in `.ai/rules/` and rewrites the index; a rule file added by hand is not found until `php artisan boost:index-rules` runs.

Boost never reaches production. Squire's `scripts/release.sh` and the `--docker` prod target both install with `composer install --no-dev`, which leaves `require-dev` behind, and `BoostDevOnlyTest` fails when `laravel/boost` moves into `require` or the lock file puts it outside `packages-dev`. Boost 2.10 needs PHP 8.2 or newer and Laravel 11.45, 12.41 or 13.

> [!WARNING]
> Boost's MCP server can run database queries and code through Tinker, so the development `.env` must point at a local database and never at the shared server.

## Support windows

Laravel gives each major 18 months of bug fixes and two years of security fixes. From its release notes: 11 reached the end of security fixes on 2026-03-12; 12 (PHP 8.2 to 8.5) gets security fixes until 2027-02-24; 13 (PHP 8.3 to 8.5) until 2028-03-17. Name the major in the generator's tests, so a new release cannot change output unseen, and print the end-of-security date in the generated README. Laravel 13 needs PHP 8.3, which is also the stack's floor: 8.2 security support ends 2026-12-31.

## Trust the HA-Proxy, not every sender

Citadel ends TLS at HA-Proxy, so the app sees plain HTTP. Behind a balancer that ends TLS, Laravel's `url` helper can produce `http` links until `trustProxies` is set in `bootstrap/app.php`. The generated app should list the proxy addresses (from env) and also set `trustHosts`. Raw PHP gets the same behavior from HELMET.

## A stopped scheduler shows up

Citadel has no queue worker, so a generated app runs its queue from the scheduler (`QUEUE_WORKER_MODE=scheduler` starts a short `queue:work` each minute). A scheduler that stops does not error: it simply stops running, and jobs pile up in silence. Every Laravel shape therefore stamps a heartbeat into the cache each minute (`routes/console.php`, under the name `scheduler-heartbeat`) and registers HELMET's readiness route at `/ready` with a `scheduler` check. While the stamp is missing or older than `WORKER_HEARTBEAT_MAX_AGE` seconds (default 180, `config/worker.php`), `/ready` answers 503, so a balancer or health probe skips the instance instead of feeding it work nobody will run. `/up` stays the liveness answer: it never looks at the cache, so a stalled scheduler does not take the app out of rotation for requests that do not need the queue.

## Work on the shared MySQL 5.6

Laravel's database docs list MySQL 5.7+ and MariaDB 10.3+ as supported; the shared server is MySQL 5.6, which left extended support on 2021-02-28. The practical limit is the index key prefix: 767 bytes for REDUNDANT and COMPACT tables, which is 191 characters in utf8mb4. Laravel's default string column is 255 characters, so a unique index on it can fail with error 1071. The Laravel templates set the 191 default with `Schema::defaultStringLength(191)` in `app/Providers/AppServiceProvider.php` and avoid JSON columns, so string columns stay inside the limit (PROCTOR `mysql56-string-default`). Sail's MySQL is current, so a developer's migrations can pass locally and fail on deploy.

## Next steps

Each Laravel bake prints Next steps suited to the shape. For `--laravel`:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

The islands and Inertia shapes add the front-end build between `migrate` and `serve`:

```bash
npm install
npm run build
```

The bake runs `php artisan migrate` after `php artisan key:generate`, so a freshly baked app has its database before the first request instead of answering 500 until someone migrates by hand.

## The Oracle check

Oracle is wired but dormant in every generated app. The settings `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` or `ORACLE_HOST`, `ORACLE_PORT`, `ORACLE_SERVICE_NAME` ship commented out in `.env.example`; while they are empty, nothing connects and nothing errors. Once you fill them in, Laravel apps check the wiring with:

```bash
php artisan oracle:check
```

The command finds the check file where it lives: `oracle-check.php` at the app root, where Squire's Laravel layout ships it, first, then `deploy/oracle-check.php`.

## Related

- [Pick a shape](./shapes.md)
- [The manifest](./manifest.md)
- [Keeping apps current](./keeping-current.md)
- [Every developer, every operating system](./every-os.md)
