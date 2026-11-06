---
title: "Commands | Sourdough docs"
description: "Every sourdough command and option, from sourdough new's flags to sourdough manifest --check, with their exit codes."
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
| `--no-input` | never prompt (Sourdough 0.1 has no prompts) |
| `--json` | with `--dry-run`, print the plan as JSON |
| `--describe "<sentence>"` | describe what the app does; a model plans it (needs the `SOURDOUGH_LLM_*` env vars) |
| `--plan-only` | with `--describe`, print the plan as JSON and write nothing |
| `--plan <file>` | a ready-made plan file (JSON matching `resources/plan.schema.json`) |
| `--playbook` | kept for compatibility: Playbook is now the default |
| `--no-playbook` | skip the Playbook wiring (for CI and automated tests only; WSU apps need Playbook) |
| `--no-advisor` | skip the ADVISOR wiring (no `require-dev` entry, no `.mcp.json` git-ignore, no README section) |

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

### App name validation[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#app-name-validation)

The app name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

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

Seeded files are never reported: the app owns them.

## Related[#](https://sourdough.apps.wayne.edu/docs/reference/commands.html#related)

- [Plan format](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
