---
title: "CI | Sourdough docs"
description: "Set up the generated app's CI with the Preflight components on GitLab and GitHub, and why a git.wayne.edu instance runner is strongly recommended."
url: https://sourdough.apps.wayne.edu/docs/guides/ci.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# CI[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#ci)

Sourdough's pipelines run one gate, `composer ci`, on both forges: the same command on GitLab and GitHub. This page covers what each pipeline runs, what green means, and where the pipelines should run. For what the gate contains, continue to [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html).

Tip

Strongly recommended: a git.wayne.edu instance runner.

## On GitLab[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#on-gitlab)

Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.

## On GitHub[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#on-github)

`.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.

## What green means[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#what-green-means)

Green means `composer ci` passed on all three PHP versions on both.

## The smoke jobs on CI[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#the-smoke-jobs-on-ci)

On CI the smoke gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof. The full recipe is in [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html).

## On the bench[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#on-the-bench)

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](https://sourdough.apps.wayne.edu/docs/readme/gallery.jpg)

## The recipe card[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#the-recipe-card)

![Sourdough brand board: the starter jar mark, palette and type](https://sourdough.apps.wayne.edu/docs/readme/board.jpg)

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#related)

- [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html)
- [Same kitchen, many ovens](https://sourdough.apps.wayne.edu/docs/guides/ovens.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
- [Maintaining](https://sourdough.apps.wayne.edu/docs/maintaining.html)
