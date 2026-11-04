---
title: "Overview"
description: "What Sourdough bakes: install, flags, permutations, plans, the manifest and the checks."
---

[![Sourdough: dough in a jar, risen past its gold band](readme/hero.jpg)](../index.html)

# Sourdough

![php](readme/badges/php.svg) ![license](readme/badges/license.svg) ![exit codes](readme/badges/exit-codes.svg) [![GitHub CI](https://github.com/waynestate/sourdough/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/sourdough/actions/workflows/ci.yml)

**Feed it and it grows.**

A starter you feed: `sourdough new` makes a Wayne State app with the stack already wired in. One command scaffolds a new app with SESAME (auth), HELMET (baseline runtime), Pinstripe (coding standard), PROCTOR (checks), and the Preflight CI components already in place. It is one PHP command, so it works on Windows, macOS, and Linux.

Every loaf gets proofed before it ships. Checks: `composer ci` (the same command runs on GitLab and GitHub).

![The proof: dough rises past its gold band as WayneUI, SESAME, HELMET, PROCTOR and Preflight go in one by one, over the feeding schedule](readme/proof.svg)

<sub>The proofing log from the front page: what goes into the tub when you run <code>sourdough new</code>, and the day-to-day check that keeps it alive.</sub>

## Install

```
composer global require waynestate/sourdough
```

After `composer global require`, the `sourdough` command lives in `composer global config bin-dir --absolute`; put that directory on your PATH. Or scaffold without installing, with `composer create-project waynestate/sourdough`. The full instructions are on [Install](install.md).

## First run

```
sourdough new my-app
```

Expected: exit code `0` (done), a `my-app` directory holding a Wayne State app with the stack wired in, and Next steps printed for it. Then run the app's own gate from inside `my-app/`:

```
composer ci
```

The step-by-step version is the [Quick start](guides/quick-start.md).

## Pick your path

- [Quick start](guides/quick-start.md): bake your first app and run its checks.
- [Install](install.md): what the machine needs, and the two ways to get Sourdough.
- [Reference](reference/commands.md): every command and option, the plan format, the templates.

## Status

Version 0.1.5 (2026-11-03), MIT license. Agents: this site ships [llms.txt](https://sourdough.apps.wayne.edu/llms.txt) for LLM readers.

<details>
<summary>On the bench: the five loaves and the feeding schedule</summary>
<img src="readme/gallery.jpg" alt="Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard">
</details>

<details>
<summary>The recipe card: Sourdough's brand board</summary>
<img src="readme/board.jpg" alt="Sourdough brand board: the starter jar mark, palette and type">
</details>

## Same kitchen, many ovens

<a href="https://github.com/waynestate/wayne-ui"><img src="readme/cast/wayneui.png" width="44" alt="WayneUI"></a>
<a href="https://github.com/waynestate/creed"><img src="readme/cast/creed.png" width="44" alt="CREED"></a>
<a href="https://github.com/waynestate/proctor"><img src="readme/cast/proctor.png" width="44" alt="PROCTOR"></a>
<a href="https://github.com/waynestate/sesame"><img src="readme/cast/sesame.png" width="44" alt="SESAME"></a>
<a href="https://github.com/waynestate/helmet"><img src="readme/cast/helmet.png" width="44" alt="HELMET"></a>
<a href="https://github.com/waynestate/sourdough"><img src="readme/cast/sourdough.png" width="44" alt="Sourdough"></a>
<a href="https://github.com/waynestate/squire"><img src="readme/cast/squire.png" width="44" alt="Squire"></a>
<a href="https://github.com/waynestate/preflight"><img src="readme/cast/preflight.png" width="44" alt="Preflight"></a>
<a href="https://github.com/waynestate/pinstripe"><img src="readme/cast/pinstripe.png" width="44" alt="Pinstripe"></a>
<a href="https://github.com/waynestate/booster"><img src="readme/cast/booster.png" width="44" alt="Booster"></a>
<a href="https://github.com/waynestate/rollcall"><img src="readme/cast/rollcall.png" width="44" alt="Rollcall"></a>
<a href="https://github.com/waynestate/drawbridge"><img src="readme/cast/drawbridge.png" width="44" alt="Drawbridge"></a>
<a href="https://github.com/waynestate/advisor"><img src="readme/cast/advisor.png" width="44" alt="ADVISOR"></a>
<a href="https://github.com/waynestate/weld"><img src="readme/cast/weld.png" width="44" alt="WELD"></a>

<sub>Sourdough is one of the parts. Feed it, and it grows; just do not leave it on the counter over a long weekend.</sub>
