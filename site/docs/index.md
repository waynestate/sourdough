---
title: "Overview | Sourdough docs"
description: "What Sourdough bakes: install, flags, permutations, plans, the manifest and the checks."
url: https://sourdough.apps.wayne.edu/docs/
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

[![Sourdough: dough in a jar, risen past its gold band](https://sourdough.apps.wayne.edu/docs/readme/hero.jpg)](https://sourdough.apps.wayne.edu/index.html)

# Sourdough[#](https://sourdough.apps.wayne.edu/docs/#sourdough)

![php](https://sourdough.apps.wayne.edu/docs/readme/badges/php.svg) ![license](https://sourdough.apps.wayne.edu/docs/readme/badges/license.svg) ![exit codes](https://sourdough.apps.wayne.edu/docs/readme/badges/exit-codes.svg) [GitHub CI](https://github.com/waynestate/sourdough/actions/workflows/ci.yml)

**Feed it and it grows.**

A starter you feed: `sourdough new` makes a Wayne State app with the stack already wired in. One command scaffolds a new app with SESAME (auth), HELMET (baseline runtime), Pinstripe (coding standard), PROCTOR (checks), and the Preflight CI components already in place. It is one PHP command, so it works on Windows, macOS, and Linux.

Every loaf gets proofed before it ships. Checks: `composer ci` (the same command runs on GitLab and GitHub).

![The proof: dough rises past its gold band as WayneUI, SESAME, HELMET, PROCTOR and Preflight go in one by one, over the feeding schedule](https://sourdough.apps.wayne.edu/docs/readme/proof.svg)

The proofing log from the front page: what goes into the tub when you run `sourdough new`, and the day-to-day check that keeps it alive.

## Install[#](https://sourdough.apps.wayne.edu/docs/#install)

```
composer global require waynestate/sourdough
```

After `composer global require`, the `sourdough` command lives in `composer global config bin-dir --absolute`; put that directory on your PATH. Or scaffold without installing, with `composer create-project waynestate/sourdough`. The full instructions are on [Install](https://sourdough.apps.wayne.edu/docs/install.html).

## First run[#](https://sourdough.apps.wayne.edu/docs/#first-run)

```
sourdough new my-app
```

Expected: exit code `0` (done), a `my-app` directory holding a Wayne State app with the stack wired in, and Next steps printed for it. Then run the app's own gate from inside `my-app/`:

```
composer ci
```

The step-by-step version is the [Quick start](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html).

## Pick your path[#](https://sourdough.apps.wayne.edu/docs/#pick-your-path)

- [Quick start](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html): bake your first app and run its checks.
- [Install](https://sourdough.apps.wayne.edu/docs/install.html): what the machine needs, and the two ways to get Sourdough.
- [Reference](https://sourdough.apps.wayne.edu/docs/reference/commands.html): every command and option, the plan format, the templates.

## Status[#](https://sourdough.apps.wayne.edu/docs/#status)

Version 0.1.5 (2026-11-03), MIT license. Agents: this site ships [llms.txt](https://sourdough.apps.wayne.edu/llms.txt) for LLM readers.

**On the bench: the five loaves and the feeding schedule**

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](https://sourdough.apps.wayne.edu/docs/readme/gallery.jpg)

**The recipe card: Sourdough's brand board**

![Sourdough brand board: the starter jar mark, palette and type](https://sourdough.apps.wayne.edu/docs/readme/board.jpg)

## Same kitchen, many ovens[#](https://sourdough.apps.wayne.edu/docs/#same-kitchen-many-ovens)

[![WayneUI](https://sourdough.apps.wayne.edu/docs/readme/cast/wayneui.png)](https://github.com/waynestate/wayne-ui) [![CREED](https://sourdough.apps.wayne.edu/docs/readme/cast/creed.png)](https://github.com/waynestate/creed) [![PROCTOR](https://sourdough.apps.wayne.edu/docs/readme/cast/proctor.png)](https://github.com/waynestate/proctor) [![SESAME](https://sourdough.apps.wayne.edu/docs/readme/cast/sesame.png)](https://github.com/waynestate/sesame) [![HELMET](https://sourdough.apps.wayne.edu/docs/readme/cast/helmet.png)](https://github.com/waynestate/helmet) [![Sourdough](https://sourdough.apps.wayne.edu/docs/readme/cast/sourdough.png)](https://github.com/waynestate/sourdough) [![Squire](https://sourdough.apps.wayne.edu/docs/readme/cast/squire.png)](https://github.com/waynestate/squire) [![Preflight](https://sourdough.apps.wayne.edu/docs/readme/cast/preflight.png)](https://github.com/waynestate/preflight) [![Pinstripe](https://sourdough.apps.wayne.edu/docs/readme/cast/pinstripe.png)](https://github.com/waynestate/pinstripe) [![Booster](https://sourdough.apps.wayne.edu/docs/readme/cast/booster.png)](https://github.com/waynestate/booster) [![Rollcall](https://sourdough.apps.wayne.edu/docs/readme/cast/rollcall.png)](https://github.com/waynestate/rollcall) [![Drawbridge](https://sourdough.apps.wayne.edu/docs/readme/cast/drawbridge.png)](https://nougat.snu.gs/enterprise-apps/drawbridge) [![ADVISOR](https://sourdough.apps.wayne.edu/docs/readme/cast/advisor.png)](https://github.com/waynestate/advisor) [![WELD](https://sourdough.apps.wayne.edu/docs/readme/cast/weld.png)](https://github.com/waynestate/weld)

Sourdough is one of the parts. Feed it, and it grows; just do not leave it on the counter over a long weekend.
