<div align="center">

[![Sourdough: dough in a jar, risen past its gold band](docs/readme/hero.jpg)](site/index.html)

# Sourdough

![php](docs/readme/badges/php.svg) ![license](docs/readme/badges/license.svg) ![exit codes](docs/readme/badges/exit-codes.svg) [![GitHub CI](https://github.com/waynestate/sourdough/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/sourdough/actions/workflows/ci.yml)

**Feed it and it grows.**

</div>

A starter you feed: `sourdough new` makes a Wayne State app with the stack already wired in.

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Every loaf gets proofed before it ships.

Documentation: the [docs site](site/docs/index.html) is built from [`docs/`](docs/) by Squire's docs builder; `composer docs:build` rebuilds it and `composer docs:check` fails when it is stale. The docs pages wear the front page's header, menu, palette and fonts: edit [`docs/_header.html`](docs/_header.html) and [`docs/theme/sourdough.css`](docs/theme/sourdough.css) (named by the `theme` key in `docs/docs.json`), never the kit's `docs.css`.

Front page: [`site/index.html`](site/index.html), a static page you can open in a browser or serve from `site/`.

On Citadel it is served by the raw-PHP deploy layer in [`deploy/`](deploy/): a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

## Where the code lives

The canonical repository is on nougat: [enterprise-apps/sourdough](https://nougat.snu.gs/enterprise-apps/sourdough). git.wayne.edu and GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) are read-only mirrors of it.
Open issues and merge requests on nougat, not on the mirrors.
This moves to git.wayne.edu once it has CI runners. Nougat is the starter culture; the mirrors are just slices.

Sourdough is the app starter for the WSU stack. One command scaffolds a new app with SESAME (auth), HELMET (baseline runtime), Pinstripe (coding standard), PROCTOR (checks), and the Preflight CI components already in place. It is one PHP command, so it works on Windows, macOS, and Linux.

<div align="center">

![The proof: dough rises past its gold band as WayneUI, SESAME, HELMET, PROCTOR and Preflight go in one by one, over the feeding schedule](docs/readme/proof.svg)

<sub>The proofing log from the front page: what goes into the tub when you run <code>sourdough new</code>, and the day-to-day check that keeps it alive.</sub>

</div>

## Install

```
composer global require waynestate/sourdough
```

After `composer global require`, the `sourdough` command lives in `composer global config bin-dir --absolute`; put that directory on your PATH.

Or scaffold without installing, with `composer create-project waynestate/sourdough`.

## Usage

```
sourdough new [dir] [options]
```

`dir` is the directory to create the app in. It is required unless you use `--describe`.

Options:

- `--raw` use the raw PHP base
- `--laravel` use the Laravel base
- `--vue` add a Vue front end
- `--inertia` use Inertia/Vue (implies `--laravel`)
- `--sail` run the Laravel app on Sail for local work: compose.yaml and laravel/sail in require-dev, MySQL 8.4 LTS; needs WSL2 on Windows, where `--docker` is the route that works natively
- `--docker` generate a Dockerfile and compose.yaml for local work (native on every OS; amd64 and arm64): the app's own base images, pinned by digest, are the single place to swap in SQUIRE's Citadel-parity image when it exists; MySQL 8.4; Playbook credentials reach the build only as a BuildKit secret, never a build arg
- `--dry-run` print the plan and write nothing
- `--name` app name (default: the basename of `<dir>`)
- `--no-input` never prompt (Sourdough 0.1 has no prompts)
- `--json` with `--dry-run`, print the plan as JSON
- `--describe "<sentence>"` describe what the app does; a model plans it (needs the `SOURDOUGH_LLM_*` env vars)
- `--plan-only` with `--describe`, print the plan as JSON and write nothing
- `--plan <file>` a ready-made plan file (JSON matching `resources/plan.schema.json`)
- `--playbook` kept for compatibility: Playbook is now the default
- `--no-playbook` skip the Playbook wiring (for CI and automated tests only; WSU apps need Playbook)
- `--no-advisor` skip the ADVISOR wiring (no `require-dev` entry, no `.mcp.json` git-ignore, no README section)

Exit codes: `0` done, `1` refused (invalid flags, non-empty target, not available, model gave up), `2` tool error.

By default the generated app requires `waynestate/playbook` (WSU's private shared-values package on git.wayne.edu): the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends your token to git.wayne.edu, the seven setting names in `.env.example`, a `composer playbook` script that shows whether Playbook is installed, and a "WSU values (PLAYBOOK)" README section that explains how to get read access. Before writing anything, `sourdough new` checks that this machine can read the package (a Composer credential, then a direct git probe); without read access it refuses with exit `1` and prints the how-to, and if it cannot reach git.wayne.edu it still generates the app but warns that `composer install` will need the connection. Non-interactive runs and CI environments skip the check. `--dry-run` runs the check but never refuses, and its JSON plan carries a `playbook` key with the mode, the access status and the probe that answered. After generation the Next steps point at `composer playbook`. `--playbook` is kept for compatibility and changes nothing; `--no-playbook` skips all of it. The two flags together are refused with exit `1`.

Generated apps ship with ADVISOR wired in by default: `waynestate/advisor` lands in `require-dev`, `.mcp.json` is git-ignored (a client config starts a program for whoever opens the project), and the README gains an "ADVISOR" section. The Next steps print the install line once dependencies are in:

```
php vendor/bin/advisor install --client=claude --write
```

`--no-advisor` skips all of it.

The app name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

## Permutations

| Flags | Permutation |
|---|---|
| (none) or `--raw` | Raw PHP with WayneUI's standalone path |
| `--raw --vue` | Raw PHP + Vue SPA over JSON |
| `--laravel` | Laravel + Blade |
| `--laravel --vue` | Laravel + Blade + Vue islands |
| `--laravel --inertia` (or `--inertia`) | Laravel + Inertia/Vue |

All five permutations generate in 0.1. `--raw --vue` is the raw PHP bake with a Vue 3 front end over it: PHP answers JSON under `/api/`, Vite builds the Vue app, and `src/Vite.php` loads it from the build or the dev server.

Oracle is wired but dormant in every generated app. The settings `ORACLE_USERNAME`, `ORACLE_PASSWORD`, and either `ORACLE_TNS` or `ORACLE_HOST`, `ORACLE_PORT`, `ORACLE_SERVICE_NAME` ship commented out in `.env.example`; while they are empty, nothing connects and nothing errors. Once you fill them in, Laravel apps check the wiring with `php artisan oracle:check`, and raw PHP apps run queries through the `App\Oracle` helper, which takes values only as binds and answers mistakes with plain sentences. The command finds the check file where it lives: `oracle-check.php` at the app root, where Squire's Laravel layout ships it, first, then `deploy/oracle-check.php`.

## Describe a plan

Instead of choosing flags, describe what the app does and let a model plan it:

```
sourdough new --describe "A blog with posts and comments"
```

Sourdough asks an OpenAI-compatible model for a plan, validates it against `resources/plan.schema.json`, and prints it. The model kneads the dough, but you decide when the loaf is ready to bake: if the answer is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`). You write the plan yourself and pass it in.

To see the plan as JSON without writing anything:

```
sourdough new --describe "A blog with posts and comments" --plan-only
```

Generation from a plan is not built yet in 0.1. Save the plan to a file and pass it with `--plan`:

```
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name.

### Model configuration

`--describe` needs three environment variables (no defaults, no key in code):

- `SOURDOUGH_LLM_BASE_URL` the OpenAI-compatible endpoint, including the API path (usually `/v1`)
- `SOURDOUGH_LLM_API_KEY` the API key
- `SOURDOUGH_LLM_MODEL` the model name

Any OpenAI-compatible endpoint works: a hosted API, a local server, or WarriorGPT. Sourdough does not care about the hostname; it only needs the base URL, key, and model.

A plan is a small JSON object:

```json
{
    "permutation": "raw",
    "parts": ["sesame", "helmet"],
    "name": "my-app",
    "models": [
        {
            "name": "Post",
            "fields": [
                { "name": "title", "type": "string" },
                { "name": "body", "type": "text" }
            ]
        }
    ],
    "pages": ["/", "/posts"]
}
```

`permutation`, `parts`, and `name` are required; `models` and `pages` are optional. `parts` is a subset of `sesame`, `helmet`, `proctor`, and `preflight`.

## The manifest

Every app Sourdough generates carries a `.sourdough/manifest.json` recording what was generated: the Sourdough version, the template, the answers you chose, and a sha256 hash of every file, each marked `managed` (Sourdough owns it and will update it) or `seeded` (Sourdough wrote it once and you own it now: `.env.example` and `README.md`).

The manifest is the starter's memory: it remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own. `sourdough feed` reads it to bring template updates into your app without clobbering what you've changed.

`sourdough manifest --check` lists the managed files that have drifted from the manifest:

```
sourdough manifest --check
```

Exit codes: `0` clean, `1` a managed file changed or is missing, `2` tool error (no manifest, or no `--check`). Seeded files are never reported. They're yours.

Commit the manifest. It's the record that keeps `feed` honest.

## Feeding an app

`sourdough feed` brings template updates into an app made with `sourdough new`, without clobbering what you've changed. Install the new Sourdough, then, in the app, on a clean git tree:

```
sourdough feed --dry-run
sourdough feed
```

Feed bakes your app's shape again with the answers in its manifest, then goes file by file:

- a file the template did not change is left alone, whatever you did to it;
- a managed file you never touched takes the template's new version;
- a file you both changed is three-way merged by git (`git merge-file`), from the version Sourdough wrote last time; where you both changed the same lines, git's conflict markers go in the file (`<<<<<<< app` and `>>>>>>> sourdough <version>`), never a silent overwrite;
- new template files are added, and files the template dropped are removed only if you never changed them;
- a file that already matches the template's new version is only recorded in the manifest (`record`); that is also how an app baked by 0.1.6 with `--sail` or `--docker` gets its oven into the manifest;
- seeded files (`README.md`, `.env.example`) are never touched.

Then the manifest moves to the new base, and the template's version of each managed file you've changed is kept in `.sourdough/base/`, so the next feed can merge from it even when git history can't. Commit both.

Exit codes: `0` fed cleanly (or nothing to feed), `1` fed with conflicts to resolve, `2` tool error (no manifest, no git, a dirty tree, conflicts left from the last feed, a manifest from a newer Sourdough). With `--dry-run` nothing is written: `0` the app is fresh, `1` feeding would change files, so `sourdough feed --dry-run` in CI fails the pipeline when an app falls behind its template. `--json` prints the plan for machines.

Feed needs git and nothing else: git is the merge engine and the undo button (`git restore . && git clean -fd`). The Laravel shapes bake on a fresh `laravel/laravel` skeleton, so feeding one needs Composer and the network, the same as `sourdough new`; skeleton updates arrive as template updates. The full story is in [Keeping apps current](docs/guides/keeping-current.md). A fed starter is a happy starter.

The deploy files in your app (`build.sh`, `scripts/release.sh`, the `.squire/` config, and their friends) come from Squire's exported copy, checked into this repository at `templates/squire/`. Squire owns them; Sourdough installs them as-is and records them in the manifest, so you never edit them by hand. To refresh them, run `composer squire-copy:sync`, which rewrites the copy from the installed `waynestate/squire` package, then verify it with `composer squire-copy:check` (part of `composer ci`).

## Testing

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](MAINTAINING.md).

From the repo root:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate --strict
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli php bin/sourdough new /tmp/demo --dry-run
```

The smoke gate bakes a shape for real and runs the generated app's own checks; see the "Smoke gate" section of [MAINTAINING.md](MAINTAINING.md) for the full recipe. On Windows, `.\scripts\smoke-shape.ps1 <shape>` runs it in one line.

The proof harness runs that gate on every shape and every Laravel shape in each oven, one after another in Docker: it bakes each with this checkout, checks the manifest, feeds a bake of the last release forward, installs the app, runs its own suite, PROCTOR and `composer ci`, then boots it and fetches its front page. `sh scripts/proof.sh` on Linux and macOS, `.\scripts\proof.ps1` on Windows. What each run proves, and the Last run table, are on the [Proof](docs/reference/proof.md) page.

The site accessibility gate runs axe-core (WCAG 2.2 AA) over every committed page under `site/` in Docker, in light and dark at 360 px and 1280 px: `sh scripts/a11y.sh` from Git Bash, Linux or macOS, `scripts\a11y.ps1` on Windows.

### Tests in the apps it bakes

The Laravel shapes (Blade, Blade + Vue islands, Inertia) come with Pest 4. The skeleton's PHPUnit example keeps running, `composer test` and `composer ci` run Pest, and the baked tests read `.env` when it exists, otherwise `.env.example`, on top of `phpunit.xml`. Their `.gitlab-ci.yml` runs the php-test matrix with `vendor/bin/pest` too, because PHPUnit refuses to load Pest files. Production refuses to boot on the tests' dummy `APP_KEY` (`app/Support/DummyAppKey.php`). The raw PHP shape is unchanged. Write new tests in Pest.

## CI

Strongly recommended: a git.wayne.edu instance runner.

<details>
<summary>On the bench: the five loaves and the feeding schedule</summary>

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](docs/readme/gallery.jpg)

</details>

<details>
<summary>The recipe card: Sourdough's brand board</summary>

![Sourdough brand board: the starter jar mark, palette and type](docs/readme/board.jpg)

</details>

<div align="center">

## Same kitchen, many ovens

<a href="https://github.com/waynestate/wayne-ui"><img src="docs/readme/cast/wayneui.png" width="44" alt="WayneUI"></a>
<a href="https://github.com/waynestate/creed"><img src="docs/readme/cast/creed.png" width="44" alt="CREED"></a>
<a href="https://github.com/waynestate/proctor"><img src="docs/readme/cast/proctor.png" width="44" alt="PROCTOR"></a>
<a href="https://github.com/waynestate/sesame"><img src="docs/readme/cast/sesame.png" width="44" alt="SESAME"></a>
<a href="https://github.com/waynestate/helmet"><img src="docs/readme/cast/helmet.png" width="44" alt="HELMET"></a>
<a href="https://github.com/waynestate/sourdough"><img src="docs/readme/cast/sourdough.png" width="44" alt="Sourdough"></a>
<a href="https://github.com/waynestate/squire"><img src="docs/readme/cast/squire.png" width="44" alt="Squire"></a>
<a href="https://github.com/waynestate/preflight"><img src="docs/readme/cast/preflight.png" width="44" alt="Preflight"></a>
<a href="https://github.com/waynestate/pinstripe"><img src="docs/readme/cast/pinstripe.png" width="44" alt="Pinstripe"></a>
<a href="https://github.com/waynestate/booster"><img src="docs/readme/cast/booster.png" width="44" alt="Booster"></a>
<a href="https://github.com/waynestate/rollcall"><img src="docs/readme/cast/rollcall.png" width="44" alt="Rollcall"></a>
<a href="https://nougat.snu.gs/enterprise-apps/drawbridge"><img src="docs/readme/cast/drawbridge.png" width="44" alt="Drawbridge"></a>
<a href="https://github.com/waynestate/advisor"><img src="docs/readme/cast/advisor.png" width="44" alt="ADVISOR"></a>
<a href="https://github.com/waynestate/weld"><img src="docs/readme/cast/weld.png" width="44" alt="WELD"></a>

<sub>Sourdough is one of the parts. Feed it, and it grows; just do not leave it on the counter over a long weekend.</sub>

</div>
