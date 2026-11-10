---
title: "Sourdough: Feed it and it grows."
description: "Sourdough bakes all five Wayne State app shapes: raw PHP, raw PHP + Vue, Laravel + Blade, Laravel + Blade + Vue islands and Laravel + Inertia/Vue, each with SESAME sign-in, HELMET headers, PROCTOR, Preflight CI and Squire's deploy files already in it."
url: https://sourdough.apps.wayne.edu/
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

A starter for Wayne State apps

# Sourdough

Feed it and it grows.

![Sourdough logo: a starter jar whose starter has risen into a dome, well above the gold band that marks where it began](https://sourdough.apps.wayne.edu/brand/sourdough-avatar-512.png)

Hungry. It has not been fed yet today.

`sourdough new` makes a Wayne State app with the stack already in it: SESAME for sign-in, HELMET for the security headers, PROCTOR and Preflight for the checks, and Squire's files for deploying to Citadel. With no flags that's a raw PHP app, and you add WayneUI's standalone file with one download. All five shapes bake today, from raw PHP to Inertia.

The whole recipe

```
$ composer global require waynestate/sourdough
$ sourdough new my-app
```

**Want Laravel with Inertia and Vue?** Sourdough bakes it with `--inertia`. [WELD](https://weld.apps.wayne.edu/) is that shape already built, with the stack's product docs and site on top.

[Start baking](https://sourdough.apps.wayne.edu/#start-title) [See the five loaves](https://sourdough.apps.wayne.edu/#loaves-title)

Proofing log

## The proof

What goes into the tub when you run `sourdough new`.

1. 08:00 **Mix.** `sourdough new` picks the shape; with no flags that's raw PHP.
2. 08:20 **Flour: WayneUI.** The interface, from WayneUI's standalone PHP file, which you download once.
3. 08:40 **Water: SESAME.** Sign-in. A fake driver locally, CAS in production.
4. 09:00 **Salt: HELMET.** Security headers, a hardened session, and `/up` for the load balancer.
5. 10:30 **Levain: PROCTOR.** The standards check. Preflight's CI components run it on every push, along with the tests, a Composer audit, an accessibility check of your own pages, a bill of materials and a secret scan.
6. 13:00 **Doubled.** Well past the gold band. A label goes on: `.sourdough/manifest.json`.
7. 14:00 **Divide and shape.** Cut into the five shapes the stack supports, every one of them ready to bake.

On the cooling rack

## Five loaves, one starter

These are the five app shapes the stack supports, and all five bake today. The proof harness bakes, boots and feeds forward every one of them: read [the latest proof](https://sourdough.apps.wayne.edu/docs/reference/proof.html). Score a loaf to see what's inside.

Country boule

### Raw PHP

Raw PHP with WayneUI's standalone path. No framework, no build.

Out of the oven since 0.1.0

`sourdough new my-app --raw`

The crumb

- `public/index.php` HELMET boots first, then SESAME signs you in
- `composer.json` helmet, sesame and Playbook, with pinstripe, proctor and advisor for dev
- `pint.json` and `phpstan.neon` Pinstripe's standard: Pint on the per preset, PHPStan at level 8
- `.gitlab-ci.yml` Preflight components: PROCTOR, tests on PHP 8.3 to 8.5, audit, accessibility, SBOM and a secret scan, plus a `sourdough-feed` job
- `build.sh` and `scripts/release.sh` Squire's Citadel deploy files
- `.env.example` and `README.md` seeded: yours from day one
- `.sourdough/manifest.json` the label on the jar

Bâtard

### Raw PHP + Vue

A Vue single-page app over JSON, on raw PHP. No framework, one build.

Out of the oven since 0.1.6

`sourdough new my-app --raw --vue`

The crumb

- `public/index.php` the raw boot, then JSON under `/api/` and one page for Vue
- `resources/js/App.vue` drawn with WayneUI's Vue components, pinned at 2.0.8
- `src/Vite.php` reads Vite's manifest, nonces every tag for HELMET
- Next steps: `composer install`, `npm install`, `npm run build`, `composer serve`

Baguette

### Laravel + Blade

The classic Wayne State shape: Laravel with Blade views.

Out of the oven since 0.1.5

`sourdough new my-app --laravel`

The crumb

A fresh Laravel skeleton from Composer with the stack wired in: SESAME through the Laravel adapter, HELMET's config, `/up` for liveness and `/ready` that asks the database, the cache and the scheduler, Pest 4, Laravel Boost for dev, Pinstripe's Laravel preset with Pint's findings in the merge request, PROCTOR and Preflight in CI, Squire's Laravel deploy files and the manifest. Oracle's `oci8` driver is required and waits, dormant, for its settings. Next steps: `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan serve`.

Seeded loaf

### Laravel + Blade + Vue islands

Blade pages with Vue islands, like seeds in the crust.

Out of the oven since 0.1.5

`sourdough new my-app --laravel --vue`

The crumb

The Laravel + Blade bake under a front-end layer: Vite with the Vue plugin, and a `resources/js/app.js` registry that mounts a Vue 3 app on every `[data-vue-island]` element. Next steps add `npm install` and `npm run build` before `php artisan serve`.

Tin loaf

### Laravel + Inertia/Vue

Laravel with Inertia and Vue, as one app.

Out of the oven since 0.1.5

`sourdough new my-app --inertia`

The crumb

Laravel with Inertia and Vue as one app: Inertia pages rendered by Vue, Ziggy routes, the stack packages, Squire's Laravel deploy files and the manifest. [WELD](https://weld.apps.wayne.edu/) is this shape already built, with the stack's product docs and site on top; you can also bake it yourself with `--inertia`.

Not sure yet? [Place an order at the counter](https://sourdough.apps.wayne.edu/#order) and the ticket writes the command for you, or add `--dry-run` to any of these: it prints the plan and writes nothing.

At the counter

## Place your order

Pick a loaf, an oven and a name. The ticket writes the command, the bake and what to run after it, from the same code `sourdough new --dry-run --json` runs, so what you copy is what you get.

Sourdough bakery

### Order ticket

No. 1

For **my-app**, Raw PHP

`sourdough new my-app --raw`

#### The bake

1. Copy the raw template into my-app
2. Replace {{app_name}} and {{app_slug}} in file names and contents
3. Wire ADVISOR into the app

#### Then run

```
cd my-app
composer install
php vendor/bin/advisor install --client=claude --write
composer serve
```

The same plan `--dry-run --json` prints. Add `--dry-run` to see it on your own machine first.

Recipe card No. 1

## What's baked in

Baker's percentages, roughly. The parts are Composer packages, so `composer update` updates them. Only the code that connects them lives in the template.

**The parts of the stack in every Sourdough app, as ingredients**

| Ingredient | Part | What it does in your app |
| --- | --- | --- |
| 100% Flour | [WayneUI](https://wayneui.apps.wayne.edu/) | The interface: WayneUI's standalone PHP file, fetched once into `public/`. |
| 75% Water | [SESAME](https://sesame.apps.wayne.edu/) | Sign-in. The fake driver in development; the cas driver in production, with `SESAME_CAS_URL` and `SESAME_SERVICE_URL`. |
| 2% Salt | [HELMET](https://helmet.apps.wayne.edu/) | The runtime baseline: security headers, session cookie flags, trusted proxies from `HELMET_PROXIES`, and `/up` answering `200 ok`. Every shape answers `/ready` too: in the Laravel shapes it answers 503 while the database, the cache or the scheduler is down, and the raw shapes add their own checks as the app grows. |
| 20% Levain | [PROCTOR](https://proctor.apps.wayne.edu/) | The standards check. CI runs `vendor/bin/proctor check` on every push. The release script asks `proctor gate` for a go or no-go too, as a report unless you set `SQUIRE_PROCTOR=enforce`. |
| 1 Oven | [Preflight](https://preflight.apps.wayne.edu/) | CI from `.gitlab-ci.yml`, pinned to Preflight 0.6.0: PROCTOR, tests on PHP 8.3, 8.4 and 8.5, a Composer audit, an accessibility check of your own pages at WCAG 2.2 AA that blocks, a bill of materials and a Gitleaks secret scan. |
| 1 Recipe | [CREED](https://creed.apps.wayne.edu/) | The rules the loaf is judged by. Every bake reads its PHP floor from CREED's policy, and PROCTOR grades the app against the same file. |
| 1 Basket | Release script | `build.sh` and `scripts/release.sh` come from Squire and run on Citadel at build and release time. Off Citadel, the app reads the same environment variables on OpenShift, Coolify or raw Docker. |
| 1 Pinch | [ADVISOR](https://advisor.apps.wayne.edu/) | For your AI assistant: the stack's MCP server, in `require-dev`, answers from the stack's own docs. `--no-advisor` leaves it out. |

Bake at `composer serve` until golden.

Pinned above the bench

## Feeding schedule

A starter you never feed goes flat. Every app Sourdough makes carries `.sourdough/manifest.json`: the Sourdough version, the template, the answers you chose, and a sha256 of every file. That's how `sourdough feed` knows which files it can update, and which ones you've kneaded. An app Sourdough did not make can get one too, on adoption day.

1. Bake day
   `sourdough new my-app`
   Makes the app and writes the manifest. Commit the manifest with the rest of the app.
2. Adoption day for a loaf we did not bake
   `sourdough adopt --shape raw`
   Sourdough can now look after an app it did not make. In the app, on a clean git tree, name the shape it already is: `raw`, `raw-vue`, `laravel-blade`, `laravel-blade-vue` or `laravel-inertia`. Adopt bakes that shape fresh, compares your files with it, and writes the one thing the app was missing: the manifest. Files that match get fed from then on, files that differ stay yours and are never overwritten, and files you lack arrive with the first feed. Your code is never touched. [Adopt an existing app](https://sourdough.apps.wayne.edu/docs/guides/adopt.html) walks through it.
   - **0**: adopted (or a dry run)
   - **1**: refused: not in git, a dirty tree, already adopted
   - **2**: no git, or the bake failed
   Adoption papers an old course finder, taken in
   ```
   $ sourdough adopt --shape raw
   Adopting "course-finder" as a raw app (Raw PHP with WayneUI's standalone path) with Sourdough 0.4.2.
   Compared your app with a fresh raw bake: 41 template files.
   
     yours    composer.json     differs from the template: yours, never overwritten
     yours    public/index.php  differs from the template: yours, never overwritten
     seeded   .env.example      yours, as in every Sourdough app
     seeded   README.md         yours, as in every Sourdough app
     missing  .gitlab-ci.yml    the next sourdough feed adds it
     missing  phpstan.neon      the next sourdough feed adds it
   
   Adopted: 35 match, 2 yours, 2 seeded, 2 missing. Wrote .sourdough/manifest.json.
   Files the template does not ship are not recorded: Sourdough never reads or touches them.
   Next: git add .sourdough && git commit -m "Adopt with Sourdough 0.4.2"
         then sourdough feed --dry-run lists the 2 files the first feed adds.
   ```
3. Any day
   `sourdough manifest --check`
   Is it still alive? Lists every managed file that has drifted from the manifest. Run it in the app any time; every shape's pipeline runs it too, in the `sourdough-feed` job, and only lists what drifted.
   - **0**: clean
   - **1**: a managed file changed or is missing
   - **2**: no manifest, or no `--check`
4. Feeding
   `sourdough feed`
   Brings template updates into your app without clobbering what you changed. It bakes your shape again and lets git do a three-way merge, file by file; where you both changed the same lines, it leaves git's conflict markers for you instead of guessing. Run `--dry-run` first; every shape's pipeline runs it too, and warns when a feed is due.
   - **0**: fed (or already fresh)
   - **1**: fed, with conflicts to resolve
   - **2**: no manifest, no git, or a dirty tree

### Managed we keep feeding these

- `public/index.php`
- `composer.json`
- `.gitlab-ci.yml`
- Squire's deploy files: `build.sh`, `scripts/`, `deployments/`, `.squire/`
- `.gitattributes`, `.gitignore`

### Seeded written once, yours now

- `.env.example`
- `README.md`
- `package-lock.json`

The lock comes only with a front end. Seeded files are never reported. They're yours to knead.

### Yours adopted, kept as you made them

- `composer.json`
- `public/index.php`

Whatever differed on adoption day, like these two in the course finder. Never written, merged or reported. Make one match the template and the next feed takes it over.

Quick start

## Your first loaf

Sourdough is one PHP command, so the steps match on every system; only the shell changes. You need PHP 8.3 or newer and Composer.

**Windows**

PowerShell

```
# 1. Get the starter
composer global require waynestate/sourdough
# 2. Bake the app
sourdough new my-app
cd my-app
# 3. Fetch WayneUI's standalone file once
curl.exe -o public/wayne-ui.php https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php
# 4. Install and serve
composer install
php vendor/bin/advisor install --client=claude --write
composer serve
```

**macOS**

Terminal (zsh)

```
# 1. Get the starter
composer global require waynestate/sourdough
# 2. Bake the app
sourdough new my-app
cd my-app
# 3. Fetch WayneUI's standalone file once
curl -o public/wayne-ui.php https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php
# 4. Install and serve
composer install
php vendor/bin/advisor install --client=claude --write
composer serve
```

**Linux**

bash

```
# 1. Get the starter
composer global require waynestate/sourdough
# 2. Bake the app
sourdough new my-app
cd my-app
# 3. Fetch WayneUI's standalone file once
curl -o public/wayne-ui.php https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php
# 4. Install and serve
composer install
php vendor/bin/advisor install --client=claude --write
composer serve
```

ADVISOR answers questions about the stack from inside your AI assistant; skip it with `sourdough new --no-advisor`.

To move to a newer WayneUI release, change the version in the URL above and run the fetch again.

**`sourdough` not found?** Add the folder that `composer global config bin-dir --absolute` prints to your PATH. Then open `http://localhost:8000`: locally the app signs in with SESAME's fake driver.

Hand it to your agent

## Recipes for your agent

Four prompts for whatever coding agent you use. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT. Each tells the agent what Sourdough put in the app and what it must leave alone, and each tells it to ask you before it guesses.

### Get started with Sourdough

Installs Sourdough, makes a raw PHP app with WayneUI, serves it locally and runs the manifest check.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer bake a new app with Sourdough, the app starter of WELD, the Wayne State application stack. Sourdough bakes all five shapes: raw PHP on WayneUI's standalone path (--raw or no flag), raw PHP + Vue SPA over JSON (--raw --vue), Laravel + Blade (--laravel), Laravel + Blade + Vue islands (--laravel --vue) and Laravel + Inertia/Vue (--inertia). Each comes with SESAME (sign-in), HELMET (security headers and the /up check), Pinstripe (coding standard), PROCTOR (checks), the Preflight CI components and Squire's deploy files already in it.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, the index of Sourdough's pages, each one as Markdown; llms-full.txt beside it holds the README too. Fetch what you need; do not work from memory.
- ADVISOR, the stack's MCP server, if it is connected: get_project_status and read_project_docs for sourdough answer from the stack's own docs, and policy_lookup gives today's PHP support from CREED. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Check that PHP 8.3 or newer and Composer are on the PATH, and say which versions you found.
2. Install the starter with `composer global require waynestate/sourdough`. If the sourdough command is then not found, add the folder that `composer global config bin-dir --absolute` prints to the PATH.
3. Bake the app with `sourdough new my-app --raw`, using the folder name I give you in place of my-app, and change into it.
4. Fetch WayneUI's standalone file once. Windows (PowerShell): `curl.exe -o public/wayne-ui.php https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php`. macOS and Linux: `curl -o public/wayne-ui.php https://wayneui.apps.wayne.edu/v2/releases/2.0.8/wayne-ui.php`.
5. Run `composer install`, then wire in ADVISOR with `php vendor/bin/advisor install --client=claude --write`, then `composer serve`, and tell me to open http://localhost:8000. Locally SESAME signs in with its fake driver.
6. Run `sourdough manifest --check` in the app and report what it says.

Constraints:
- Sourdough bakes all five shapes: raw PHP (--raw or no flag), raw PHP + Vue SPA over JSON (--raw --vue), Laravel + Blade (--laravel), Laravel + Blade + Vue islands (--laravel --vue) and Laravel + Inertia/Vue (--inertia). A --raw --vue app needs npm install and npm run build before composer serve shows the page. WELD is the Inertia/Vue shape already built, with the stack's product docs and site on top: https://weld.apps.wayne.edu/llms.txt
- .sourdough/manifest.json records every file Sourdough wrote. Managed files are Sourdough's; .env.example and README.md are seeded and mine. Commit the manifest.
- The deploy files (build.sh, scripts/release.sh, the .squire/ config) come from Squire. Never edit them by hand.
- Never commit .env, and never paste a secret into a prompt or a file.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess: a folder name, a PHP version you cannot read, or anything that touches a deploy.

Output: what you ran and what it said, the app's files one level deep, then your questions.
```

### Check what has drifted in a Sourdough app

Lists the managed files that changed since Sourdough wrote them, shows what a feed would bring in, and suggests what to put back. It changes nothing.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent checking an app that Sourdough baked, at Wayne State University. Sourdough is the app starter of WELD, the Wayne State application stack. Every app it bakes carries .sourdough/manifest.json: the Sourdough version, the template, the answers chosen, and a sha256 hash of every file it wrote, each marked managed or seeded, or yours in an app Sourdough adopted.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, then llms-full.txt beside it: the README's manifest section says what managed, seeded and yours mean.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sourdough and squire, and run_proctor_check for this app. If it is not connected, say so and work from the pages.
- .sourdough/manifest.json, composer.json and public/index.php in this app.

Task:
1. Run `sourdough manifest --check` at the app's root. Exit 0 is clean, 1 means a managed file changed or is missing, 2 is a tool error.
2. For each file it reports, say what changed, as far as you can tell, and whether it looks deliberate.
3. If ADVISOR is connected, call run_proctor_check for this app and list the findings.
4. Run `sourdough feed --dry-run` and list what a feed would update, merge, add, remove or leave in conflict. Exit 0 means fresh, 1 means feeding would change files, 2 is a tool error.
5. Change nothing. For each drifted file, propose whether to put it back or keep the change, and why. A kept change is fine: feed merges it with the template's.

Constraints:
- Seeded files (.env.example and README.md), and yours files in an adopted app, are mine and never count as drift.
- The deploy files come from Squire, through Sourdough. Never hand-edit them to make a check pass.
- Never run sourdough feed without --dry-run unless I say so: a real feed writes files.
- Ask me before you guess, and before you touch anything that deploys.

Output: a table (file, managed, seeded or yours, state, deliberate or not), the PROCTOR findings, then your proposals and your questions.
```

### Bake a Laravel app with Sourdough

Installs Sourdough, bakes one of the three Laravel shapes, serves it locally and runs the manifest check.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer bake a new Laravel app with Sourdough, the app starter of WELD, the Wayne State application stack. Sourdough bakes three Laravel shapes: Laravel + Blade (--laravel), Laravel + Blade + Vue islands (--laravel --vue) and Laravel + Inertia/Vue (--inertia). Each comes with SESAME (sign-in), HELMET (security headers and the /up check), Pinstripe (coding standard), PROCTOR (checks), the Preflight CI components and Squire's deploy files already in it.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, the index of Sourdough's pages, each one as Markdown; llms-full.txt beside it holds the README too. Fetch what you need; do not work from memory.
- ADVISOR, the stack's MCP server, if it is connected: get_project_status and read_project_docs for sourdough answer from the stack's own docs, and policy_lookup gives today's PHP support from CREED. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Check that PHP 8.3 or newer and Composer are on the PATH, and say which versions you found.
2. Install the starter with `composer global require waynestate/sourdough`. If the sourdough command is then not found, add the folder that `composer global config bin-dir --absolute` prints to the PATH.
3. Ask me which shape I want: Blade (`--laravel`), Blade with Vue islands (`--laravel --vue`) or Inertia/Vue (`--inertia`), and whether I want `--sail` or `--docker` for the local runtime. Then bake the app with `sourdough new my-app` and those flags, using the folder name I give you in place of my-app, and change into it.
4. Run `composer install`, then `cp .env.example .env`, `php artisan key:generate` and `php artisan migrate`. For the islands and Inertia shapes, run `npm install` and `npm run build` before serving. With `--sail` or `--docker` the bake prints the oven's own Next steps; follow those when they differ.
5. Wire in ADVISOR with `php vendor/bin/advisor install --client=claude --write`, then serve the app with `php artisan serve`, and tell me to open http://localhost:8000. Locally SESAME signs in with its fake driver.
6. Run `sourdough manifest --check` in the app and report what it says.

Constraints:
- The app requires waynestate/playbook, WSU's private shared-values package on git.wayne.edu; the settings in .env.example come from it. If the bake refuses because this machine cannot read the package, tell me; do not pass --no-playbook, which is for CI and automated tests only.
- .sourdough/manifest.json records every file Sourdough wrote. Managed files are Sourdough's; .env.example and README.md are seeded and mine. Commit the manifest.
- The deploy files (build.sh, scripts/release.sh, the .squire/ config) come from Squire. Never edit them by hand.
- Never commit .env, and never paste a secret into a prompt or a file.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess: a folder name, a shape or an oven I have not named, or anything that touches a deploy.

Output: what you ran and what it said, the app's files one level deep, then your questions.
```

### Adopt an existing app with Sourdough

Looks at an existing app with a dry run, picks the shape and the name with you, then writes the manifest and shows what the first feed would add. It changes no code.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer bring an existing app under Sourdough, the app starter of WELD, the Wayne State application stack. Sourdough did not make this app. sourdough adopt writes the manifest the app would have had, .sourdough/manifest.json, so sourdough feed can keep it current from then on. It writes that one file and changes no code: files that match the template become managed, files that differ stay the app's own (yours) and are never overwritten, and files the app lacks are added by the first feed.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, then the Adopt an existing app guide it lists. Fetch what you need; do not work from memory.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sourdough, and run_proctor_check for this app. If it is not connected, say so and work from the pages.
- composer.json, package.json if there is one, and whether an artisan file sits at the app's root.

Task:
1. Check that PHP 8.3 or newer, Composer and git are on the PATH, and say which versions you found. If the sourdough command is missing, install it with `composer global require waynestate/sourdough`.
2. Run `git status --short` at the app's root. If the folder is not a git repository, or the tree has changes, stop and tell me. Do not commit for me.
3. Propose the shape, one of raw, raw-vue, laravel-blade, laravel-blade-vue or laravel-inertia, with the evidence (artisan, inertiajs/inertia-laravel in composer.json, vue in package.json). Propose the name the app goes by, from composer.json. Ask me to confirm both.
4. Run `sourdough adopt --shape  --name  --dry-run` and summarize it: how many files match, are yours, seeded and missing, and any note (a name that looks wrong, an older Squire kit).
5. Only when I say so, run the same command without --dry-run, then `sourdough feed --dry-run`, and list the files the first feed would add.

Constraints:
- Pass --sail or --docker only for a Laravel app that runs on Sourdough's own oven files. An app with its own Docker setup gets neither.
- Many yours files is a normal outcome, not a failure. For a Laravel app several majors behind, suggest upgrading with Booster first (https://booster.apps.wayne.edu/llms.txt): every hop brings more files level with the template.
- Never edit, rename or delete the app's files to make more of them match. Never run sourdough feed without --dry-run unless I say so: a real feed writes files.
- Never commit .env, and never paste a secret into a prompt or a file.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess: the shape, the name, or anything that touches a deploy.

Output: the shape and the name you propose with their evidence, the dry run's summary line with its yours and missing lists, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://sourdough.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://sourdough.apps.wayne.edu/llms-full.txt) holds them all in one file, and this page has a [Markdown twin](https://sourdough.apps.wayne.edu/index.md). Nothing here phones home. The buttons only copy text or open the tool you picked.

The rest of the bakery

## Same kitchen, many ovens

Sourdough is one part of the Wayne State app stack, and it starts every shape. [WELD](https://weld.apps.wayne.edu/) is the Inertia/Vue one already built from it: a Laravel app with Inertia and Vue, with the stack's product docs and site on top.

### In every loaf

- [![WayneUI logo: a rounded frame holding a solid form, with a gold tab on the lower edge](https://sourdough.apps.wayne.edu/assets/marks/wayneui.svg) **WayneUI** the interface](https://wayneui.apps.wayne.edu/)
- [![SESAME logo: an open door with a gold gap](https://sourdough.apps.wayne.edu/assets/marks/sesame.png) **SESAME** sign-in](https://sesame.apps.wayne.edu/)
- [![HELMET logo: a white football helmet with a gold facemask](https://sourdough.apps.wayne.edu/assets/marks/helmet.png) **HELMET** runtime baseline](https://helmet.apps.wayne.edu/)
- [![PROCTOR logo: a watchful eye with a gold square pupil](https://sourdough.apps.wayne.edu/assets/marks/proctor.png) **PROCTOR** the checks](https://proctor.apps.wayne.edu/)
- [![Preflight logo: a runway with a gold center line](https://sourdough.apps.wayne.edu/assets/marks/preflight.png) **Preflight** shared CI](https://preflight.apps.wayne.edu/)

### From the same kitchen

- [![CREED logo: a stone arch whose gold keystone holds the other stones in place](https://sourdough.apps.wayne.edu/assets/marks/creed.png) **CREED** the standards](https://creed.apps.wayne.edu/)
- [![Pinstripe logo: a striped necktie with a gold tie bar](https://sourdough.apps.wayne.edu/assets/marks/pinstripe.png) **Pinstripe** the coding standard](https://pinstripe.apps.wayne.edu/)
- ![Sourdough logo: a starter jar with a gold band](https://sourdough.apps.wayne.edu/brand/sourdough-avatar-256.png)**Sourdough** you are here
- [![WELD logo: a welder's helmet](https://sourdough.apps.wayne.edu/assets/marks/weld.png) **WELD** Laravel and Vue](https://weld.apps.wayne.edu/)

### Also in the kitchen

- [![Squire logo: a satchel with a gold buckle](https://sourdough.apps.wayne.edu/assets/marks/squire.png) **Squire** carries releases](https://squire.apps.wayne.edu/)
- [![Parity logo: a spirit level with its gold bubble in the middle](https://sourdough.apps.wayne.edu/assets/marks/parity.png) **Parity** Citadel at home](https://parity.apps.wayne.edu/)
- [![Rollcall logo: an open register with gold ticks](https://sourdough.apps.wayne.edu/assets/marks/rollcall.png) **Rollcall** every app, present](https://rollcall.apps.wayne.edu/)
- [![ADVISOR logo: a speech bubble holding a gold compass needle](https://sourdough.apps.wayne.edu/assets/marks/advisor.png) **ADVISOR** the MCP server](https://advisor.apps.wayne.edu/)
- [![Drawbridge logo: a castle tower lowering its bridge on a gold chain](https://sourdough.apps.wayne.edu/assets/marks/drawbridge.png) **Drawbridge** sign-in gateway](https://drawbridge.apps.wayne.edu/)
- [![Booster logo: a syringe that is also a rocket, with one gold accent](https://sourdough.apps.wayne.edu/assets/marks/booster.png) **Booster** PHP upgrades](https://booster.apps.wayne.edu/)
