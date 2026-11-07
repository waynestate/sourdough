---
title: Keeping apps current
description: How sourdough feed reads the manifest and brings template updates into your app without clobbering what you have changed.
---

# Keeping apps current

A project that was fresh on the day it was generated is stale a month later, so this page is about keeping generated apps fed: what the manifest records, how `sourdough feed` merges template updates in without clobbering your changes, and how to make CI tell you when an app falls behind. Like any starter, it needs regular feeding, or it goes flat. Commit the manifest in your app, run `sourdough feed --dry-run` in CI, then read on.

## Record where the app came from

Every app carries a `.sourdough/manifest.json` recording the Sourdough version, the template, the answers you chose, and a sha256 hash of every file, each marked `managed` or `seeded`. Other tools do the same: Copier writes `.copier-answers.yml`, and cruft writes `.cruft.json`. Without this record there is nothing to compare against, so no update is possible.

> [!WARNING]
> Do not hand-edit the manifest. It is the starter's memory: it remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own.

[The manifest](./manifest.md) has the full key list and the managed/seeded split.

## Check for drift

`sourdough manifest --check` lists the managed files that have drifted from the manifest, the ones you (or someone) edited since Sourdough wrote them:

```bash
sourdough manifest --check
```

Exit codes: `0` clean, `1` a managed file changed or is missing, `2` tool error (no manifest, or no `--check`). Seeded files are never reported. They're yours. Drift is not wrong: `feed` merges your edits with the template's. The check tells you which files a feed will have to merge rather than simply replace.

## Feed the app: a merge, not an overwrite

Upgrade the Sourdough CLI first ([Upgrading](../upgrading.md)), then, in the app, on a clean git tree, look before you leap:

```bash
sourdough feed --dry-run
```

The plan lists one line per file that would change, with the reason, and writes nothing:

```
Would feed the laravel-inertia app: Sourdough 0.1.5 to 0.1.6 (dry run: nothing written)
  update    config/helmet.php (you had not changed it)
  merge     routes/web.php (your edits kept, the template's merged in)
  conflict  vite.config.ts (1 conflicting hunk: you and the template changed the same lines; resolve the markers)
Would keep the template's version of 2 changed files in .sourdough/base/, so the next feed can merge from it.
Plan: 1 update, 1 merge, 1 conflict.
```

Then feed it for real, and review it like any other change:

```bash
sourdough feed
git status
git diff
```

### What feed does

Feed is the update model of Copier and cruft (ADR-0014), built on plain git instead of a Python runtime:

1. It reads `.sourdough/manifest.json`: the template, your answers, and a sha256 of every file Sourdough wrote last time (the base).
2. It bakes the same shape with the same answers, with the Sourdough you have installed, into a scratch folder: the loaf `sourdough new` would bake today. `new` and `feed` bake through the same code, so the two cannot disagree.
3. It compares the three versions of each file (the base, your app's, the new loaf's) and acts:

| The template | Your app | Feed |
|---|---|---|
| did not change it | anything | leaves it alone (no line in the plan) |
| changed it | never touched it | `update`: takes the template's new version |
| changed it | changed it too | `merge`: `git merge-file` applies the template's change on top of yours |
| changed the same lines | changed them too | `conflict`: git's markers in the file, `<<<<<<< app` ... `>>>>>>> sourdough <version>` |
| added it | has no such file | `add` |
| added it | has its own file there | `conflict`: both versions marked |
| dropped it | never touched it | `remove` (and its emptied folder) |
| dropped it | changed it | `keep`: it is yours now, and leaves the manifest |
| changed it | deleted it | `keep`: it stays deleted |

Seeded files (`README.md`, `.env.example`) are never touched; a seeded file new to the template is written only where you have none. Line endings are forgiven: a Windows checkout with CRLF endings still matches what Sourdough wrote, and the files feed writes keep your checkout's endings. Binary files are never merged: the template's copy lands next to yours as `<file>.sourdough-new`.

