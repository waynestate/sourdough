---
title: "Sourdough: Feed it and it grows."
description: "Sourdough makes a new raw PHP app for Wayne State with SESAME sign-in, HELMET headers, PROCTOR, Preflight CI and Squire's Citadel deploy files already in it. The Laravel shapes come in a later release."
url: https://sourdough.apps.wayne.edu/
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

A starter for Wayne State apps

# Sourdough

Feed it and it grows.

![Sourdough logo: a starter jar whose starter has risen into a dome, well above the gold band that marks where it began](https://sourdough.apps.wayne.edu/brand/sourdough-avatar-512.png)

Hungry. It has not been fed yet today.

`sourdough new` makes a raw PHP app with the stack already in it: SESAME for sign-in, HELMET for the security headers, PROCTOR and Preflight for the checks, and Squire's files for deploying to Citadel. You add WayneUI's standalone file with one download. Raw PHP is the only shape it makes today; the Laravel shapes come in a later release.

The whole recipe

```
$ composer global require waynestate/sourdough
$ sourdough new my-app
```

**Want Laravel with Inertia and Vue? Start from [WELD](https://weld.apps.wayne.edu/).** Sourdough is for a smaller raw PHP app with the same sign-in, headers and checks.

[Start baking](https://sourdough.apps.wayne.edu/#start-title) [See the five loaves](https://sourdough.apps.wayne.edu/#loaves-title)

Proofing log

## The proof

What goes into the tub when you run `sourdough new`.

1. 08:00 **Mix.** `sourdough new` picks the shape. In 0.1 that's raw PHP.
2. 08:20 **Flour: WayneUI.** The interface, from WayneUI's standalone PHP file, which you download once.
3. 08:40 **Water: SESAME.** Sign-in. A fake driver locally, CAS in production.
4. 09:00 **Salt: HELMET.** Security headers, a hardened session, and `/up` for the load balancer.
5. 10:30 **Levain: PROCTOR.** The standards check. Preflight's CI components run it on every push, along with the tests and a Composer audit.
6. 13:00 **Doubled.** Well past the gold band. A label goes on: `.sourdough/manifest.json`.
7. 14:00 **Divide and shape.** Cut into the five shapes the stack plans for.

On the cooling rack

## Five loaves, one starter

These are the five app shapes the stack supports. Sourdough 0.1 makes the raw PHP one. The other four print their plan and stop with exit 1, so they're still proofing. Score a loaf to see what's inside.

Country boule

### Raw PHP

Raw PHP with WayneUI's standalone path. No framework, no build.

Out of the oven · 0.1

`sourdough new my-app --raw`

The crumb

- `public/index.php` HELMET boots first, then SESAME signs you in
- `composer.json` helmet and sesame, with proctor for dev
- `.gitlab-ci.yml` Preflight components: PROCTOR, tests on PHP 8.3 to 8.5, audit
- `build.sh` and `scripts/release.sh` Squire's Citadel deploy files
- `.env.example` and `README.md` seeded: yours from day one
- `.sourdough/manifest.json` the label on the jar

Bâtard

### Raw PHP + Vue

A Vue single-page app over JSON, on raw PHP.

Coming soon

The crumb

Cut too early: still dough in the middle. In 0.1, `--raw --vue` prints its plan and stops.

Baguette

### Laravel + Blade

The classic Wayne State shape: Laravel with Blade views.

Coming soon

The crumb

The plan is to start from Laravel's own installer and add SESAME, HELMET, PROCTOR and WayneUI. In 0.1, `--laravel` prints that plan and stops.

Seeded loaf

### Laravel + Blade + Vue islands

Blade pages with Vue islands, like seeds in the crust.

Coming soon

The crumb

Planned for a later release. In 0.1, `--laravel --vue` prints its plan and stops.

Tin loaf

### Laravel + Inertia/Vue

Laravel with Inertia and Vue, as one app.

Coming soon

The crumb

Need this one now? [WELD](https://weld.apps.wayne.edu/) is a Laravel app with Inertia and Vue and the stack already installed: `composer create-project waynestate/weld my-app`.

Not sure yet? `sourdough new my-app --dry-run` prints the plan and writes nothing. It works with every shape's flags, including the four that don't bake yet.

Recipe card No. 1

## What's baked in

Baker's percentages, roughly. The parts are Composer packages, so `composer update` updates them. Only the code that connects them lives in the template.

**The parts of the stack in every Sourdough app, as ingredients**

| Ingredient | Part | What it does in your app |
| --- | --- | --- |
| 100% Flour | [WayneUI](https://wayneui.apps.wayne.edu/) | The interface: WayneUI's standalone PHP file, fetched once into `public/`. |
| 75% Water | [SESAME](https://sesame.apps.wayne.edu/) | Sign-in. The fake driver in development; the cas driver in production, with `SESAME_CAS_URL` and `SESAME_SERVICE_URL`. |
| 2% Salt | [HELMET](https://helmet.apps.wayne.edu/) | The runtime baseline: security headers, session cookie flags, trusted proxies from `HELMET_PROXIES`, and `/up` answering `200 ok`. |
| 20% Levain | [PROCTOR](https://proctor.apps.wayne.edu/) | The standards check. CI runs `vendor/bin/proctor check` on every push. The release script runs PROCTOR too, as a report unless you set `SQUIRE_PROCTOR=enforce`. |
| 1 Oven | [Preflight](https://preflight.apps.wayne.edu/) | CI from `.gitlab-ci.yml`: PROCTOR, tests on PHP 8.3, 8.4 and 8.5, and a Composer audit. |
| 1 Basket | Release script | `build.sh` and `scripts/release.sh` come from Squire and run on Citadel at build and release time. Off Citadel, the app reads the same environment variables on OpenShift, Coolify or raw Docker. |

Bake at `php -S localhost:8000 -t public` until golden.

Pinned above the bench

## Feeding schedule

A starter you never feed goes flat. Every app Sourdough makes carries `.sourdough/manifest.json`: the Sourdough version, the template, the answers you chose, and a sha256 of every file. That's how a later `sourdough feed` will know which files it can update.

1. Bake day
   `sourdough new my-app`
   Makes the app and writes the manifest. Commit the manifest with the rest of the app.
2. Any day
   `sourdough manifest --check`
   Is it still alive? Lists every managed file that has drifted from the manifest. Run it in the app; the generated CI doesn't run it for you.
   - **0**: clean
   - **1**: a managed file changed or is missing
   - **2**: no manifest, or no `--check`
3. Feeding
   sourdough feed coming soon
   Brings template updates into your app without clobbering what you changed.

### Managed we keep feeding these

- `public/index.php`
- `composer.json`
- `.gitlab-ci.yml`
- Squire's deploy files: `build.sh`, `scripts/`, `deployments/`, `.squire/`
- `.gitattributes`, `.gitignore`

### Seeded written once, yours now

- `.env.example`
- `README.md`

Seeded files are never reported. They're yours to knead.

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
curl.exe -o public/wayne-ui.php https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php
# 4. Install and serve
composer install
php -S localhost:8000 -t public
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
curl -o public/wayne-ui.php https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php
# 4. Install and serve
composer install
php -S localhost:8000 -t public
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
curl -o public/wayne-ui.php https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php
# 4. Install and serve
composer install
php -S localhost:8000 -t public
```

**`sourdough` not found?** Add the folder that `composer global config bin-dir --absolute` prints to your PATH. Then open `http://localhost:8000`: locally the app signs in with SESAME's fake driver.

Hand it to your agent

## Recipes for your agent

Two prompts for whatever coding agent you use. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT. Each tells the agent what Sourdough put in the app and what it must leave alone, and both tell it to ask you before it guesses. There's no prompt for the Laravel shapes yet, because they don't bake yet.

### Get started with Sourdough

Installs Sourdough, makes a raw PHP app with WayneUI, serves it locally and runs the manifest check.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer bake a new app with Sourdough, the app starter of WELD, the Wayne State application stack. Sourdough 0.1 bakes one shape, raw PHP on WayneUI's standalone path, with SESAME (sign-in), HELMET (security headers and the /up check), PROCTOR (checks), the Preflight CI components and Squire's Citadel deploy files already in it.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, the index of Sourdough's pages, each one as Markdown; llms-full.txt beside it holds the README too. Fetch what you need; do not work from memory.
- ADVISOR, the stack's MCP server, if it is connected: get_project_status and read_project_docs for sourdough answer from the stack's own docs, and policy_lookup gives today's PHP support from CREED. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Check that PHP 8.3 or newer and Composer are on the PATH, and say which versions you found.
2. Install the starter with `composer global require waynestate/sourdough`. If the sourdough command is then not found, add the folder that `composer global config bin-dir --absolute` prints to the PATH.
3. Bake the app with `sourdough new my-app --raw`, using the folder name I give you in place of my-app, and change into it.
4. Fetch WayneUI's standalone file once. Windows (PowerShell): `curl.exe -o public/wayne-ui.php https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php`. macOS and Linux: `curl -o public/wayne-ui.php https://git.wayne.edu/enterprise-apps/wayne-ui/-/raw/main/php/standalone/wayne-ui.php`.
5. Run `composer install`, then `php -S localhost:8000 -t public`, and tell me to open http://localhost:8000. Locally SESAME signs in with its fake driver.
6. Run `sourdough manifest --check` in the app and report what it says.

Constraints:
- Only the raw PHP shape bakes in Sourdough 0.1. The Laravel and Vue flags print a plan and stop with exit 1; never say they made an app. For Laravel with Inertia and Vue, WELD is the finished app: https://weld.apps.wayne.edu/llms.txt
- .sourdough/manifest.json records every file Sourdough wrote. Managed files are Sourdough's; .env.example and README.md are seeded and mine. Commit the manifest.
- The deploy files (build.sh, scripts/release.sh, the .squire/ config) come from Squire. Never edit them by hand.
- Never commit .env, and never paste a secret into a prompt or a file.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess: a folder name, a PHP version you cannot read, or anything that touches a deploy.

Output: what you ran and what it said, the app's files one level deep, then your questions.
```

### Check what has drifted in a Sourdough app

Lists the managed files that changed since Sourdough wrote them and suggests what to put back. It changes nothing.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent checking an app that Sourdough baked, at Wayne State University. Sourdough is the app starter of WELD, the Wayne State application stack. Every app it bakes carries .sourdough/manifest.json: the Sourdough version, the template, the answers chosen, and a sha256 hash of every file it wrote, each marked managed or seeded.

Read first:
- https://sourdough.apps.wayne.edu/llms.txt, then llms-full.txt beside it: the README's manifest section says what managed and seeded mean.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for sourdough and squire, and run_proctor_check for this app. If it is not connected, say so and work from the pages.
- .sourdough/manifest.json, composer.json and public/index.php in this app.

Task:
1. Run `sourdough manifest --check` at the app's root. Exit 0 is clean, 1 means a managed file changed or is missing, 2 is a tool error.
2. For each file it reports, say what changed, as far as you can tell, and whether it looks deliberate.
3. If ADVISOR is connected, call run_proctor_check for this app and list the findings.
4. Change nothing. For each drifted file, propose whether to put it back or keep the change, and why.

Constraints:
- Seeded files (.env.example and README.md) are mine and never count as drift.
- The deploy files come from Squire, through Sourdough. Never hand-edit them to make a check pass.
- sourdough feed, which will bring template updates in, is not built yet. Do not promise it.
- Ask me before you guess, and before you touch anything that deploys.

Output: a table (file, managed or seeded, state, deliberate or not), the PROCTOR findings, then your proposals and your questions.
```

The agent reads the same pages you do: [llms.txt](https://sourdough.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://sourdough.apps.wayne.edu/llms-full.txt) holds them all in one file, and this page has a [Markdown twin](https://sourdough.apps.wayne.edu/index.md). Nothing here phones home. The buttons only copy text or open the tool you picked.

The rest of the bakery

## Same kitchen, many ovens

Sourdough is one part of the Wayne State app stack. [WELD](https://weld.apps.wayne.edu/) is the other starter: a Laravel app with Inertia and Vue, with the same parts already installed.

### In every loaf

- [![WayneUI logo: a rounded frame holding a solid form, with a gold tab on the lower edge](https://sourdough.apps.wayne.edu/assets/marks/wayneui.svg) **WayneUI** the interface](https://wayneui.apps.wayne.edu/)
- [![SESAME logo: an open door with a gold gap](https://sourdough.apps.wayne.edu/assets/marks/sesame.png) **SESAME** sign-in](https://sesame.apps.wayne.edu/)
- [![HELMET logo: a white football helmet with a gold facemask](https://sourdough.apps.wayne.edu/assets/marks/helmet.png) **HELMET** runtime baseline](https://helmet.apps.wayne.edu/)
- [![PROCTOR logo: a watchful eye with a gold square pupil](https://sourdough.apps.wayne.edu/assets/marks/proctor.png) **PROCTOR** the checks](https://proctor.apps.wayne.edu/)
- [![Preflight logo: a runway with a gold centre line](https://sourdough.apps.wayne.edu/assets/marks/preflight.png) **Preflight** shared CI](https://preflight.apps.wayne.edu/)

### From the same kitchen

- [![CREED logo: a stone arch whose gold keystone holds the other stones in place](https://sourdough.apps.wayne.edu/assets/marks/creed.png) **CREED** the standards](https://creed.apps.wayne.edu/)
- [![Pinstripe logo: a striped necktie with a gold tie bar](https://sourdough.apps.wayne.edu/assets/marks/pinstripe.png) **Pinstripe** the coding standard](https://pinstripe.apps.wayne.edu/)
- ![Sourdough logo: a starter jar with a gold band](https://sourdough.apps.wayne.edu/brand/sourdough-avatar-256.png)**Sourdough** you are here
- [![WELD logo: a welder's helmet](https://sourdough.apps.wayne.edu/assets/marks/weld.png) **WELD** Laravel and Vue](https://weld.apps.wayne.edu/)

### Also in the kitchen

- [![Squire logo: a satchel with a gold buckle](https://sourdough.apps.wayne.edu/assets/marks/squire.png) **Squire** carries releases](https://squire.apps.wayne.edu/)
- [![Rollcall logo: an open register with gold ticks](https://sourdough.apps.wayne.edu/assets/marks/rollcall.png) **Rollcall** every app, present](https://rollcall.apps.wayne.edu/)
- [![ADVISOR logo: a speech bubble holding a gold compass needle](https://sourdough.apps.wayne.edu/assets/marks/advisor.png) **ADVISOR** the MCP server](https://advisor.apps.wayne.edu/)
- [![Drawbridge logo: a castle tower lowering its bridge on a gold chain](https://sourdough.apps.wayne.edu/assets/marks/drawbridge.png) **Drawbridge** sign-in gateway](https://drawbridge.apps.wayne.edu/)
- [![Booster logo: a syringe that is also a rocket, with one gold accent](https://sourdough.apps.wayne.edu/assets/marks/booster.png) **Booster** PHP upgrades](https://booster.apps.wayne.edu/)
