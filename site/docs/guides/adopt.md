---
title: "Adopt an existing app | Sourdough docs"
description: "How sourdough adopt writes the manifest for an app Sourdough did not make, so sourdough feed can keep it current from then on."
url: https://sourdough.apps.wayne.edu/docs/guides/adopt.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Adopt an existing app[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#adopt-an-existing-app)

`sourdough feed` only reaches an app that carries `.sourdough/manifest.json`, and `sourdough new` writes one only into the apps it bakes. Most Wayne State apps were mixed somewhere else, years ago. `sourdough adopt` is the way in for them: it writes the manifest the app would have had if Sourdough had baked it, and from then on `feed` looks after it like any other loaf. Not every loaf came from our starter, but every loaf can be fed.

Adopt writes one file, `.sourdough/manifest.json`. It never changes your code, never writes `.sourdough/base/`, and never reads a file the template does not ship.

## When to adopt[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#when-to-adopt)

- **The app is close to one of the five shapes.** Adopt compares the app with a fresh bake of the shape you name, file by file. The closer the app is to that shape, the more files Sourdough can keep current.
- **Older Laravel apps: upgrade with [Booster](https://booster.apps.wayne.edu/) first.** Adopt compares with today's `laravel/laravel` skeleton, so an app on Laravel 8 is mostly `yours`. That is correct, not a failure, but every hop Booster takes brings more files level with the template, and each one that matches gets updates from then on.
- **Older PHP is fine.** The PHP floor comes from CREED's policy at bake time, never from an option, so an app on an older PHP gets `composer.json` and `.gitlab-ci.yml` marked `yours`. Upgrade and they can come back (see [Take a yours file over](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#take-a-yours-file-over)).

WELD is not adopted yet: it was built by hand and checks its drift against a fresh Inertia bake with its own regen check. See [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#apps-sourdough-did-not-make-and-weld).

## Before you start[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#before-you-start)

Adopt only runs inside a git repository, on a clean tree, so the adoption is one commit you can review and `git revert` can take back. An app that is not in git yet:

```
git init
git add -A
git commit -m "Before Sourdough"
```

Adopt needs git on PATH (or `SOURDOUGH_GIT` pointing at it), on Windows, macOS and Linux alike. The Laravel shapes bake a fresh `laravel/laravel` skeleton to compare with, so adopting one needs Composer and the network (or a warm Composer cache), the same as `sourdough new`.

## Pick the shape and the name[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#pick-the-shape-and-the-name)

```
sourdough adopt [<dir>] --shape <shape> [--name <name>] [--sail | --docker] [--no-playbook] [--no-advisor] [--dry-run] [--json] [-v]
```

`<dir>` is the app's root folder; the default is the current directory. `--shape` is required, because you are naming what the app already is, not composing a new one:

| `--shape` | The app |
| --- | --- |
| `raw` | Raw PHP with WayneUI's standalone path |
| `raw-vue` | Raw PHP + Vue SPA over JSON |
| `laravel-blade` | Laravel + Blade |
| `laravel-blade-vue` | Laravel + Blade + Vue islands |
| `laravel-inertia` | Laravel + Inertia/Vue |

Leave it off and adopt refuses, with a guess from the app's files (`artisan`, `inertiajs/inertia-laravel` in `composer.json`, `vue` in `package.json`). Adopt never picks a shape itself:

```
$ sourdough adopt
--shape is required: raw, raw-vue, laravel-blade, laravel-blade-vue or laravel-inertia. This app looks like laravel-inertia (it has artisan and inertiajs/inertia-laravel).
```

**The name.** The template writes the app's name into some files (`composer.json`, `public/index.php`, the README). `--name` defaults to the basename of the folder. If the app goes by another name, every file that carries it differs and lands in `yours`. Adopt cannot know the right name, but it says when the name looks wrong: the file's line says so, and two or more such files add a note after the summary:

```
  yours    composer.json     differs; the template writes the app name here as "ticketing", and your file does not have it: if the app goes by another name, delete .sourdough/ and adopt again with --name
  yours    public/index.php  differs; the template writes the app name here as "ticketing", and your file does not have it: if the app goes by another name, delete .sourdough/ and adopt again with --name
...
note: 2 files differ where the template writes the app name; if the app goes by another name, delete .sourdough/ and adopt again with --name.
```

**The oven.** `--sail` and `--docker` are for the Laravel shapes, with the same meaning as for `sourdough new`. Pass one only when the app runs on Sourdough's own oven files. An app with its own Docker setup leaves both off, so its `Dockerfile` and `compose.yaml` stay outside the manifest and Sourdough never sees them.

**Playbook and ADVISOR.** `--no-playbook` and `--no-advisor` change what the template bakes (`composer.json`, `.env.example`, the README, `.gitignore`), and they are recorded in the manifest's answers, so every later feed bakes the same way. Leave them off unless the app should never have that wiring; WSU apps need Playbook.

## Look first[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#look-first)

```
sourdough adopt --shape raw --dry-run
```

A dry run prints the same report and writes nothing. It opens with `Would adopt` and ends `Run sourdough adopt without --dry-run to write the manifest.` It still refuses a wrong command (a missing or unknown shape, a bad oven or name, a folder that is missing, already adopted, or left from an old manifest), but git and the folder only earn a note, so you can look at an app before it is in git:

- `Not inside a git repository: a real adopt would refuse.`
- `Uncommitted changes: a real adopt would refuse until the tree is clean.`
- `The folder is not writable: a real adopt would fail.`
- `git is not available: a real adopt would fail.`

## Read the report[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#read-the-report)

Here is a small, older raw PHP app, `parking-permits`, with a hand-built `public/index.php`, a `composer.json` that requires PHP 7.4, its own `public/.htaccess` and a README:

```
$ sourdough adopt --shape raw
Adopting "parking-permits" as a raw app (Raw PHP with WayneUI's standalone path) with Sourdough 0.4.1.
Compared your app with a fresh raw bake: 41 template files.

  yours    composer.json              differs from the template: yours, never overwritten
  yours    public/.htaccess           differs from the template: yours, never overwritten
  yours    public/index.php           differs; the template writes the app name here as "parking-permits", and your file does not have it: if the app goes by another name, delete .sourdough/ and adopt again with --name
  seeded   README.md                  yours, as in every Sourdough app
  missing  .env.example               the next sourdough feed adds it
  missing  .env.squire.example        the next sourdough feed adds it
  missing  .gitattributes             the next sourdough feed adds it
  missing  .gitignore                 the next sourdough feed adds it
  missing  .gitlab-ci.yml             the next sourdough feed adds it
  missing  .squire/ (15 files)        the next sourdough feed adds them
  missing  build.sh                   the next sourdough feed adds it
  missing  deployments/ (1 file)      the next sourdough feed adds it
  missing  docker-compose.squire.yml  the next sourdough feed adds it
  missing  health.php                 the next sourdough feed adds it
  missing  oracle-check.php           the next sourdough feed adds it
  missing  phpstan.neon               the next sourdough feed adds it
  missing  phpunit.xml.dist           the next sourdough feed adds it
  missing  pint.json                  the next sourdough feed adds it
  missing  public/up.php              the next sourdough feed adds it
  missing  scripts/ (3 files)         the next sourdough feed adds them
  missing  src/ (2 files)             the next sourdough feed adds them
  missing  stubs/ (1 file)            the next sourdough feed adds it
  missing  tests/ (2 files)           the next sourdough feed adds them

Adopted: 0 match, 3 yours, 1 seeded, 37 missing. Wrote .sourdough/manifest.json.
Files the template does not ship are not recorded: Sourdough never reads or touches them.
Next: git add .sourdough && git commit -m "Adopt with Sourdough 0.4.1"
      then sourdough feed --dry-run lists the 37 files the first feed adds.
```

Only one file carries the name hint here, and it takes two for the note: a hand-built `index.php` simply never had the name in it. Every template path gets one status. The report lists `yours` first, then `seeded`, then `missing`; `match` lines show only with `-v`. A top-level folder whose every template file is missing folds into one line (`.squire/ (15 files)`); `-v` lists each file.

| Status | When | Recorded as | What feed does with it |
| --- | --- | --- | --- |
| `match` | the app's file is the template's, line endings aside | `managed` | keeps it current, like any file Sourdough baked |
| `yours` | the file differs, is a symlink (or sits under one), or is named in another case (`readme.md` for `README.md`) | `yours` | never writes, merges, removes or reports it; takes it over the day it matches the template |
| `seeded` | a file the template seeds (`.env.example`, `README.md`, `package-lock.json`) that the app has | `seeded` | never touches it |
| `missing` | the app has no file at that path | not recorded | adds it, through its "new in the template" path |

Every recorded hash is the template's, never the app's. A Windows checkout with CRLF endings still matches. Files the template does not ship (your own code, `vendor/`, `node_modules/`, uploads) are never read and never recorded.

`yours` and `missing` are normal outcomes: adopt exits `0` with any mix of them. Adopt is not a CI gate; `feed --dry-run` is.

A vendored Squire kit gets a note too. When `.squire.json` says an older kit than the one this Sourdough bakes, the note says to run `vendor/bin/squire update` and commit; the next feed takes over each kit file that then matches.

## Commit the adoption[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#commit-the-adoption)

```
git add .sourdough
git commit -m "Adopt with Sourdough 0.4.1"
```

The adoption is its own commit, so `git revert` takes it back cleanly. Commit the manifest and do not hand-edit it: it is the starter's memory.

## The first feed[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#the-first-feed)

```
sourdough feed --dry-run
```

The first feed adds every `missing` file. A raw app that had lost its `.gitlab-ci.yml` and `phpstan.neon`, with its own `composer.json` and `public/index.php`:

```
$ sourdough feed --dry-run
Would feed the raw app: Sourdough 0.4.1 to 0.4.1 (dry run: nothing written)
  add       .gitlab-ci.yml (new in the template)
  add       phpstan.neon (new in the template)
Plan: 2 add.
Run sourdough feed without --dry-run to apply it.
```

Then feed for real, on the clean tree:

```
sourdough feed
git status
git diff
```

This is how the stack's wiring (Squire's kit, the pipeline with PROCTOR and the `sourdough-feed` job, PHPStan, Pint) reaches an older app. Review what arrived, run your checks, and commit. An app that had no `.gitlab-ci.yml` gets the shape's full pipeline, and some of its gates may fail on an older app until you fix them.

**Delete what you do not want.** Delete the file, commit, and feed remembers: it never adds the file again, and when the template changes it the plan only shows `keep`. `sourdough manifest --check` lists it as `missing <file>`, a report the CI job never fails on. To undo a whole feed instead: `git restore . && git clean -fd`.

## Your own .gitlab-ci.yml[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#your-own-gitlab-ci-yml)

When `.gitlab-ci.yml` is `yours`, the app's pipeline has no `sourdough-feed` job, so nothing warns when the app falls behind. Copy the job by hand from a fresh bake of the same shape:

```
sourdough new ../fresh-bake --raw
```

Copy the `sourdough-feed:` job from `../fresh-bake/.gitlab-ci.yml` into yours, with what it leans on: the `.composer-cache` template it extends and the `IMAGE_PREFIX` variable its image uses. Then delete the fresh bake. [Feed in CI](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#feed-in-ci) explains what the job reports.

## Take a yours file over[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#take-a-yours-file-over)

A `yours` file is never written, merged or reported, until the day your copy equals the template's. To hand one to Sourdough, make it match the template (copy it from a fresh bake of the same shape and name, or bring your copy level), commit, and feed:

```
$ sourdough feed --dry-run
Would feed the raw app: Sourdough 0.4.1 to 0.4.1 (dry run: nothing written)
  record    composer.json (your file now matches the template's: Sourdough manages it from here)
Plan: 1 record.
Run sourdough feed without --dry-run to apply it.
```

A `record` line changes no file, only the manifest: the file is `managed` from then on and gets every template update. The `sourdough-feed` job warns until that feed is committed. Squire's kit files come back the same way after `vendor/bin/squire update`.

## Start over[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#start-over)

Wrong shape, wrong name? Delete `.sourdough/`, commit, and adopt again. Adopt never writes over a manifest, because that would lose the record feed stands on:

```
$ sourdough adopt /srv/apps/parking-permits --shape raw
"/srv/apps/parking-permits" already has a Sourdough manifest: Sourdough made or adopted this app. Bring it up to date with sourdough feed. To adopt it again with another --shape or --name, delete .sourdough/, commit, and run adopt again.
```

A `.sourdough/base/` folder with no manifest beside it is left from a record that is gone, and adopt refuses that too, until you delete `.sourdough/` and commit.

## Exit codes[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#exit-codes)

| Exit | Meaning | When |
| --- | --- | --- |
| `0` | adopted, or a dry run printed its plan | any mix of `match`, `yours`, `seeded` and `missing` |
| `1` | refused: fix the command or the repo and run again | a missing or unknown shape, a bad oven, a folder that is missing or a file, a manifest already there, a leftover `.sourdough/base/`, a bad name, not a git repository, a dirty tree |
| `2` | tool error | no git, a folder adopt cannot write, a failed bake, a failed write |

The whole command (options, refusals, JSON and environment) is in [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-adopt). Failing lines and their fixes are in [Troubleshooting](https://sourdough.apps.wayne.edu/docs/troubleshooting.html#adopting-sourdough-adopt).

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/adopt.html#related)

- [The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html)
- [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html)
- [CI](https://sourdough.apps.wayne.edu/docs/guides/ci.html)
- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html#sourdough-adopt)
