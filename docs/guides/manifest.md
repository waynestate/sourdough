---
title: The manifest
description: What .sourdough/manifest.json records, how managed, seeded and yours files differ, and how sourdough manifest --check reports drift.
---

# The manifest

Every app Sourdough generates (or adopts) carries a `.sourdough/manifest.json`, the starter's memory of what went into the loaf. This page explains what it records and how to check your app against it; commit the manifest so `sourdough feed` can bring template updates in without clobbering what you have changed.

## What it records

The manifest records the Sourdough version, the template, the answers you chose, and a sha256 hash of every file. Its keys, sorted alphabetically in the JSON:

| Key | Value |
|---|---|
| `answers` | each option you chose, sorted by key: the name, the shape flags, `sail` or `docker`, and the Playbook and ADVISOR choices; `adopted: true` in an app `sourdough adopt` took in, which every later feed carries along |
| `files` | every generated file with its `sha256` hash and a `class` of `managed`, `seeded` or `yours`, including the `--sail` and `--docker` files |
| `generated_at` | the generation time (or, after a feed, the time of the new bake), UTC |
| `schema` | the manifest schema version, currently `1` |
| `sourdough_version` | the Sourdough package version, or `dev` when it cannot be determined (running from a git checkout without a release tag) |
| `template` | the template the app was generated from |

The JSON is pretty-printed with sorted keys and a trailing newline, and paths use forward slashes on every OS, so two runs with the same answers differ only in `generated_at`.

## Managed, seeded and yours

| Class | Who owns it |
|---|---|
| `managed` | Sourdough owns the file and will update it |
| `seeded` | Sourdough wrote the file once; you own it now (`.env.example`, `README.md`, and `package-lock.json` on the shapes with a front end) |
| `yours` | differed from the template when the app was adopted: you own it; feed takes it over once it matches the template |

Only `sourdough adopt` writes `yours`. Its hash is the template's version of the file, never yours, so the day your copy equals it, `sourdough feed` records the file (`record`) and it is `managed` from then on. Until then feed never writes, merges, removes or reports it. See [Adopt an existing app](./adopt.md).

The manifest remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own. `sourdough feed` reads it to bring template updates into your app without clobbering what you've changed: see [Keeping apps current](./keeping-current.md).

## After a feed

`sourdough feed` moves the manifest to the new base: the hashes of the loaf the installed Sourdough bakes today, and its version. Next to the manifest it keeps `.sourdough/base/`, one `<file>.base` per managed file you have changed, holding the template's version of that file. That is the base the next feed merges from, even when git history no longer has it. The `.base` suffix keeps a kept `.gitignore` from ignoring anything and keeps linters out. Commit the folder with the manifest, and do not edit it either.

## Check an app against its manifest

`sourdough manifest --check` lists the managed files that have drifted from the manifest, the files a feed will merge rather than replace:

```bash
sourdough manifest --check
```

You can pass the app directory as an argument; the default is the current directory. The exit codes match PROCTOR:

- `0` clean: `Clean: every managed file matches the manifest.`
- `1` a managed file changed or is missing: one line per file, `changed  <file>` or `missing  <file>`
- `2` tool error: no manifest in the directory, or no `--check`

Seeded and yours files are never reported. They're yours. Line endings are forgiven, as `feed` forgives them: a file a checkout turned to CRLF still matches the LF bytes Sourdough wrote. Every shape's pipeline runs the check in its `sourdough-feed` job and lists what drifted without failing on it: see [Feed in CI](./keeping-current.md#feed-in-ci).

> [!TIP]
> Commit `.sourdough/manifest.json` in your app. It's the record that keeps `feed` honest.

## The saved plan is not in the manifest

`.sourdough/plan.yaml`, the plan the app was baked from, sits next to the manifest but is not listed in it, so it is neither managed nor seeded. The manifest lists what the template wrote; the plan is what you asked for. `sourdough manifest --check` never reports it and `sourdough feed` never touches it, so edit it as the app grows and commit it with the manifest. See [The saved plan](../reference/plan-format.md#the-saved-plan).

## The Squire deploy files

The deploy files in your app (`build.sh`, `scripts/release.sh`, the `.squire/` config, and their friends) come from Squire's exported copy, checked into this repository at `templates/squire/`. Squire owns them; Sourdough installs them as-is and records them in the manifest, so you never edit them by hand. To refresh them, run `composer squire-copy:sync`, which rewrites the copy from the installed `waynestate/squire` package, then verify it with `composer squire-copy:check` (part of `composer ci`).

## Related

- [Pick a shape](./shapes.md)
- [Describe a plan](./describe-a-plan.md)
- [Adopt an existing app](./adopt.md)
