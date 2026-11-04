---
title: The manifest
description: What .sourdough/manifest.json records, how managed and seeded files differ, and how sourdough manifest --check reports drift.
---

# The manifest

Every app Sourdough generates carries a `.sourdough/manifest.json`, the starter's memory of what went into the loaf. This page explains what it records and how to check your app against it; commit the manifest so a later `sourdough feed` can bring template updates in without clobbering what you have changed.

## What it records

The manifest records the Sourdough version, the template, the answers you chose, and a sha256 hash of every file. Its keys, sorted alphabetically in the JSON:

| Key | Value |
|---|---|
| `answers` | each option you chose, sorted by key |
| `files` | every generated file with its `sha256` hash and a `class` of `managed` or `seeded` |
| `generated_at` | the generation time, UTC |
| `schema` | the manifest schema version, currently `1` |
| `sourdough_version` | the Sourdough package version, or `dev` when it cannot be determined (running from a git checkout without a release tag) |
| `template` | the template the app was generated from |

The JSON is pretty-printed with sorted keys and a trailing newline, and paths use forward slashes on every OS, so two runs with the same answers differ only in `generated_at`.

## Managed and seeded

| Class | Who owns it |
|---|---|
| `managed` | Sourdough owns the file and will update it |
| `seeded` | Sourdough wrote the file once; you own it now (`.env.example` and `README.md`) |

The manifest remembers what went into the loaf, so `feed` knows what to refresh and what to leave to rise on its own. A later `sourdough feed` reads it to bring template updates into your app without clobbering what you've changed.

## Check an app against its manifest

Until `feed` ships, `sourdough manifest --check` is the gate. Run it in your app to list the managed files that have drifted from the manifest:

```bash
sourdough manifest --check
```

You can pass the app directory as an argument; the default is the current directory. The exit codes match PROCTOR:

- `0` clean: `Clean: every managed file matches the manifest.`
- `1` a managed file changed or is missing: one line per file, `changed  <file>` or `missing  <file>`
- `2` tool error: no manifest in the directory, or no `--check`

Seeded files are never reported. They're yours.

> [!TIP]
> Commit `.sourdough/manifest.json` in your app. It's the record that keeps `feed` honest.

## The Squire deploy files

The deploy files in your app (`build.sh`, `scripts/release.sh`, the `.squire/` config, and their friends) come from Squire's exported copy, checked into this repository at `templates/squire/`. Squire owns them; Sourdough installs them as-is and records them in the manifest, so you never edit them by hand. To refresh them, run `composer squire-copy:sync`, which rewrites the copy from the installed `waynestate/squire` package, then verify it with `composer squire-copy:check` (part of `composer ci`).

## Related

- [Pick a shape](./shapes.md)
- [Describe a plan](./describe-a-plan.md)
