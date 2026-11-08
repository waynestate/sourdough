---
title: CI
description: Set up the generated app's CI with the Preflight components on GitLab and GitHub, and why a git.wayne.edu instance runner is strongly recommended.
---

# CI

Sourdough's pipelines run one gate, `composer ci`, on both forges: the same command on GitLab and GitHub. This page covers what each pipeline runs, what green means, and where the pipelines should run. For what the gate contains, continue to [Testing](./testing.md).

> [!TIP]
> Strongly recommended: a git.wayne.edu instance runner.

## On GitLab

Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job. The raw shapes add the `proctor` component. Every include is pinned to the Preflight 0.3.0 release commit, with the tag in a trailing comment.

## Is the app still fed?

Every shape also ships a `sourdough-feed` job. It installs the newest Sourdough release (`composer create-project waynestate/sourdough`) on the shape's own `composer` image, through `${IMAGE_PREFIX}` and on the `.composer-cache`, and runs two checks against the app's manifest:

- `sourdough manifest --check` lists the managed files edited since the last bake or feed. Drift is normal (feed merges it), so the list is a report and never fails the job.
- `sourdough feed --dry-run` exits `1` when a newer release has template changes the app has not fed. The job exits `1` too, and `allow_failure: exit_codes: [1]` turns that into a warning: a pending feed never blocks a deploy.

Exit `2` from either (no manifest, a manifest from a newer Sourdough, conflict markers left over from the last feed, a bake that failed) fails the job, because the app's record needs fixing before any feed can run. The full table and the job itself are in [Keeping apps current](./keeping-current.md#feed-in-ci).

## Dependency caching

A pipeline that downloads every image and package on every run can get the runner's address rate limited by Docker Hub, and a busy afternoon of fifty jobs can look like abuse. Every shape's `.gitlab-ci.yml` keeps those downloads cached, so a fed starter does not go asking the neighbors for flour.

**Images.** Each job's image is `${IMAGE_PREFIX}/<image>`, digest pins kept. `IMAGE_PREFIX` defaults to `docker.io`, and a `workflow:` rule sets it to the group's [GitLab Dependency Proxy](https://docs.gitlab.com/user/packages/dependency_proxy/) (`$CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX`) when the GitLab has one. The runner signs in to the proxy on its own; the proxy asks Docker Hub once and serves its copy after that. The Preflight components (0.3.0 and later) put a prefix in front of their own default images themselves, through their `image_prefix` input; every shape hands them `image_prefix: "${IMAGE_PREFIX}"`, so components and jobs follow the same rule. The shapes pass no `image` input, so each component runs the default image its pinned release carries, pinned by digest.

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

> [!WARNING]
> To run a component on another image, give its `image` input the plain name, such as `php:8.4-cli-trixie@sha256:<digest>`, never `${IMAGE_PREFIX}/php:...`. The component already adds `image_prefix` in front, so a prefixed image is pulled from a path that does not exist and the job fails before it starts.

**Packages.** `COMPOSER_CACHE_DIR` and `npm_config_cache` point at `.cache/composer/` and `.cache/npm/` inside the project, and every job that installs extends `.composer-cache` (keyed on `composer.lock`) or `.npm-cache` (keyed on `package-lock.json` and `package.json`). A lock change starts a fresh cache, and the install still resolves from the lock, so a cached run never gets a stale package.

`tests/TemplateCiCacheTest.php` fails Sourdough's own suite when a shape's own job gains an image without `${IMAGE_PREFIX}/` or an install without its cache, and `tests/PreflightPinTest.php` fails it when a shape pins another Preflight version than the rest, gives a component a prefixed `image`, or leaves out `image_prefix`.

## On GitHub

`.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, pinned to Preflight 0.1.4: GitHub's copy of Preflight has no later tag yet, so the GitHub side stays there while GitLab runs 0.3.0.

## What green means

Green means `composer ci` passed on all three PHP versions on both.

## The smoke jobs on CI

On CI the smoke gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof. The full recipe is in [Testing](./testing.md).

The `proof` job runs the whole [proof harness](../reference/proof.md) on GitLab: every shape and every oven, with the last release fed forward, and keeps the Last run table and every step log as an artifact. It never runs on a push: start it by hand from any pipeline, or from a pipeline schedule that sets `SOURDOUGH_PROOF` to `1`.

## On the bench

![Five loaves on the proofing bench (raw, raw + vue, blade, blade + islands, inertia) above the feeding schedule chalkboard](../readme/gallery.jpg)

## The recipe card

![Sourdough brand board: the starter jar mark, palette and type](../readme/board.jpg)

## Related

- [Testing](./testing.md)
- [Same kitchen, many ovens](./ovens.md)
- [Wiring the stack in](./wiring-the-stack.md)
- [Maintaining](../maintaining.md)
