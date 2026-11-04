---
title: "Keeping apps current | Sourdough docs"
description: "How the manifest lets a later sourdough feed bring template updates into your app without clobbering what you have changed."
url: https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Keeping apps current[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#keeping-apps-current)

A project that was fresh on the day it was generated is stale a month later, so this page is about keeping generated apps fed: what the manifest records, how to check for drift today, and how the planned `sourdough feed` will merge template updates in without clobbering your changes. Like any starter, it needs regular feeding, or it goes flat. Commit the manifest in your app and run the check in CI, then read on.

## Record where the app came from[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#record-where-the-app-came-from)

Every app carries a `.sourdough/manifest.json` recording the Sourdough version, the template, the answers you chose, and a sha256 hash of every file, each marked `managed` or `seeded`. Other tools do the same: Copier writes `.copier-answers.yml`, and cruft writes `.cruft.json`. Without this record there is nothing to compare against, so no update is possible.

Warning

Do not hand-edit the manifest. It is the starter's memory: it remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own.

[The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html) has the full key list and the managed/seeded split.

## Check for drift[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#check-for-drift)

Until `feed` ships, `sourdough manifest --check` is the gate. Run it in your app to list the managed files that have drifted from the manifest:

```bash
sourdough manifest --check
```

Exit codes: `0` clean, `1` a managed file changed or is missing, `2` tool error (no manifest, or no `--check`). Seeded files are never reported. They're yours. Wire the check into CI so an app that falls behind the template fails the pipeline instead of surfacing as drift later; that is what makes a starter "fed". Copier's `copier check-update` is the same idea: `--quiet` makes it exit 2 when an update exists, and `--output-format json` gives machines the answer.

## The planned feed: a merge, not an overwrite[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#the-planned-feed-a-merge-not-an-overwrite)

The planned `sourdough feed` regenerates the project from the new template version, compares, and applies the difference on top of your changes as a three-way merge. It needs only git on your machine: it records the template commit an app came from and applies template changes with `git apply --3way`. It requires a clean git state first (Copier recommends a clean `git status` but does not enforce it, so the feed enforces it), marks conflicts with inline markers or `.rej` files, and a hook or CI check blocks a commit containing leftover markers. A merge keeps your work; an overwrite would not.

## Versioned template releases[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#versioned-template-releases)

Tag template releases with semantic versions, and put a short "what changed, what to do" entry in the changelog for each. Copier picks the newest tag by default. An update tool is only as good as the history it can read.

## Compatibility CI[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#compatibility-ci)

On every change to Sourdough, and on every release of WayneUI, SESAME, HELMET and PROCTOR, generate each permutation, install it and run its own tests and the PROCTOR check. Run it on a schedule (nightly), not only on changes, covering PHP 8.4 and 8.5 and the current and previous Laravel major. The starter is the place where all the stack libraries meet, so it is where a break shows first. A starter with no commits still rots, because the things it depends on keep moving.

## Keep templates thin[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#keep-templates-thin)

Put shared behavior in libraries, not in the template. Code that lives in SESAME, HELMET and PROCTOR updates with `composer update`, so only the glue lives in the template and the merge stays small. Other universities ship starters the same way: a Composer package plus an install command. A new site installs it with `composer require` and `php artisan starterkit:install`, and an existing site can require the library without publishing the project files. This is the core-plus-adapters rule applied to the starter itself.

## Owners, windows and sunsets[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#owners-windows-and-sunsets)

Starters die quietly. The React team deprecated Create React App on 2025-02-14, saying it had no active maintainers, and installing it now prints a warning that points at current frameworks. Laravel replaced Breeze and Jetstream with new kits when Laravel 12 shipped. Each permutation should name an owner, tie its support window to the framework and PHP end dates, run the scheduled build, and have the check print a deprecation notice with the migration note.

## Refreshing the Squire templates[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#refreshing-the-squire-templates)

The deploy files in your app (`build.sh`, `scripts/release.sh`, the `.squire/` config, and their friends) come from Squire's exported copy, checked into the Sourdough repository at `templates/squire/`. Squire owns them; Sourdough installs them as-is and records them in the manifest, so you never edit them by hand. When Squire's templates change, regenerate the copy from a Squire checkout, then verify it:

```bash
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire
php ../squire/bin/export-templates.php --to=../sourdough/templates/squire --check
```

Commit the refreshed copy with the Squire commit in the message, and add a CHANGELOG line. From the installed package the same refresh is `composer squire-copy:sync`, verified by `composer squire-copy:check` (part of `composer ci`). Until `feed` exists, apps generated earlier pick up template changes by hand.

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#related)

- [The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html)
- [Building on Laravel](https://sourdough.apps.wayne.edu/docs/guides/laravel.html)
- [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html)
