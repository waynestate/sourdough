---
title: Troubleshooting
description: What the exit codes mean when a bake or an adopt is refused, Playbook cannot be read, a managed file has drifted, or a tool error stops the job.
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

Cause: PROCTOR 0.4 to 0.7's `preflight-pinned` check wants a 40-hex SHA and warns on a bare tag (0.8 also accepts a full release tag such as `@0.3.0`, and still warns on a branch, `~latest` or a partial version such as `0.3`). New apps pin the Preflight CI components to the 0.4.0 release SHA (`87d9b6fc6b0ed302dd729986525282cd7b567ebd`, with the tag in a trailing comment), which every PROCTOR accepts.

Fix: pin the Preflight component includes to the release SHA, with the tag in a trailing comment. Moving a pin is a deliberate commit.

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

Cause: feed stands on git and on a manifest. An app outside git cannot be fed, and neither can one Sourdough did not make (WELD included) until it has a manifest.

Fix: `git init` and commit the app first. For an app with no manifest, adopt it: `sourdough adopt --shape <shape>`, commit `.sourdough/`, then feed. See [Adopt an existing app](guides/adopt.md).

Check:

```bash
sourdough manifest --check
```

Exits 0 or 1 when the app has a manifest, 2 when it has none.

## Adopting: sourdough adopt

### `sourdough adopt` exits 1: "--shape is required" or "Unknown shape"

Cause: adopt never picks a shape; you name what the app already is. Without `--shape` it says what the app's files point at (`This app looks like laravel-inertia (it has artisan and inertiajs/inertia-laravel).`). A shape that is not one of the five is refused with the list, and a retired one with its replacement.

Fix: pass one of `raw`, `raw-vue`, `laravel-blade`, `laravel-blade-vue`, `laravel-inertia`. These are the manifest's `template` values, not `sourdough new`'s flags.

Check:

```bash
sourdough adopt --shape raw --dry-run
```

Exits 0 with the report.

### `sourdough adopt` exits 1: an oven or a name it cannot bake

Cause: `--sail` or `--docker` on a raw shape (`--sail and --docker are for the Laravel paths: the raw paths run on plain PHP.`), both ovens (`Choose one of --sail or --docker, not both.`), or a name `sourdough new` would refuse, followed by `Pass --name "<a valid name>".` The name defaults to the folder's basename, so a folder called `my app (old)` is refused.

Fix: drop the oven (an app with its own Docker setup needs neither), or pass `--name` with letters, numbers, spaces, dots, dashes and underscores, at most 63 characters, starting with a letter or number.

Check:

```bash
sourdough adopt --shape laravel-blade --name parking-permits --dry-run
```

Exits 0 with the report.

### `sourdough adopt` exits 1: "does not exist" or "is a file, not a directory"

Cause: `<dir>` is not there, or it names a file. Adopt takes the app's root folder.

Fix: pass the app's root folder, or run adopt inside it with no `<dir>`.

Check:

```bash
sourdough adopt --shape raw --dry-run
```

Exits 0 with the report.

### `sourdough adopt` exits 1: "already has a Sourdough manifest"

Cause: Sourdough made or adopted this app already. Adopting over a manifest would lose the record feed stands on, so adopt has no `--force`.

Fix: bring it up to date with `sourdough feed`. To adopt it again with another `--shape` or `--name`, delete `.sourdough/`, commit, and run adopt again.

Check:

```bash
sourdough feed --dry-run
```

Exits 0 (fresh) or 1 (a plan): the app already has its record.

### `sourdough adopt` exits 1: "is left from a manifest that is gone"

Cause: `.sourdough/base/` is there but `.sourdough/manifest.json` is not. Those base copies belong to a record that is gone, and a later feed would merge against them as if they were still true.

Fix: delete `.sourdough/`, commit, and run adopt again.

Check:

```bash
sourdough adopt --shape raw --dry-run
```

Exits 0 with the report.

### `sourdough adopt` exits 1: "only runs inside a git repository"

Cause: the app is not in git. Adopt wants the adoption to be one commit you can review, and every later feed to be undoable.

Fix:

```bash
git init
git add -A
git commit -m "Before Sourdough"
```

Check:

```bash
sourdough adopt --shape raw --dry-run
```

Exits 0, with no `note: Not inside a git repository` line.

### `sourdough adopt` exits 1: "Commit or stash your changes first"

Cause: the tree has uncommitted or untracked changes, listed under the message (up to ten, then `... and N more`). The hashes describe the files on disk, so uncommitted edits would be adopted as if they were committed, and the adoption should be a commit of its own.

Fix: commit or stash, then adopt.

Check:

```bash
git status --short
```

Prints nothing.

### `sourdough adopt` exits 2: git, the folder or the bake

Cause: one of these:
- `sourdough adopt needs git on PATH (or SOURDOUGH_GIT set to it)`: adopt checks the tree, and feed, which comes next, merges with git.
- `Cannot write "<dir>/.sourdough/manifest.json": the folder is not writable.`: adopt writes only that file.
- `sourdough adopt failed: could not bake a fresh <shape> app to compare with: ...`: the Laravel shapes bake a fresh `laravel/laravel` skeleton, so they need Composer and the network (or a warm Composer cache), the same as `sourdough new`.
- `sourdough adopt failed: ...` after the report was planned: the manifest could not be written.

Fix: put git on PATH or set `SOURDOUGH_GIT`; fix the folder's permissions; for a Laravel shape, check that `composer` runs (or set `SOURDOUGH_COMPOSER`) and that the network or the Composer cache is there.

Check:

```bash
git --version
composer --version
```

Both print a version.

### Many files are `yours`, and a note says the name may be wrong

Cause: the template writes the app's name into files (`composer.json`, `public/index.php`, the README), and `--name` defaulted to the folder's basename. If the app goes by another name, every file that carries it differs. Adopt flags each one (`differs; the template writes the app name here as "<name>", and your file does not have it`), and two or more add `note: <n> files differ where the template writes the app name`.

Fix: delete `.sourdough/`, commit, and adopt again with `--name` set to the name in `composer.json`. A `yours` file is never lost or overwritten either way; the right name only lets more files match.

Check:

```bash
sourdough adopt --shape raw --name parking-permits --dry-run
```

The name note is gone and more files are `match` (add `-v` to list them).

### Almost everything is `yours` on an older Laravel app

Cause: adopt compares with today's `laravel/laravel` skeleton and today's PHP floor from CREED's policy. An app several majors behind differs almost everywhere. That is correct: `yours` files are safe, and feed never touches them.

Fix: upgrade first with [Booster](https://booster.apps.wayne.edu/), one hop at a time, then adopt (or, if already adopted, feed: each file that now matches is recorded and managed from then on).

Check:

```bash
sourdough adopt --shape laravel-blade --dry-run
```

The summary line shows more `match` than before the upgrade.

## Related

- [Commands](reference/commands.md)
- [Templates](reference/templates.md)
- [The manifest](guides/manifest.md)
- [Adopt an existing app](guides/adopt.md)
- [Keeping apps current](guides/keeping-current.md)
- [Upgrading](upgrading.md)
