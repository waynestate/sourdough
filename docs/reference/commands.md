---
title: Commands
description: Every sourdough command and option, from sourdough new's flags to sourdough manifest --check, with their exit codes.
---

# Commands

Reference for the `sourdough` CLI: every command, argument, option and exit code, exactly as the sources define them. For the JSON plan behind `--describe` and `--plan`, see [Plan format](plan-format.md).

## sourdough new

Make a new Wayne State app with the stack already wired in.

```
sourdough new [dir] [options]
```

`dir` is the directory to create the app in. It is required unless you use `--describe`.

### Options

| Option | Meaning |
|---|---|
| `--raw` | use the raw PHP base |
| `--laravel` | use the Laravel base |
| `--vue` | add a Vue front end |
| `--inertia` | use Inertia/Vue (implies `--laravel`) |
| `--sail` | use Laravel Sail for the local runtime (not available in this release; planned) |
| `--docker` | use a Docker image for the local runtime (not available in this release; planned) |
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

> [!NOTE]
> `--sail` and `--docker` are planned features that refuse in this release. For the Laravel shapes (`--laravel`, `--laravel --vue` and `--inertia`) the plan still builds and prints, then the command writes "not available in this release" to stderr and exits `1` without writing anything; a `--dry-run` refuses the same way, so the preview matches the real run. The raw paths keep their own refusal at the plan step: "`--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP."

### Exit codes

Exit codes: `0` done, `1` refused (invalid flags, non-empty target, not available, model gave up), `2` tool error.

| Refused case | Exact message | Exit |
|---|---|---|
| `--describe` and `--plan` together | `--describe and --plan are mutually exclusive.` | `1` |
| `--playbook` and `--no-playbook` together | `--playbook and --no-playbook are mutually exclusive.` | `1` |
| `--plan-only` without `--describe` | `--plan-only is only available with --describe.` | `1` |
| `dir` missing without `--describe` | `The dir argument is required unless you use --describe.` | `1` |
| no read access to Playbook | `You need read access to Playbook (a private package on git.wayne.edu) to make a Wayne State app.` plus the how-to | `1` |
| `--json` without `--dry-run` | `--json is only available with --dry-run.` | `2` |
| the model could not be reached (`--describe`) | `The model could not be reached:` plus the underlying error | `2` |

Other refusals, all exit `1`: invalid flags, a non-empty target, a permutation that is not available in this release (the plan is printed first, then `not available in this release`), a model that gives up (the message points to `--plan=<file>`), and an app name that fails validation.

### Playbook access check

By default the generated app requires `waynestate/playbook` (WSU's private shared-values package on git.wayne.edu): the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends your token to git.wayne.edu, the seven setting names in `.env.example`, a `composer playbook` script that shows whether Playbook is installed, and a "WSU values (PLAYBOOK)" README section that explains how to get read access.

Before writing anything, `sourdough new` checks that this machine can read the package (a Composer credential, then a direct git probe):

| Situation | Behavior |
|---|---|
| no read access | refuses with exit `1` and prints the how-to |
| git.wayne.edu unreachable | still generates the app, but warns that `composer install` will need the connection |
| non-interactive run or CI environment | skips the check |
| `--dry-run` | runs the check but never refuses; the JSON plan carries a `playbook` key with the mode, the access status and the probe that answered |

After generation the Next steps point at `composer playbook`. `--playbook` is kept for compatibility and changes nothing; `--no-playbook` skips all of it. The two flags together are refused with exit `1`.

### ADVISOR wiring

Generated apps ship with ADVISOR wired in by default: `waynestate/advisor` lands in `require-dev`, `.mcp.json` is git-ignored (a client config starts a program for whoever opens the project), and the README gains an "ADVISOR" section. The Next steps print the install line once dependencies are in:

```
php vendor/bin/advisor install --client=claude --write
```

`--no-advisor` skips all of it.

### App name validation

The app name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

### Next steps printed after generation

| Shape | Steps |
|---|---|
| Raw PHP | `cd <dir>`, `composer install`, `php -S localhost:8000 -t public` |
| Laravel + Blade | `cd <dir>`, `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan serve` |
| Laravel + Blade + Vue islands | the Laravel + Blade steps plus `npm install` and `npm run build` |
| Laravel + Inertia/Vue | the Laravel + Blade steps plus `npm install` and `npm run build` |

With ADVISOR wired in, `php vendor/bin/advisor install --client=claude --write` is printed after `composer install`. With Playbook required, the output ends with: `WSU values: Playbook installs with composer install. Check it any time with: composer playbook`.

## sourdough manifest

Check a generated app against its Sourdough manifest.

```
sourdough manifest [dir] [--check]
```

| Argument or option | Meaning |
|---|---|
| `dir` | the app directory to check (default: the current directory) |
| `--check` | list managed files that changed or are missing; exit 1 when anything changed |

Without `--check` the command answers "Use `sourdough manifest --check` to check the app against its manifest." and exits `2`.

Exit codes: `0` clean, `1` changed or missing, `2` tool error.

| Output | Meaning |
|---|---|
| `Clean: every managed file matches the manifest.` | every managed file matches the manifest |
| `changed  <file>` | a managed file changed |
| `missing  <file>` | a managed file is missing |

Seeded files are never reported: the app owns them.

## Related

- [Plan format](plan-format.md)
- [Wiring the stack in](../guides/wiring-the-stack.md)
