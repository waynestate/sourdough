---
title: Troubleshooting
description: What the exit codes mean when a bake is refused, Playbook cannot be read, a managed file has drifted, or a tool error stops the job.
---

# Troubleshooting

What to do when a bake is refused or a check fails. Each symptom below names the cause, the fix, and a command that checks the result. Start with the exit code: `0` done, `1` refused, `2` tool error. A starter that refuses loudly is easier to feed than one that fails quietly.

## Exit code 1: the bake was refused

### A `--raw --vue` app shows "No Vite build and no dev server"

Cause: the raw PHP + Vue app loads its page from Vite: from `public/build/manifest.json` after `npm run build`, or from the dev server while `npm run dev` runs. A fresh bake has neither, so `src/Vite.php` refuses with that sentence instead of printing tags that point nowhere.

Fix: build the front end once, or run the dev server next to `composer serve`.

Check:

```bash
npm install
npm run build
composer serve
```

http://localhost:8000 shows the app, and http://localhost:8000/api/me answers JSON.

### `sourdough new` refuses before writing anything: no read access to Playbook

Cause: by default the generated app requires `waynestate/playbook`, WSU's private shared-values package on git.wayne.edu. Before writing anything, `sourdough new` checks that this machine can read the package (a Composer credential, then a direct git probe); without read access it refuses with exit `1` and prints the how-to.

Fix: follow the printed how-to to get read access, then rerun the same command.

Check:

```bash
sourdough new my-app --dry-run --json
```

`--dry-run` runs the check but never refuses, and its JSON plan carries a `playbook` key with the mode, the access status and the probe that answered. Once the app exists, `composer playbook` (the script `@composer show waynestate/playbook --no-interaction`) shows whether Playbook is installed.

> [!WARNING]
> `--no-playbook` skips the Playbook wiring and is for CI and automated tests only. WSU apps need Playbook.

### git.wayne.edu is unreachable during the access check

Cause: the Playbook access check cannot reach git.wayne.edu. Sourdough still generates the app, but warns that `composer install` will need the connection.

Fix: restore the connection to git.wayne.edu, then install the app's dependencies.

Check:

```bash
composer install
```

Run it in the generated app; it completes without the Playbook fetch failing.

### `--playbook` and `--no-playbook` together

Cause: the two flags are mutually exclusive and are refused with exit `1`. `--playbook` is kept for compatibility and changes nothing, because Playbook is now the default.

Fix: drop one of the flags.

Check:

```bash
sourdough new my-app --dry-run
```

Exits 0 and prints the plan.

### An invalid flag, a non-empty target, or a bad app name

Cause: exit `1` also covers invalid flags, a non-empty target (Sourdough refuses to generate into a directory that already has files), and an app name that fails validation. The name (from `--name`, from the plan, or from the basename of `<dir>`) must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters; anything else is refused before anything is written.

Fix: correct the flags, point `dir` at an empty directory, and rename the app to something the allow-list accepts.

Check:

```bash
sourdough new my-app --dry-run
```

Prints the plan and writes nothing.

### The model gave up (`--describe`)

Cause: if the answer from the model is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`).

Fix: write the plan yourself and pass it in:

```bash
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name.

Check: the same command exits 0 when the plan is valid.

## Exit code 2: a tool error

### `sourdough manifest` without `--check`

Cause: without `--check` the command answers "Use `sourdough manifest --check` to check the app against its manifest." and exits `2`. A missing manifest in the directory is also a tool error (exit `2`).

Fix: run the command with `--check`, inside the generated app (or pass the app directory as an argument).

Check:

```bash
sourdough manifest --check
```

Exits 0 and prints `Clean: every managed file matches the manifest.`

### `--json` without `--dry-run`

Cause: `--json` is only available with `--dry-run`; without it the command exits `2`.

Fix: add `--dry-run`.

Check:

```bash
sourdough new my-app --dry-run --json
```

Prints the plan as JSON.

### The model could not be reached (`--describe`)

Cause: `--describe` needs the `SOURDOUGH_LLM_BASE_URL`, `SOURDOUGH_LLM_API_KEY` and `SOURDOUGH_LLM_MODEL` environment variables (no defaults, no key in code) and a reachable OpenAI-compatible endpoint. When the endpoint cannot be reached, the command prints `The model could not be reached:` plus the underlying error and exits `2`.

