---
title: "Proof | Sourdough docs"
description: "How Sourdough proves every loaf: the proof harness bakes each shape and each oven with the current Sourdough, installs it, runs its own suite and PROCTOR, boots it, checks the manifest and feeds the last release forward."
url: https://sourdough.apps.wayne.edu/docs/reference/proof.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#proof)

This page says what each check of Sourdough proves and how to run it. Sourdough's promise is that every shape it bakes works on the day it comes out of the oven, so the proof is not a unit test of the generator: it bakes every shape for real, with this checkout, and makes the loaf prove itself.

## Last run[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#last-run)

| Run | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `sh scripts/smoke-shape.sh raw` | 2026-11-07 | `191ee57` | pass: 25 of 25 steps; suite: 11 tests, 21 assertions; fed from 0.1.6: fresh | 18 s |
| `sh scripts/smoke-shape.sh raw-vue` | 2026-11-07 | `191ee57` | pass: 28 of 28 steps; suite: 16 tests, 34 assertions; fed from 0.1.6: fresh | 16 s |
| `sh scripts/smoke-shape.sh blade` | 2026-11-07 | `191ee57` | pass: 27 of 27 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: fresh | 19 s |
| `sh scripts/smoke-shape.sh blade-vue` | 2026-11-07 | `191ee57` | pass: 30 of 30 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: fresh | 16 s |
| `sh scripts/smoke-shape.sh inertia` | 2026-11-07 | `191ee57` | pass: 31 of 31 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: fresh | 37 s |
| `sh scripts/smoke-shape.sh blade --sail` | 2026-11-07 | `191ee57` | pass: 22 of 22 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 2 record | 10 s |
| `sh scripts/smoke-shape.sh blade --docker` | 2026-11-07 | `191ee57` | pass: 24 of 24 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 6 record | 9 s |
| `sh scripts/smoke-shape.sh blade-vue --sail` | 2026-11-07 | `191ee57` | pass: 22 of 22 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 2 record | 9 s |
| `sh scripts/smoke-shape.sh blade-vue --docker` | 2026-11-07 | `191ee57` | pass: 24 of 24 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 6 record | 10 s |
| `sh scripts/smoke-shape.sh inertia --sail` | 2026-11-07 | `191ee57` | pass: 22 of 22 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 2 record | 10 s |
| `sh scripts/smoke-shape.sh inertia --docker` | 2026-11-07 | `191ee57` | pass: 24 of 24 steps; suite: 2 tests, 2 assertions; fed from 0.1.6: 6 record | 11 s |
| `composer ci` | 2026-11-07 | `{{CI_COMMIT}}` | {{CI_RESULT}} | {{CI_SECONDS}} s |

