---
title: "Quick start | Sourdough docs"
description: "Bake your first app with sourdough new, follow its Next steps, and run the app's own checks."
url: https://sourdough.apps.wayne.edu/docs/guides/quick-start.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

From a machine where `sourdough` is installed (see [Install](https://sourdough.apps.wayne.edu/docs/install.html)), bake an app and prove it.

## Steps[#](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html#steps)

1. Scaffold the app.
   ```
   sourdough new my-app
   ```
   Expected: exit code `0` (done). The `my-app` directory appears with a Wayne State app in it: SESAME (auth), HELMET (baseline runtime), PROCTOR (checks), and the Preflight CI components already wired in. Sourdough prints Next steps for the new app.
2. Run the app's own checks.
   From inside `my-app/`:
   ```
   composer ci
   ```
   Expected: exit code `0`. A freshly baked app passes its own `composer ci` on the first run.

## What you built[#](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

- A Wayne State app with the stack already wired in: SESAME (auth), HELMET (baseline runtime), PROCTOR (checks), and the Preflight CI components.
- Playbook wiring by default: the VCS repository, the package in `require`, `config.gitlab-domains` so Composer sends your token to git.wayne.edu, the seven setting names in `.env.example`, a `composer playbook` script that shows whether Playbook is installed, and a "WSU values (PLAYBOOK)" README section.
- ADVISOR in `require-dev`, a git-ignored `.mcp.json`, and an "ADVISOR" README section.
- `.sourdough/manifest.json`, the starter's memory: the Sourdough version, the template, the answers you chose, and a sha256 hash of every file, each marked `managed` (Sourdough owns it and will update it) or `seeded` (Sourdough wrote it once and you own it now: `.env.example` and `README.md`).
- `.sourdough/plan.yaml`, the plan the app was baked from: the shape, the name and the stack parts (plus the models and pages, when you baked from a plan file). It bakes the same loaf again with `--plan`; see [The saved plan](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#the-saved-plan).

## Next steps[#](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- Follow the app's printed Next steps: they point at `composer playbook` once dependencies are in, and at `php vendor/bin/advisor install --client=claude --write` for ADVISOR.
- [Pick a shape](https://sourdough.apps.wayne.edu/docs/guides/shapes.html): the permutations, from raw PHP to Laravel plus Inertia/Vue.
- [Describe a plan](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html): describe what the app does and let a model plan it.
- [The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html): what the manifest records, and `sourdough manifest --check`.
- [CI](https://sourdough.apps.wayne.edu/docs/guides/ci.html): set up the generated app's CI; a git.wayne.edu instance runner is strongly recommended.
- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html): every flag and option.
