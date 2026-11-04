---
title: "Troubleshooting | Sourdough docs"
description: "What the exit codes mean when a bake is refused, Playbook cannot be read, a managed file has drifted, or a tool error stops the job."
url: https://sourdough.apps.wayne.edu/docs/troubleshooting.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

What to do when a bake is refused or a check fails. Each symptom below names the cause, the fix, and a command that checks the result. Start with the exit code: `0` done, `1` refused, `2` tool error. A starter that refuses loudly is easier to feed than one that fails quietly.

## Exit code 1: the bake was refused[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#exit-code-1-the-bake-was-refused)

### `--raw --vue` prints the plan, then exits 1[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#raw-vue-prints-the-plan-then-exits-1)

Cause: Raw PHP + Vue SPA over JSON is the one permutation that does not generate in 0.1. Sourdough prints the plan first, then `not available in this release`; a later step adds it.

Fix: pick one of the four shapes that bake in 0.1 (`--raw`, `--laravel`, `--laravel --vue`, `--inertia`), or keep the printed plan for the release that ships the fifth loaf.

Check:

```bash
sourdough new my-app --raw --dry-run
```

Exits 0 and writes nothing.

### `sourdough new` refuses before writing anything: no read access to Playbook[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#sourdough-new-refuses-before-writing-anything-no-read-access-to-playbook)

Cause: by default the generated app requires `waynestate/playbook`, WSU's private shared-values package on git.wayne.edu. Before writing anything, `sourdough new` checks that this machine can read the package (a Composer credential, then a direct git probe); without read access it refuses with exit `1` and prints the how-to.

Fix: follow the printed how-to to get read access, then rerun the same command.

Check:

```bash
sourdough new my-app --dry-run --json
```

`--dry-run` runs the check but never refuses, and its JSON plan carries a `playbook` key with the mode, the access status and the probe that answered. Once the app exists, `composer playbook` (the script `@composer show waynestate/playbook --no-interaction`) shows whether Playbook is installed.

Warning

`--no-playbook` skips the Playbook wiring and is for CI and automated tests only. WSU apps need Playbook.

### git.wayne.edu is unreachable during the access check[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#git-wayne-edu-is-unreachable-during-the-access-check)

Cause: the Playbook access check cannot reach git.wayne.edu. Sourdough still generates the app, but warns that `composer install` will need the connection.

Fix: restore the connection to git.wayne.edu, then install the app's dependencies.

Check:

```bash
composer install
```

Run it in the generated app; it completes without the Playbook fetch failing.

### `--playbook` and `--no-playbook` together[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#playbook-and-no-playbook-together)

Cause: the two flags are mutually exclusive and are refused with exit `1`. `--playbook` is kept for compatibility and changes nothing, because Playbook is now the default.

Fix: drop one of the flags.

Check:

```bash
sourdough new my-app --dry-run
```

Exits 0 and prints the plan.

### An invalid flag, a non-empty target, or a bad app name[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#an-invalid-flag-a-non-empty-target-or-a-bad-app-name)

Cause: exit `1` also covers invalid flags, a non-empty target (Sourdough refuses to generate into a directory that already has files), and an app name that fails validation. The name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

Fix: correct the flags, point `dir` at an empty directory, and rename the app to something the allow-list accepts.

Check:

```bash
sourdough new my-app --dry-run
```

Prints the plan and writes nothing.

### The model gave up (`--describe`)[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#the-model-gave-up-describe)

Cause: if the answer from the model is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`).

Fix: write the plan yourself and pass it in:

```bash
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name.

Check: the same command exits 0 when the plan is valid.

## Exit code 2: a tool error[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#exit-code-2-a-tool-error)

### `sourdough manifest` without `--check` [#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#sourdough-manifest-without-check)

Cause: without `--check` the command answers "Use `sourdough manifest --check` to check the app against its manifest." and exits `2`. A missing manifest in the directory is also a tool error (exit `2`).

