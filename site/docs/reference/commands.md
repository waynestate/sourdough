---
title: "Commands | Sourdough docs"
description: "Every sourdough command and option, from sourdough new's flags to sourdough manifest --check, sourdough adopt and sourdough feed, with their exit codes."
url: https://sourdough.apps.wayne.edu/docs/reference/commands.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Commands[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#commands)

Reference for the `sourdough` CLI: every command, argument, option and exit code, exactly as the sources define them. For the JSON plan behind `--describe` and `--plan`, see [Plan format](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html).

## sourdough new[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-new)

Make a new Wayne State app with the stack already wired in.

```
sourdough new [dir] [options]
```

`dir` is the directory to create the app in. It is required unless you use `--describe`.

### Options[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#options)

| Option | Meaning |
| --- | --- |
| `--raw` | use the raw PHP base |
| `--laravel` | use the Laravel base |
| `--vue` | add a Vue front end |
| `--inertia` | use Inertia/Vue (implies `--laravel`) |
| `--sail` | run the Laravel app on Sail for local work (compose.yaml; needs WSL2 on Windows) |
| `--docker` | generate a Dockerfile and compose.yaml for local work (native on every OS; amd64 and arm64) |
| `--dry-run` | print the plan and write nothing |
| `--name` | app name (default: the basename of `<dir>`) |
| `--no-input` | never prompt (Sourdough has no prompts) |
| `--json` | with `--dry-run`, print the plan as JSON |
| `--describe "<sentence>"` | describe what the app does; a model plans it (needs the `SOURDOUGH_LLM_*` env vars) |
| `--plan-only` | with `--describe`, print the plan as JSON and write nothing |
| `--plan <file>` | a ready-made plan file (JSON, or YAML ending in `.yaml` or `.yml`, matching `resources/plan.schema.json`), such as the `.sourdough/plan.yaml` every bake saves |
| `--playbook` | kept for compatibility: Playbook is now the default |
| `--no-playbook` | skip the Playbook wiring (for CI and automated tests only; WSU apps need Playbook) |
| `--no-advisor` | skip the ADVISOR wiring (no `require-dev` entry, no `.mcp.json` git-ignore line of its own, no README section; the Laravel shapes still ignore `/.mcp.json` for Laravel Boost) |

Note

`--sail` and `--docker` are both real for the Laravel shapes (`--laravel`, `--laravel --vue` and `--inertia`) and mutually exclusive: together they are refused with `Choose one of --sail or --docker, not both.` (exit `1`). With `--sail` the app runs on Laravel Sail: `laravel/sail` in require-dev, a `compose.yaml`, the database settings in `.env.example` and a README section; the database is MySQL 8.4 LTS. On Windows, Sail needs WSL2, and `--docker` is the route that works natively. With `--docker` Sourdough writes the app's own Docker setup after the shape is baked: a two-target `Dockerfile` (dev and prod) whose base images are pinned by manifest-list digest, a `compose.yaml` with a MySQL 8.4 service (and a vite service for the Vue and Inertia shapes), a `.dockerignore`, the PHP ini files the image copies in, the `composer dev-up`, `dev-down` and `dev-shell` scripts, the database settings in `.env.example`, and a "Run it locally with Docker" README section. It works natively on Windows, macOS and Linux (no WSL2 needed) and is checked on amd64 and arm64. Playbook credentials reach a production build only as a BuildKit secret, never a build arg. The raw paths keep their own refusal at the plan step: "`--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP."

### Exit codes[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#exit-codes)

Exit codes: `0` done, `1` refused (invalid flags, non-empty target, not available, model gave up), `2` tool error.