Fix: set the three variables and point them at a working endpoint (a hosted API, a local server, or WarriorGPT), then rerun. Any OpenAI-compatible endpoint works; Sourdough only needs the base URL, key, and model.

Check:

```bash
sourdough new --describe "A blog with posts and comments" --plan-only
```

Prints the plan as JSON without writing anything.

## Drift: the manifest and the Squire copy

### `sourdough manifest --check` exits 1 in a generated app

Cause: a managed file changed or is missing. The output lists one line per file, `changed  <file>` or `missing  <file>`. Seeded files (`.env.example` and `README.md`) are never reported: they're yours.

Fix: nothing, if the change is deliberate. `sourdough feed` three-way merges your edits with the template's, so a drifted managed file is fine; the check just names the files a feed will merge rather than replace. If the change was a mistake, restore the file from your version control.

Check:

```bash
sourdough manifest --check
```

Exits 0 when every managed file matches the manifest.

> [!TIP]
> Commit `.sourdough/manifest.json` (and `.sourdough/base/`, once a feed has written it) in generated apps. It's the record that keeps `feed` honest.

### `composer squire-copy:check` exits 1 (maintainers of Sourdough)

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

### PROCTOR's `preflight-pinned` check warns on a bare tag

Cause: PROCTOR 0.4's `preflight-pinned` check wants a 40-hex SHA and warns on a bare tag. New apps pin the Preflight CI components to the 0.1.4 release SHA (`79029cc2300702d0d450c42cc699e12af1b731f8`, with the tag in a trailing comment).

Fix: pin the Preflight component includes to the release SHA, not the tag. Moving a pin is a deliberate commit.

Check:

```bash
composer ci
```

Run it in the generated app; the PROCTOR check passes.

## Feeding: sourdough feed

### `sourdough feed` exits 2: "Commit or stash your changes first"

Cause: the app's git tree is not clean (a modified, staged or untracked file under the app folder). Feed only runs on a clean tree, so `git diff` shows exactly what it did and `git restore` undoes it.

Fix: commit or stash your work, then feed. `--dry-run` still plans on a dirty tree, with a note.

Check:

```bash
git status --porcelain
```

Prints nothing when the tree is clean.

### `sourdough feed` exits 1: a conflict

Cause: you and the template changed the same lines of a file, or feed had no base to merge from (you edited the file before your first commit), so it marked both versions instead of guessing. The plan line says which.

Fix: open each `conflict` file, keep what it should be, delete the `<<<<<<< app`, `=======` and `>>>>>>> sourdough <version>` lines, run your checks and commit. For a binary file, compare it with `<file>.sourdough-new`, keep one, and delete the copy.

Check:

```bash
git diff --check
```

Prints nothing when no conflict markers are left.

### `sourdough feed` exits 2: "Finish the last feed first"

Cause: a managed file still holds a marker from an earlier feed (`>>>>>>> sourdough <version>`), or a `.sourdough-new` copy is still lying around. Feeding on top of an unresolved conflict would bury it.

Fix: resolve the listed files as for a conflict above, delete any `.sourdough-new` copies, commit, then feed again.

Check:

```bash
sourdough feed --dry-run
```

Exits 0 or 1 (a plan) instead of 2.

### `sourdough feed` exits 2: "only runs inside a git repository" or "No Sourdough manifest"

Cause: feed stands on git and on the manifest `sourdough new` writes. An app outside git, or one Sourdough did not make (WELD included), cannot be fed. Writing a manifest into an existing app is `sourdough adopt`'s job, which is not built yet.

Fix: `git init` and commit the app first. For an app with no manifest, there is no feed yet.

Check:

```bash
sourdough manifest --check
```

Exits 0 or 1 when the app has a manifest, 2 when it has none.

## Related

- [Commands](reference/commands.md)
- [Templates](reference/templates.md)
- [The manifest](guides/manifest.md)
- [Keeping apps current](guides/keeping-current.md)
- [Upgrading](upgrading.md)