All eleven runs came from one `.\scripts\proof.ps1` on Windows (Docker Desktop, amd64), 167 seconds in all, in the smoke image: PHP 8.5.12, Composer 2.10.3, Node 22.23.4. The plain shapes fed forward from 0.1.6 found nothing to do, because no template has changed since that release. Every oven run fed a 0.1.6 bake forward with `record` lines only, which is the fix this run was written for: 0.1.6 left the oven out of the manifest (see [What it found](https://sourdough.apps.wayne.edu/docs/reference/proof.html#what-it-found)). The Laravel shapes ship two tests each, the starter's own examples; the count is theirs, not the proof's.

## What it found[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#what-it-found)

The first run of the harness, on the same day, failed nine of eleven runs. Six of the failures were Sourdough's:

- **An app baked by 0.1.6 with `--sail` or `--docker` could never feed cleanly.** 0.1.6 wrote the manifest before the oven's scaffold ran, so it named no oven and hashed `composer.json` before the scaffold edited it. `sourdough feed` then baked the plain shape, called the app fresh and wrote nothing, and `sourdough manifest --check` reported `composer.json` changed for good. Fixed in the same branch: feed reads the oven from the app's files when a 0.1.6 manifest names none, and a new `record` action moves the manifest to a file the app already has right ([Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html#what-feed-does)).
- The other three failures were the harness's own: Laravel's router wants `public/` as its working directory. Fixed before the run above, along with the suite count, which Pest prints in color even without a terminal.

Still open, from the same runs:

- **The Laravel shapes ship two tests**: the home page answers 200, and the skeleton's unit example. Nothing in a baked app's own suite checks that `/dashboard` needs a sign-in or that `/up` answers; the proof checks both from outside.
- **PROCTOR says `INFO composer-config`** on every shape: the baked `composer.json` sets `require.php` but not `config.platform.php`, so the lock resolves against whatever PHP ran `composer install`.
- **The raw shapes run PROCTOR on its bundled policy**, the Laravel shapes on CREED's released one: the raw template does not require `waynestate/creed` and its `proctor` script passes no `--policy`.
- **PROCTOR warns `php-floor`** on every shape: PHP 8.3 is in security-only support until 2027-12-31. Expected, and the stack's floor until then.

## Run it[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#run-it)

The proof harness is a pair: `scripts/proof.sh` on Linux and macOS, `scripts/proof.ps1` on Windows. Both build the smoke image (`scripts/smoke.Dockerfile`: PHP 8.5, Composer 2.10.3, Node 22, pinned) and run every bake inside it, one after another, so the result is the same on every machine.

sh

```bash
sh scripts/proof.sh            # every run
sh scripts/proof.sh raw inertia+docker
```

PowerShell

```powershell
.\scripts\proof.ps1            # every run
.\scripts\proof.ps1 raw inertia+docker -OutDir C:\temp\proof
```

It needs git and Docker, and the network for Composer, npm, Packagist and the WayneUI release. It needs no credentials: every bake passes `--no-playbook` and `--no-advisor`, and the gate drops any Oracle, CAS or Composer credential the host leaks in.

The harness proofs the committed tree plus any edits to tracked files (`git stash create`), never untracked files, and the table marks the commit `+ local edits` when there were any. The source goes into the container as one tar file from `git archive` rather than a mounted folder, because Docker Desktop can list a mounted folder only partially. `git archive` honors the `export-ignore` lines in `.gitattributes`, so the proof bakes with the package exactly as Composer ships it: no tests, no site, no `composer.lock`, and Sourdough's own dependencies resolve fresh, as they do for anyone who installs it from Packagist. The CI job runs on the checkout instead, lock and all.

Results land in `proof-out/` (or `-OutDir`, or `PROOF_OUT`):

| File | What it holds |
| --- | --- |
| `last-run.md` | the table above, ready to paste |
| `summary.txt` | the image's versions, one line per run, the total |
| `logs/<run>.txt` | the run's `ok` and `FAIL` lines |
| `logs/<run>/<n>.log` | the full output of each step |

The exit code is `0` when every run passed, `1` when any failed, `2` when the harness could not start. To refresh this page, run the harness on a clean checkout of `main` and paste `last-run.md` over the table.

| Setting | Meaning |
| --- | --- |
| runs (arguments) | `raw`, `raw-vue`, `blade`, `blade-vue`, `inertia`, or a Laravel shape in an oven: `blade+sail`, `blade+docker`, `blade-vue+sail`, `blade-vue+docker`, `inertia+sail`, `inertia+docker`. None means all eleven. |
| `PROOF_FEED_FROM` (`-FeedFrom`) | the released Sourdough to feed forward from. Default: the newest release in `CHANGELOG.md`; `none` skips the feed. |
| `PROOF_OUT` (`-OutDir`) | where the results go. Default: `proof-out/`. |
| `--here` | `proof.sh` only: run in this machine instead of Docker. The CI job uses it inside the smoke image. |

## What each run proves[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#what-each-run-proves)

Every run goes through `scripts/smoke-shape.sh`, the same gate the per-shape smoke jobs run on every pipeline. Each step prints `ok` or `FAIL`; the first `FAIL` stops the run and shows the step's tail.

1. **It bakes.** `sourdough new` with this checkout's own CLI, so the starter under test is the one being shipped.
2. **The manifest is clean.** `sourdough manifest --check` exits 0 right after the bake: every managed file matches `.sourdough/manifest.json`.
3. **The bake is reproducible.** The fresh app is committed to a scratch git repository and `sourdough feed --dry-run` must find nothing to feed. A loaf that came out different every time would make every feed of it a conflict.
4. **Yesterday's loaf feeds forward.** The same shape is baked with the last release, installed from Packagist, and committed as an app would be. This checkout's `sourdough feed` must bring it up to date without a conflict, `sourdough manifest --check` must be clean afterwards, and a second `sourdough feed --dry-run` must find nothing left to do.
5. **It installs.** `composer install` (and `npm install` for the Vue shapes) from the baked `composer.json` and `package.json`.
6. **Its own suite passes.** `composer test` for the raw shapes (PHPUnit), `php artisan test` for the Laravel shapes (Pest 4). The table quotes the suite's own count.
7. **PROCTOR passes.** `composer proctor`: the stack's checks against CREED's policy, from inside the app.
8. **Its own gate passes.** `composer ci`, plus `npm run build` for the Vue shapes and `npm run typecheck` for Inertia.
9. **It serves.** A live server, then its front page:

| Shape | Server | `/` must answer 200 with | Also |
| --- | --- | --- | --- |
| Raw PHP | `php -S` with `APP_ENV=local`, after fetching the pinned WayneUI release into `public/wayne-ui.php` | `app is running.` in WayneUI's shell | `/nope` 404, `/up` 200 |
| Raw PHP + Vue | the same | the built app (`src="/build/assets/`) mounted on `id="app"` | `/api/me` 401 without a session and the fake user with one, `/nope` 404 with the app, `/up` 200 |
| Laravel + Blade | `php -S` with Laravel's own router, as `php artisan serve` runs it | `baked fresh by Sourdough` | `/dashboard` signs in with SESAME's fake driver, `/nope` 404, `/up` 200 |
| Laravel + Blade + Vue islands | the same | `baked fresh by Sourdough` and the built assets | `/dashboard` carries the `data-vue-island="counter"` island, `/nope` 404, `/up` 200 |
| Laravel + Inertia/Vue | the same | the `Home` page component and the built assets | `/dashboard` renders the `Dashboard` page after the fake sign-in, `/nope` 404, `/up` 200 |

### The ovens[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#the-ovens)

A Laravel shape with `--sail` or `--docker` is proven up to the oven door. The run bakes it, checks the manifest, the reproducible bake and the feed from the last release as above, checks that the scaffold wrote its files (`compose.yaml` and `laravel/sail` for Sail; the `Dockerfile`, `compose.yaml`, `.dockerignore` and the `dev-up` script for Docker), then installs the app and runs its own suite and PROCTOR. The oven's `.env.example` points at MySQL, which the gate does not run; the suite keeps to SQLite in memory, as `phpunit.xml` says.

## composer ci[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#composer-ci)

Sourdough's own gate, the same command on GitLab and GitHub:

```sh
composer ci   # validate, PHPUnit, Pint, PHPStan, the Squire copy, agent-ready files, the docs site, audit
```

The PHPUnit suite covers every generator, the manifest, `sourdough feed` on every shape with local edits, merges, conflicts and refusals (`tests/FeedCommandTest.php`, offline), and the Sail and Docker scaffolds file by file. `tests/ProofHarnessTest.php` keeps the two harness scripts, the gate, the CI job and the table on this page listing the same runs.

## On CI[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#on-ci)

Each shape's smoke job (`smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue`, `smoke-inertia`) runs the gate on every pipeline, without the feed from the last release and without the ovens.

The `proof` job runs the whole harness. It never runs on a push: start it by hand from any pipeline, or from a pipeline schedule that sets `SOURDOUGH_PROOF` to `1`. It keeps `proof-out/` (the table, the summary and every step log) as an artifact for 30 days. Like the smoke jobs it is allowed to fail for now. The runner it lands on is arm64 where the table above ran on amd64, so a green `proof` job is the arm64 half of the same proof.

Tip

Strongly recommended: a git.wayne.edu instance runner, so the proof and every app's pipeline run where the code lives.

## What it does not prove yet[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#what-it-does-not-prove-yet)

- **No oven is lit.** The `--sail` and `--docker` runs never start a compose stack: the gate has no Docker daemon. The scaffold tests (`tests/SailScaffoldTest.php`, `tests/DockerScaffoldTest.php`) pin their files.
- **No Playbook, no ADVISOR.** Every bake passes `--no-playbook` (its package is private and the proof runs credential-free) and `--no-advisor`. A Wayne State app needs Playbook; its wiring is covered by the unit suite.
- **No MySQL or Oracle.** The Laravel shapes migrate SQLite; nothing connects to a database server.
- **No browser.** The front page is fetched with curl. Nothing clicks the Vue island, runs axe on a baked app, or checks it on a phone.
- **A feed with local edits** is proven by `tests/FeedCommandTest.php`, not by the harness: the harness feeds an app nobody has touched.

## Related[#](https://sourdough.apps.wayne.edu/docs/reference/proof.html#related)

- [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html)
- [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html)
- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [Same kitchen, many ovens](https://sourdough.apps.wayne.edu/docs/guides/ovens.html)
