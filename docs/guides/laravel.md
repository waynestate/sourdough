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
- `--inertia` bakes a Laravel + Inertia/Vue app: the same Laravel skeleton, the laravel-inertia overlay (Inertia pages rendered by Vue, Ziggy routes, the stack packages), Squire's laravel deploy files and the manifest.

Four of the five permutations generate in 0.1. The last one, `--raw --vue`, prints the plan and exits `1` with "not available in this release"; a later step adds it.

## Build on the official installer

For Laravel paths, wrap the supported entry points instead of copying Laravel's skeleton by hand. `composer create-project laravel/laravel` picks the newest major, which is right. The installer also accepts a community starter kit published on Packagist:

```bash
laravel new my-app --using=vendor/kit
```

A kit declares its environment variables in `.env.example` and its setup steps in the `post-create-project-cmd` list of its `composer.json`. There is no plain Blade kit, so the `--laravel` (Blade) and Blade-plus-Vue-islands permutations are a bare Laravel install plus Sourdough's own layer, not a vendor kit.

## Local runtime: Sail or Docker

The Laravel paths take a flag for the local runtime, and the two are mutually exclusive: together they are refused with `Choose one of --sail or --docker, not both.`

- `--sail` runs the app on Laravel Sail. The bake adds `laravel/sail` to require-dev, writes a `compose.yaml`, sets the database settings in `.env.example` and appends a "Run it locally with Sail" README section. The database is MySQL 8.4 LTS.
- `--docker` writes the app's own Docker setup after the shape is baked: a two-target `Dockerfile` (dev and prod) whose base images are pinned by manifest-list digest, a `compose.yaml` with a MySQL 8.4 service (and a vite service for the Vue and Inertia shapes), a `.dockerignore`, the PHP ini files the image copies in, the `composer dev-up`, `dev-down` and `dev-shell` scripts, the database settings in `.env.example`, and a "Run it locally with Docker" README section. It works natively on Windows, macOS and Linux (no WSL2 needed) and is checked on amd64 and arm64.

Sail is Laravel's Docker development environment. On Windows it needs WSL2 and its `sail` script is shell, so it is an option where it fits, never the only Windows route; `--docker` is the route that works natively. See [Every developer, every operating system](./every-os.md) for the full picture.

With `--docker`, the production build takes Playbook's credentials as a BuildKit secret, never a build arg:

```bash
docker build --target prod --secret id=composer_auth,src=auth.json -t myapp .
```

The `FROM` lines in the generated `Dockerfile` are the single place to swap in SQUIRE's Citadel-parity image when it exists.

## Sign-in stays SESAME

Laravel's kits ship registration, password reset, email verification and two-factor login through Fortify. A WSU app signs in through SESAME, so turn off the features you do not use, and remove their routes from the front end too. In the Vue, React and Svelte kits a leftover reference to a disabled route breaks the build, because routes are generated at build time.

> [!WARNING]
> A live `/register` route in an app that is supposed to use central sign-in is a security hole and a support burden. The kits also set a login rate limit (5 a minute per email and IP in the docs' example), and the WorkOS variants would add a second hosted identity provider beside SESAME, so leave them out.

## Keep Boost current

For the Inertia reference path, install Laravel Boost with `--dev`, and refresh its generated guideline and skill files with `boost:update`; the Boost docs suggest running it from Composer's `post-update-cmd`. Stale agent guidance teaches an agent last year's API. Boost runs on Laravel 10 to 13 with PHP 8.1 or higher. Its docs say the generated `.mcp.json`, guideline files (`CLAUDE.md`, `AGENTS.md`, `junie/`) and `boost.json` may go in `.gitignore`, because `boost:install` and `boost:update` regenerate them; the `.ai/rules` directory (project rules recorded by agents) should be committed.

> [!WARNING]
> Boost's MCP server can run database queries and code through Tinker, so the development `.env` must point at a local database and never at the shared server.

## Support windows

Laravel gives each major 18 months of bug fixes and two years of security fixes. From its release notes: 11 reached the end of security fixes on 2026-03-12; 12 (PHP 8.2 to 8.5) gets security fixes until 2027-02-24; 13 (PHP 8.3 to 8.5) until 2028-03-17. Name the major in the generator's tests, so a new release cannot change output unseen, and print the end-of-security date in the generated README. Laravel 13 needs PHP 8.3, which is also the stack's floor: 8.2 security support ends 2026-12-31.

## Trust the HA-Proxy, not every sender

Citadel ends TLS at HA-Proxy, so the app sees plain HTTP. Behind a balancer that ends TLS, Laravel's `url` helper can produce `http` links until `trustProxies` is set in `bootstrap/app.php`. The generated app should list the proxy addresses (from env) and also set `trustHosts`. Raw PHP gets the same behavior from HELMET.

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
