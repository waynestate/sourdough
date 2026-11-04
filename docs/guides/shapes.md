---
title: Pick a shape
description: Pick the permutation your app needs, from raw PHP and raw PHP plus Vue to Laravel plus Blade, Blade plus Vue islands, and Laravel plus Inertia/Vue.
---

# Pick a shape

A starter in a jar is only potential until you give it a shape: pick the permutation below, then run `sourdough new` with the matching flags. If you would rather describe the app in a sentence and let a model plan it, continue to [Describe a plan](./describe-a-plan.md).

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](../readme/gallery.jpg)

## The five permutations

| Flags | Permutation |
|---|---|
| (none) or `--raw` | Raw PHP with WayneUI's standalone path |
| `--raw --vue` | Raw PHP + Vue SPA over JSON |
| `--laravel` | Laravel + Blade |
| `--laravel --vue` | Laravel + Blade + Vue islands |
| `--laravel --inertia` (or `--inertia`) | Laravel + Inertia/Vue |

> [!WARNING]
> `--raw`, `--laravel`, `--laravel --vue` and `--inertia` generate in 0.1. The last permutation, `--raw --vue`, prints the plan and exits `1` with "not available in this release"; a later step adds it.

Run `sourdough new` with the flags for the row you want. `dir` is the directory to create the app in:

```bash tab="raw"
sourdough new my-app --raw
```

```bash tab="raw + vue"
sourdough new my-app --raw --vue
```

```bash tab="laravel"
sourdough new my-app --laravel
```

```bash tab="laravel + vue"
sourdough new my-app --laravel --vue
```

```bash tab="inertia"
sourdough new my-app --inertia
```

## The template directories

Each shape bakes from a directory in `templates/`:

| Template directory | Shape |
|---|---|
| `raw/` | Raw PHP with WayneUI's standalone path |
| `laravel-blade/` | Laravel + Blade |
| `laravel-blade-vue/` | Laravel + Blade + Vue islands |
| `laravel-inertia/` | Laravel + Inertia/Vue |

`squire/` holds Squire's exported deploy files. Squire owns them; Sourdough installs them as-is into every app and records them in the manifest, so you never edit them by hand.

## Oracle in every shape

Oracle is wired but dormant in every generated app. The settings `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` or `ORACLE_HOST`, `ORACLE_PORT`, `ORACLE_SERVICE_NAME` ship commented out in `.env.example`; while they are empty, nothing connects and nothing errors. Once you fill them in, Laravel apps check the wiring with `php artisan oracle:check`, and raw PHP apps run queries through the `App\Oracle` helper, which takes values only as binds and answers mistakes with plain sentences. The command finds the check file where it lives: `oracle-check.php` at the app root, where Squire's Laravel layout ships it, first, then `deploy/oracle-check.php`.

## Related

- [Describe a plan](./describe-a-plan.md)
- [The manifest](./manifest.md)