4. It moves the manifest to the new base (the new loaf's hashes and the new Sourdough version) and keeps the template's version of every managed file you have changed in `.sourdough/base/<file>.base`. Commit both.

### Where the base comes from

A three-way merge needs the version Sourdough wrote last time. The manifest records only its hash, so feed finds the content by that hash: first in `.sourdough/base/` (kept by the previous feed), then in any commit of your app's git history (the commit right after `sourdough new` holds every file as baked). A candidate is used only when its sha256 matches the manifest, so a wrong base can never sneak in.

When neither has it (you edited a file before your first commit), feed cannot tell your edits from the template's, so it does not guess: every place the two versions differ is marked as a conflict, and the lines they share stand once. Commit right after `sourdough new` and this never happens.

### Conflicts

A conflict is never a silent overwrite. Feed exits `1`, and the file holds git's markers:

```
<<<<<<< app
your version
=======
the template's version
>>>>>>> sourdough 0.1.6
```

Edit the file to what it should be, delete the markers, and run your checks. `git diff --check` lists any markers left behind, so put it in a pre-commit hook or CI. Until the markers (and any `.sourdough-new` copies) are gone, the next feed refuses with exit `2` and lists them: finish one feeding before the next.

### Rules feed keeps

- **A clean git tree first.** Copier recommends a clean `git status` but does not enforce it; feed enforces it (exit `2`), untracked files included, so `git status` and `git diff` show exactly what it did. To undo a whole feed: `git restore . && git clean -fd`.
- **Git, and only git.** Feed needs git on PATH (or `SOURDOUGH_GIT` pointing at it), on Windows, macOS and Linux alike. The Laravel shapes also bake on a fresh `laravel/laravel` skeleton through Composer, the same as `sourdough new`, so feeding one needs Composer and the network; Laravel's own skeleton updates arrive as template updates, merged the same way.
- **Forward only.** A manifest written by a newer Sourdough than the one running is refused (exit `2`). Update Sourdough first: a starter only rises forward.
- **The answers stay.** Feed bakes with the answers in the manifest (the shape, `--sail` or `--docker`, Playbook and ADVISOR). It does not change them.

### Exit codes

| Run | `0` | `1` | `2` |
|---|---|---|---|
| `sourdough feed` | fed cleanly, or nothing to feed | fed, with conflicts to resolve | tool error: no manifest, no git, a dirty tree, conflicts left from the last feed, a newer manifest, a failed bake |
| `sourdough feed --dry-run` | fresh: nothing to feed | feeding would change files | tool error |

Add `--json` to either for a machine-readable plan: the template, `from` and `to` versions, one entry per file (`path`, `action`, `detail`), a summary count per action, the base copies, any notes, and the exit code.

### Feed in CI

Put the dry run in the app's pipeline so an app that falls behind its template fails the pipeline instead of surfacing as drift later; that is what makes a starter "fed":

```bash
sourdough feed --dry-run
```

Copier's `copier check-update` is the same idea: `--quiet` makes it exit 2 when an update exists, and `--output-format json` gives machines the answer.

### Apps Sourdough did not make, and WELD

Feed needs the manifest `sourdough new` writes. An app without one (an app Sourdough did not make, or WELD itself, which was built by hand and checks its drift against a fresh Inertia bake with its own regen check) gets exit `2`. Writing a manifest into an existing app is `sourdough adopt`'s job (ADR-0014), which is not built yet.

## Versioned template releases

Tag template releases with semantic versions, and put a short "what changed, what to do" entry in the changelog for each. Copier picks the newest tag by default. An update tool is only as good as the history it can read.

## Compatibility CI

On every change to Sourdough, and on every release of WayneUI, SESAME, HELMET and PROCTOR, generate each permutation, install it and run its own tests and the PROCTOR check. Run it on a schedule (nightly), not only on changes, covering PHP 8.4 and 8.5 and the current and previous Laravel major. The starter is the place where all the stack libraries meet, so it is where a break shows first. A starter with no commits still rots, because the things it depends on keep moving.

## Keep templates thin

Put shared behavior in libraries, not in the template. Code that lives in SESAME, HELMET and PROCTOR updates with `composer update`, so only the glue lives in the template and the merge stays small. Other universities ship starters the same way: a Composer package plus an install command. A new site installs it with `composer require` and `php artisan starterkit:install`, and an existing site can require the library without publishing the project files. This is the core-plus-adapters rule applied to the starter itself.

## Owners, windows and sunsets

Starters die quietly. The React team deprecated Create React App on 2025-02-14, saying it had no active maintainers, and installing it now prints a warning that points at current frameworks. Laravel replaced Breeze and Jetstream with new kits when Laravel 12 shipped. Each permutation should name an owner, tie its support window to the framework and PHP end dates, run the scheduled build, and have the check print a deprecation notice with the migration note.

## Refreshing the Squire templates

The deploy files in your app (`build.sh`, `scripts/release.sh`, the `.squire/` config, and their friends) come from Squire's exported copy, checked into the Sourdough repository at `templates/squire/`. Squire owns them; Sourdough installs them as-is and records them in the manifest, so you never edit them by hand. When Squire's templates change, regenerate the copy from a Squire checkout, then verify it:

```bash
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire --check
```

Commit the refreshed copy with the Squire commit in the message, and add a CHANGELOG line. From the installed package the same refresh is `composer squire-copy:sync`, verified by `composer squire-copy:check` (part of `composer ci`). Apps generated earlier pick up the refreshed files with their next `sourdough feed`.

## Related

- [The manifest](./manifest.md)
- [Building on Laravel](./laravel.md)
- [Testing](./testing.md)