Fix: run the command with `--check`, inside the generated app (or pass the app directory as an argument).

Check:

```bash
sourdough manifest --check
```

Exits 0 and prints `Clean: every managed file matches the manifest.`

### `--json` without `--dry-run` [#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#json-without-dry-run)

Cause: `--json` is only available with `--dry-run`; without it the command exits `2`.

Fix: add `--dry-run`.

Check:

```bash
sourdough new my-app --dry-run --json
```

Prints the plan as JSON.

### The model could not be reached (`--describe`)[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#the-model-could-not-be-reached-describe)

Cause: `--describe` needs the `SOURDOUGH_LLM_BASE_URL`, `SOURDOUGH_LLM_API_KEY` and `SOURDOUGH_LLM_MODEL` environment variables (no defaults, no key in code) and a reachable OpenAI-compatible endpoint. When the endpoint cannot be reached, the command prints `The model could not be reached:` plus the underlying error and exits `2`.

Fix: set the three variables and point them at a working endpoint (a hosted API, a local server, or WarriorGPT), then rerun. Any OpenAI-compatible endpoint works; Sourdough only needs the base URL, key, and model.

Check:

```bash
sourdough new --describe "A blog with posts and comments" --plan-only
```

Prints the plan as JSON without writing anything.

## Drift: the manifest and the Squire copy[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#drift-the-manifest-and-the-squire-copy)

### `sourdough manifest --check` exits 1 in a generated app[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#sourdough-manifest-check-exits-1-in-a-generated-app)

Cause: a managed file changed or is missing. The output lists one line per file, `changed <file>` or `missing <file>`. Seeded files (`.env.example` and `README.md`) are never reported: they're yours.

Fix: managed files belong to Sourdough, so do not edit them by hand. Until `feed` ships, apps generated earlier pick up template changes by hand; restore the drifted file from your version control, or regenerate the app.

Check:

```bash
sourdough manifest --check
```

Exits 0 when every managed file matches the manifest.

Tip

Commit `.sourdough/manifest.json` in generated apps; the planned `feed` will need it. It's the record that keeps `feed` honest.

### There is no `sourdough update` yet[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#there-is-no-sourdough-update-yet)

Cause: the update story (`.sourdough.json`, `sourdough check`, a merging `sourdough update`, tagged template releases) is still a gap for the backlog, so there is no command that brings an app forward to a newer template. Until `feed` ships, `sourdough manifest --check` is the gate.

Fix: run the check to see what drifted, and bring template changes into the app by hand.

Check:

```bash
sourdough manifest --check
```

Lists the managed files that have drifted from the manifest.

### `composer squire-copy:check` exits 1 (maintainers of Sourdough)[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#composer-squire-copy-check-exits-1-maintainers-of-sourdough)

Cause: the committed copy at `templates/squire/` has drifted from a fresh export from the installed `waynestate/squire` package. The check is part of `composer ci`.

Fix: rewrite the copy from the installed package, then commit `templates/squire/`:

```bash
composer squire-copy:sync
```

Check:

```bash
composer squire-copy:check
```

Exits 0 when the committed copy matches.

### PROCTOR's `preflight-pinned` check warns on a bare tag[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#proctor-s-preflight-pinned-check-warns-on-a-bare-tag)

Cause: PROCTOR 0.4's `preflight-pinned` check wants a 40-hex SHA and warns on a bare tag. New apps pin the Preflight CI components to the 0.1.4 release SHA (`79029cc2300702d0d450c42cc699e12af1b731f8`, with the tag in a trailing comment).

Fix: pin the Preflight component includes to the release SHA, not the tag. Moving a pin is a deliberate commit.

Check:

```bash
composer ci
```

Run it in the generated app; the PROCTOR check passes.

## Related[#](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#related)

- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [Templates](https://sourdough.apps.wayne.edu/docs/reference/templates.html)
- [The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html)
- [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html)
- [Upgrading](https://sourdough.apps.wayne.edu/docs/upgrading.html)