| Refused case | Exact message | Exit |
| --- | --- | --- |
| `--describe` and `--plan` together | `--describe and --plan are mutually exclusive.` | `1` |
| `--sail` and `--docker` together | `Choose one of --sail or --docker, not both.` | `1` |
| `--sail` or `--docker` with a raw permutation | `--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.` | `1` |
| `--playbook` and `--no-playbook` together | `--playbook and --no-playbook are mutually exclusive.` | `1` |
| `--plan-only` without `--describe` | `--plan-only is only available with --describe.` | `1` |
| `dir` missing without `--describe` | `The dir argument is required unless you use --describe.` | `1` |
| no read access to Playbook | `You need read access to Playbook (a private package on git.wayne.edu) to make a Wayne State app.` plus the how-to | `1` |
| `--json` without `--dry-run` | `--json is only available with --dry-run.` | `2` |
| the model could not be reached (`--describe`) | `The model could not be reached:` plus the underlying error | `2` |

Other refusals, all exit `1`: invalid flags, a non-empty target, a model that gives up (the message points to `--plan=<file>`), and an app name that fails validation.

### Playbook access check[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#playbook-access-check)

By default the generated app requires `waynestate/playbook` (WSU's private shared-values package on git.wayne.edu): the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends your token to git.wayne.edu, the seven setting names in `.env.example`, a `composer playbook` script that shows whether Playbook is installed, and a "WSU values (PLAYBOOK)" README section that explains how to get read access.

Before writing anything, `sourdough new` checks that this machine can read the package (a Composer credential, then a direct git probe):

| Situation | Behavior |
| --- | --- |
| no read access | refuses with exit `1` and prints the how-to |
| git.wayne.edu unreachable | still generates the app, but warns that `composer install` will need the connection |
| non-interactive run or CI environment | skips the check |
| `--dry-run` | runs the check but never refuses; the JSON plan carries a `playbook` key with the mode, the access status and the probe that answered |

After generation the Next steps point at `composer playbook`. `--playbook` is kept for compatibility and changes nothing; `--no-playbook` skips all of it. The two flags together are refused with exit `1`.

### ADVISOR wiring[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#advisor-wiring)

Generated apps ship with ADVISOR wired in by default: `waynestate/advisor` lands in `require-dev`, `.mcp.json` is git-ignored (a client config starts a program for whoever opens the project), and the README gains an "ADVISOR" section. The Next steps print the install line once dependencies are in:

```
php vendor/bin/advisor install --client=claude --write
```

`--no-advisor` skips all of it.

The three Laravel shapes also ship Laravel Boost as a dev-only tool, whatever the ADVISOR answer: `laravel/boost` in `require-dev`, a guarded `composer boost-update` after `composer update`, a `.gitignore` block for what `php artisan boost:install` writes (`/.mcp.json`, `/boost.json`, `/CLAUDE.md`, `/AGENTS.md`, `/.claude/skills/`, `/.junie/`), the stack's guidelines and two starter rules in `.ai/`, and `tests/Feature/BoostDevOnlyTest.php`. ADVISOR complements Boost: Boost knows Laravel, ADVISOR knows the stack. See [Laravel Boost, dev only](https://sourdough.apps.wayne.edu/docs/guides/laravel.html#laravel-boost-dev-only).

### App name validation[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#app-name-validation)

The app name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

### PHP floor and support lines[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#php-floor-and-support-lines)

Every plan, dry run or real, prints two lines under `Target:`:

```
PHP:         PHP ^8.3, tested on 8.3, 8.4 and 8.5 (from waynestate/creed, refreshed 2026-10-29)
Support:     supported, owned by Sourdough maintainers; Laravel 13 gets security fixes until 2028-03-17
```

The floor comes from CREED's policy at bake time; when the policy cannot be read, the first line ends `(pinned fallback: <reason>)` and the bake uses the pinned values. A shape that is deprecated also prints `Deprecated: The <shape> shape is deprecated since Sourdough <x> and is removed in <y>; move to <shape>. See <page>` to stderr; it never changes the exit code. With `--dry-run --json` the plan gains a `php` object and a `support` object with the same facts. See [Support and sunset](https://sourdough.apps.wayne.edu/docs/guides/support.html).

### Environment[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#environment)

- `SOURDOUGH_POLICY`: a CREED-format `policy.json` to take the PHP floor from (default: the copy installed with `waynestate/creed`). A file that cannot be read means the pinned fallback, named in the `PHP:` line.
- `SOURDOUGH_COMPOSER`: the Composer binary the Laravel shapes bake their skeleton with (default: `composer` on PATH).
- `SOURDOUGH_LLM_BASE_URL`, `SOURDOUGH_LLM_API_KEY`, `SOURDOUGH_LLM_MODEL`: the model `--describe` asks.

### Next steps printed after generation[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#next-steps-printed-after-generation)

| Shape | Steps |
| --- | --- |
| Raw PHP | `cd <dir>`, `composer install`, `composer serve` |
| Laravel + Blade | `cd <dir>`, `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan serve` |
| Laravel + Blade + Vue islands | the Laravel + Blade steps plus `npm install` and `npm run build` |
| Laravel + Inertia/Vue | the Laravel + Blade steps plus `npm install` and `npm run build` |
| any Laravel shape with `--sail` | `cd <dir>`, `composer install`, `cp .env.example .env`, `./vendor/bin/sail up -d`, `./vendor/bin/sail artisan key:generate`, `./vendor/bin/sail artisan migrate`, `(Windows: run sail from WSL2, or use --docker)`; the Vue and Inertia shapes add `./vendor/bin/sail npm install` and `./vendor/bin/sail npm run dev` |
| any Laravel shape with `--docker` | `cd <dir>`, `composer install`, `cp .env.example .env`, `composer dev-up`, `docker compose exec app php artisan key:generate`, `docker compose exec app php artisan migrate`; the Vue and Inertia shapes end with `(Vite runs as its own service on port 5173)` |

With ADVISOR wired in, `php vendor/bin/advisor install --client=claude --write` is printed after `composer install`. With Playbook required, the output ends with: `WSU values: Playbook installs with composer install. Check it any time with: composer playbook`.

## sourdough manifest[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-manifest)

Check a generated app against its Sourdough manifest.

```
sourdough manifest [dir] [--check]
```

| Argument or option | Meaning |
| --- | --- |
| `dir` | the app directory to check (default: the current directory) |
| `--check` | list managed files that changed or are missing; exit 1 when anything changed |

Without `--check` the command answers "Use `sourdough manifest --check` to check the app against its manifest." and exits `2`.

Exit codes: `0` clean, `1` changed or missing, `2` tool error.

| Output | Meaning |
| --- | --- |
| `Clean: every managed file matches the manifest.` | every managed file matches the manifest |
| `changed <file>` | a managed file changed |
| `missing <file>` | a managed file is missing |

Seeded and yours files are never reported: the app owns them. Line endings are forgiven: a CRLF checkout of a file Sourdough wrote with LF is not `changed`.

## sourdough adopt[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-adopt)

Write the Sourdough manifest for an app Sourdough did not make, so `sourdough feed` can keep it current. The walk-through is [Adopt an existing app](https://sourdough.apps.wayne.edu/docs/guides/adopt.html).

```
sourdough adopt [<dir>] --shape <shape> [--name <name>] [--sail | --docker] [--no-playbook] [--no-advisor] [--dry-run] [--json] [-v]
```

| Argument or option | Meaning |
| --- | --- |
| `dir` | the app's root folder (default: the current directory), resolved with `realpath()`, so a symlinked path works |
| `--shape <shape>` | required: what the app already is, one of `raw`, `raw-vue`, `laravel-blade`, `laravel-blade-vue`, `laravel-inertia` (the values the manifest records as `template`) |
| `--name <name>` | the name the template writes into files (default: the basename of the resolved folder), validated as for `sourdough new` |
| `--sail`, `--docker` | the oven, Laravel shapes only, as for `sourdough new`; leave both off when the app has its own Docker setup |
| `--no-playbook`, `--no-advisor` | as for `sourdough new`; recorded in the answers, so every later feed bakes the same way |
| `--dry-run` | print the report and write nothing; git and the folder give notes instead of refusals |
| `--json` | print the report as JSON, with or without `--dry-run` |
| `-v` | also list the `match` lines, and every file of a folded folder |

Adopt bakes the shape into a scratch folder through the same `Baker` as `new` and `feed`, looks at each template path in the app (and only those), and writes `.sourdough/manifest.json`. It writes no other file: no `.sourdough/base/`, no `.sourdough/plan.yaml`. The answers gain `adopted: true`. There is no `--php` (the PHP floor comes from CREED's policy at bake time, so feed could never reproduce one) and no `--force` (every refusal has a plain fix).

### Report lines[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#report-lines)

One line per template path that needs a look, ` <status> <path> <detail>`: `yours`, then `seeded`, then `missing`, each sorted by path; `match` lines only with `-v`. Without `-v`, a top-level folder whose every template file is missing folds into one line, `missing src/ (2 files) the next sourdough feed adds them`.

| Status | Detail | Recorded as |
| --- | --- | --- |
| `match` | `same as the template: Sourdough keeps it current` | `managed`, the template's hash |
| `yours` | `differs from the template: yours, never overwritten` | `yours`, the template's hash |
| `yours` | `differs; the template writes the app name here as "<name>", and your file does not have it: ...` | `yours` |
| `yours` | `a symlink: Sourdough never writes through links` (a link, a file under a linked folder, or a file whose real path leaves the app) | `yours` |
| `yours` | `named <actual> here; the template calls it <path>` | `yours` |
| `seeded` | `yours, as in every Sourdough app` | `seeded`, the template's hash |
| `missing` | `the next sourdough feed adds it` | not recorded |

After the lines: any `note:` lines (a deprecated shape, a vendored Squire kit older or newer than the one this Sourdough bakes), then the summary, `Adopted: <n> match, <n> yours, <n> seeded, <n> missing. Wrote .sourdough/manifest.json.` (`Plan: ...` on a dry run). With two or more name hints, the name note prints right after the summary: `note: <n> files differ where the template writes the app name; if the app goes by another name, delete .sourdough/ and adopt again with --name.` Then `Files the template does not ship are not recorded: Sourdough never reads or touches them.` and the next steps: the commit line, then `then sourdough feed --dry-run lists the <n> files the first feed adds.`, or with nothing missing, `then sourdough feed --dry-run shows the app is fresh.` A dry run ends `Run sourdough adopt without --dry-run to write the manifest.`

| Dry-run note | Meaning |
| --- | --- |
| `Not inside a git repository: a real adopt would refuse.` | `git init` and commit first |
| `Uncommitted changes: a real adopt would refuse until the tree is clean.` | commit or stash first |
| `The folder is not writable: a real adopt would fail.` | check the folder's permissions |
| `git is not available: a real adopt would fail.` | put git on PATH, or set `SOURDOUGH_GIT` |

### Exit codes[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#exit-codes-1)

| Exit | Meaning | When |
| --- | --- | --- |
| `0` | adopted, or a dry run printed its plan | any mix of `match`, `yours`, `seeded` and `missing`: none of them raises the exit code |
| `1` | refused | a missing or unknown shape, bad oven flags, a folder that is missing or a file, a manifest already there, a leftover `.sourdough/base/`, a bad name, not a git repository, a dirty tree |
| `2` | tool error | no git, a folder adopt cannot write, a failed bake, a failed write |

A dry run refuses only for the first eight (the shape, the oven, the folder, the manifest, the leftover base, the name), and fails only when the bake fails or `git status` fails (both exit `2`). Refusals go to stderr, in this order:

| Refusal | Message starts with | Exit |
| --- | --- | --- |
| no `--shape` | `--shape is required: raw, raw-vue, laravel-blade, laravel-blade-vue or laravel-inertia.` and, when the app's files point at one, `This app looks like <shape> (<reason>).` | `1` |
| an unknown shape | `Unknown shape "<x>". The five shapes are: ...`, plus the replacement for a retired shape | `1` |
| an oven on a raw shape, or both ovens | `--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.` or `Choose one of --sail or --docker, not both.`, the words `sourdough new` uses | `1` |
| the folder does not exist | `The app directory "<dir>" does not exist.` | `1` |
| the folder is a file | `"<dir>" is a file, not a directory: pass the app's root folder.` | `1` |
| a manifest is already there | `"<dir>" already has a Sourdough manifest: Sourdough made or adopted this app.` | `1` |
| `.sourdough/base/` with no manifest | `"<dir>/.sourdough/base/" is left from a manifest that is gone.` | `1` |
| a name `sourdough new` would refuse | the generator's message, then `Pass --name "<a valid name>".` | `1` |
| git is not on PATH | `sourdough adopt needs git on PATH (or SOURDOUGH_GIT set to it)` | `2` |
| not inside a git work tree | `sourdough adopt only runs inside a git repository` | `1` |
| uncommitted or untracked changes | `Commit or stash your changes first` and up to ten `git status` lines | `1` |
| the folder cannot be written | `Cannot write "<dir>/.sourdough/manifest.json": the folder is not writable.` | `2` |
| the bake failed | `sourdough adopt failed: could not bake a fresh <shape> app to compare with:` (the Laravel shapes add that they need Composer and the network, or a warm Composer cache) | `2` |
| the manifest could not be written | `sourdough adopt failed:` | `2` |

### JSON[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#json)

`--json` prints one object with sorted keys: `dir`, `dry_run`, `exit`, `files`, `name`, `notes`, `shape`, `sourdough_version` and `summary`. `files` lists every template path, `match` included, sorted and never folded, as `{path, status, detail}`; `summary` counts each status.

```json
{
    "dir": "/srv/apps/course-finder",
    "dry_run": true,
    "exit": 0,
    "files": [
        {
            "path": ".env.example",
            "status": "seeded",
            "detail": "yours, as in every Sourdough app"
        },
        {
            "path": ".env.squire.example",
            "status": "match",
            "detail": "same as the template: Sourdough keeps it current"
        },
        {
            "path": "phpstan.neon",
            "status": "missing",
            "detail": "the next sourdough feed adds it"
        }
    ],
    "name": "course-finder",
    "notes": [],
    "shape": "raw",
    "sourdough_version": "0.4.0",
    "summary": {
        "match": 38,
        "missing": 1,
        "seeded": 2,
        "yours": 0
    }
}
```

(`files` is cut to three of its 41 entries here: a raw app that lacks `phpstan.neon`, on a dry run.) A refusal with `--json` prints its message to stderr and no JSON, as `feed` does.

### Environment[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#environment-1)

- `SOURDOUGH_GIT`: the git binary to run (default: `git` on PATH).
- `SOURDOUGH_COMPOSER`: the Composer binary the Laravel shapes bake their skeleton with (default: `composer` on PATH), as for `sourdough new`.
- `SOURDOUGH_POLICY`: the CREED policy the fresh bake takes its PHP floor from, as for `sourdough new`. Adopt with the policy the app's later feeds will use, or the floor shows up as a template change.

## sourdough feed[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-feed)

Bring Sourdough template updates into an app without clobbering what it has changed.

```
sourdough feed [dir] [--dry-run] [--json]
```

| Argument or option | Meaning |
| --- | --- |
| `dir` | the app directory to feed (default: the current directory) |
| `--dry-run` | print the plan and write nothing; exit `1` when feeding would change files |
| `--json` | print the plan, or the result, as JSON |

Feed bakes the app's shape again with the answers in its manifest, compares the base (what the manifest recorded), the app and the new loaf file by file, and writes the result. How it decides, and where the base comes from, is in [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#what-feed-does).

### Plan lines[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#plan-lines)

One line per file that changes, ` <action> <file> (<why>)`; files feed leaves alone are not listed.

| Action | Meaning |
| --- | --- |
| `add` | new in the template (or a seeded file new to the template, where the app has none) |
| `update` | the template changed it and the app had not: replaced |
| `merge` | both changed it in different places: three-way merged by `git merge-file` |
| `conflict` | both changed the same lines, there was no base to merge from, or the file is binary: marked, never overwritten |
| `remove` | the template dropped it and the app had not changed it |
| `keep` | the template dropped a file the app changed (it is the app's now), or changed a file the app deleted (it stays deleted) |
| `record` | the app already has the template's new version of the file: nothing is written, the manifest records it |

An app baked by Sourdough 0.1.6 with `--sail` or `--docker` has no oven in its manifest's answers (0.1.6 wrote the manifest before the oven's scaffold ran). Feed reads the oven from the app's files instead (a `Dockerfile`, `compose.yaml` and the `dev-up` script for Docker; `laravel/sail` and Sail's `compose.yaml` for Sail), says so in a `note:` line, and records the oven's files, so `sourdough manifest --check` is clean afterwards.

A conflict in a text file holds git's markers, `<<<<<<< app`, `=======` and `>>>>>>> sourdough <version>`; a binary conflict leaves the template's copy next to the app's as `<file>.sourdough-new`.

After the plan lines: how many base copies feed keeps in `.sourdough/base/`, a one-line count per action, and the next steps. A real feed ends with the undo line: `To undo the whole feed: git restore . && git clean -fd (the tree was clean before it ran).`

| Output | Meaning |
| --- | --- |
| `Fresh: the app already has everything Sourdough <version> bakes into a <template> app. Nothing to feed.` | nothing to do; nothing written, not even the manifest |
| `note: Not inside a git repository: ...` | dry runs only: a real feed would refuse |
| `note: Uncommitted changes: ...` | dry runs only: a real feed would refuse until the tree is clean |

### Exit codes[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#exit-codes-2)

| Run | `0` | `1` | `2` |
| --- | --- | --- | --- |
| `sourdough feed` | fed cleanly, or nothing to feed | fed, with conflicts to resolve | tool error |
| `sourdough feed --dry-run` | fresh | feeding would change files | tool error |

| Tool error | Message starts with | Exit |
| --- | --- | --- |
| no manifest in the directory (an app Sourdough did not make: adopt it first) | `No Sourdough manifest found in "<dir>".`, then `an app Sourdough did not make needs adopting first: sourdough adopt <dir> --shape <shape>.` | `2` |
| git is not on PATH | `sourdough feed needs git on PATH` | `2` |
| the app is not inside a git repository (real run) | `sourdough feed only runs inside a git repository` | `2` |
| uncommitted or untracked changes (real run) | `Commit or stash your changes first` | `2` |
| conflict markers or `.sourdough-new` copies left from the last feed | `Finish the last feed first` | `2` |
| the manifest was written by a newer Sourdough | `This app was fed by Sourdough <version>, newer than this one` | `2` |
| the manifest names a template this Sourdough does not bake | `The manifest names the template` | `2` |
| the manifest lists a path outside the app | `The manifest lists an unsafe path` | `2` |
| the bake failed (for example, Composer could not fetch the Laravel skeleton) | `sourdough feed failed:` | `2` |

### JSON[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#json-1)

`--json` prints one object: `dir`, `template`, `from` and `to` (the Sourdough versions), `dry_run`, `fresh`, `files` (one `{path, action, detail}` per plan line), `summary` (a count per action), `base_copies` (the files kept in `.sourdough/base/`), `notes`, and `exit`. A deprecated shape's notice is one of the `notes` on every run; feeding an app whose shape was removed stops with exit `2` and names the replacement. See [Support and sunset](https://sourdough.apps.wayne.edu/docs/guides/support.html).

### Environment[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#environment-2)

- `SOURDOUGH_GIT`: the git binary to run (default: `git` on PATH).
- `SOURDOUGH_COMPOSER`: the Composer binary the Laravel shapes bake their skeleton with (default: `composer` on PATH), as for `sourdough new`.
- `SOURDOUGH_POLICY`: the CREED policy the new loaf takes its PHP floor from, as for `sourdough new`. Feed with the same policy the app was baked with, or the floor shows up as a template change.

## Related[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#related)

- [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html)
- [Adopt an existing app](https://sourdough.apps.wayne.edu/docs/guides/adopt.html)
- [Plan format](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
