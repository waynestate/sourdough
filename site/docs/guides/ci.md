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

## Dependency caching[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#dependency-caching)

A pipeline that downloads every image and package on every run can get the runner's address rate limited by Docker Hub, and a busy afternoon of fifty jobs can look like abuse. Every shape's `.gitlab-ci.yml` keeps those downloads cached, so a fed starter does not go asking the neighbors for flour.

**Images.** Each job's image is `${IMAGE_PREFIX}/<image>`, digest pins kept. `IMAGE_PREFIX` defaults to `docker.io`, and a `workflow:` rule sets it to the group's [GitLab Dependency Proxy](https://docs.gitlab.com/user/packages/dependency_proxy/) (`$CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX`) when the GitLab has one. The runner signs in to the proxy on its own; the proxy asks Docker Hub once and serves its copy after that. The Preflight components' `image` inputs carry the same prefix in front of the components' own default images.

```yaml
workflow:
  rules:
    - if: $CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX
      variables:
        IMAGE_PREFIX: $CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX
    - when: always

variables:
  IMAGE_PREFIX: docker.io
```

On a group whose proxy is turned off, set `IMAGE_PREFIX` to `docker.io` in **Settings > CI/CD > Variables**: a project variable beats the file's own.

**Packages.** `COMPOSER_CACHE_DIR` and `npm_config_cache` point at `.cache/composer/` and `.cache/npm/` inside the project, and every job that installs extends `.composer-cache` (keyed on `composer.lock`) or `.npm-cache` (keyed on `package-lock.json` and `package.json`). A lock change starts a fresh cache, and the install still resolves from the lock, so a cached run never gets a stale package.

`tests/TemplateCiCacheTest.php` fails Sourdough's own suite when a shape's pipeline gains an image without `${IMAGE_PREFIX}/` or an install without its cache.

## On GitHub[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#on-github)

`.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.

## What green means[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#what-green-means)

Green means `composer ci` passed on all three PHP versions on both.

## The smoke jobs on CI[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#the-smoke-jobs-on-ci)

On CI the smoke gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof. The full recipe is in [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html).

The `proof` job runs the whole [proof harness](https://sourdough.apps.wayne.edu/docs/reference/proof.html) on GitLab: every shape and every oven, with the last release fed forward, and keeps the Last run table and every step log as an artifact. It never runs on a push: start it by hand from any pipeline, or from a pipeline schedule that sets `SOURDOUGH_PROOF` to `1`.

## On the bench[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#on-the-bench)

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](https://sourdough.apps.wayne.edu/docs/readme/gallery.jpg)

## The recipe card[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#the-recipe-card)

![Sourdough brand board: the starter jar mark, palette and type](https://sourdough.apps.wayne.edu/docs/readme/board.jpg)

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/ci.html#related)

- [Testing](https://sourdough.apps.wayne.edu/docs/guides/testing.html)
- [Same kitchen, many ovens](https://sourdough.apps.wayne.edu/docs/guides/ovens.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
- [Maintaining](https://sourdough.apps.wayne.edu/docs/maintaining.html)
