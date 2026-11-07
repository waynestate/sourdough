---
title: "Wiring the stack in | Sourdough docs"
description: "How SESAME, HELMET, PROCTOR, the Preflight CI components, Playbook and ADVISOR land in a freshly baked app."
url: https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Wiring the stack in[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#wiring-the-stack-in)

Goal: know exactly what Sourdough wires into a freshly baked app, and why each piece is a default rather than an option. Read this before your first bake, and keep it at hand while you maintain an app. Like the starter it is named after, a Sourdough app only stays alive while something keeps feeding it; the wiring below is the first meal.

Sourdough is the app starter for the WSU stack. One command scaffolds a new app with SESAME (auth), HELMET (baseline runtime), PROCTOR (checks), and the Preflight CI components already in place. It is one PHP command, so it works on Windows, macOS, and Linux.

![The proof: dough rises past its gold band as WayneUI, SESAME, HELMET, PROCTOR and Preflight go in one by one, over the feeding schedule](https://sourdough.apps.wayne.edu/docs/readme/proof.svg)

*The proofing log from the front page: what goes into the tub when you run `sourdough new`, and the day-to-day check that keeps it alive.*

## What lands in every app[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#what-lands-in-every-app)

Every generated app has SESAME, HELMET, PROCTOR as a dev dependency, a `.gitlab-ci.yml` that includes the Preflight components, a `/up` route that does not touch the database, and a README that points at the CREED handbook. The libraries are tagged (SESAME, HELMET with a health check, PROCTOR, Preflight templates), and `sourdough new` wires them into every generated app.

Defaults decide what most apps end up doing, so the stack is wired in by default, not as an option. Laravel's own kits include sign-in, rate limiting and two-factor login out of the box for the same reason.

For the Playbook and ADVISOR wiring and the flags that turn each one off, see [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html).

## The release script runs the check, but do not call it the gate[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#the-release-script-runs-the-check-but-do-not-call-it-the-gate)

The Citadel release script runs `proctor check` and stops on exit 1. Treat that as a report. A failure in a release script cannot stop bad code from going live on a Quick Update, so the real gates are CI before merge and, later, a platform check.

Warning

Promising a gate that does not hold is worse than having none. Nothing prevents someone from describing the script as a gate in a README.

## Keep the start-up path small and boring[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#keep-the-start-up-path-small-and-boring)

Generated commands should run and exit on their own, with no prompts and no network calls after install. Developers, CI and coding agents all run them, and a prompt that waits for input breaks all three.

## Accessibility gate[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#accessibility-gate)

The US Justice Department's Title II web rule sets WCAG 2.1 Level AA for state and local government web content. An interim final rule published on 2026-04-20 moved the first compliance date to 2027-04-26 for entities of 50,000 people or more and to 2028-04-26 for smaller ones. The standard and the ongoing duty to keep content accessible did not change. Whether and when the rule applies to a given WSU app is not decided here; ask WSU's accessibility office.

For Sourdough: each permutation's generated home page and sign-in flow get an automated accessibility check in CI, including the Vue paths (focus after navigation, announced route changes); WayneUI's accessible templates are the default markup; and the "new app" page passes the same gate.

Note

Automated checks find only part of the problems, so the generated README should say so.

## Protect deploy credentials in the generated pipeline[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#protect-deploy-credentials-in-the-generated-pipeline)

GitLab's docs say masking a variable does not reliably stop a malicious user from reading it; protected variables exist only in pipelines on protected branches and tags; a pipeline for a merge request from a fork should run in the parent project only after review, because malicious code can compromise masked and protected variables; and sensitive values belong in an external secrets manager. A project's job-token allowlist includes only the project itself by default, and GitLab calls disabling it a security risk. From GitLab 19.0 job tokens use the JWT format by default, and the legacy format is available only until 20.0. Which GitLab version WSU runs was not checked.

The generated `.gitlab-ci.yml` should therefore:

- run deploy jobs only on a protected branch or tag with protected variables
- put no secrets in component `inputs`
- leave the job-token allowlist on
- add other projects to it by name when the pipeline must fetch from them

Danger

Sensitive values belong in an external secrets manager. Put no secrets in component `inputs`, and run deploy jobs only on a protected branch or tag with protected variables.

## Where the code lives[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#where-the-code-lives)

The canonical repository is [enterprise-apps/sourdough](https://git.wayne.edu/enterprise-apps/sourdough) on git.wayne.edu. Open issues and merge requests there; tags are made there, by the maintainer, with an approver. GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) is a read-only push mirror of it: nobody pushes to it, and Packagist reads from it. git.wayne.edu is the starter culture now; GitHub is the slice Packagist eats.

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html#related)

- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [Plan format](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html)
