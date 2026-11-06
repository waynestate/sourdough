---
title: "Testing | Sourdough docs"
description: "Run Sourdough's PHPUnit suite on PHP 8.3 and 8.4, validate the package, and fire the smoke gate that bakes a shape for real."
url: https://sourdough.apps.wayne.edu/docs/guides/testing.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Testing[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#testing)

This page is the proofing bench: how to run Sourdough's own checks day to day, and how to fire the smoke gate that bakes a shape for real before it may be claimed. Like any starter, Sourdough needs regular feeding, or it goes flat. For the wider runbook, continue to [Maintaining](https://sourdough.apps.wayne.edu/docs/maintaining.html).

Note

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://sourdough.apps.wayne.edu/docs/maintaining.html).

## Day-to-day commands[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#day-to-day-commands)

| Task | Command |
| --- | --- |
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Fix code style | `composer fix` |
| Check the Squire copy | `composer squire-copy:check` |
| Refresh the Squire copy | `composer squire-copy:sync`, then commit `templates/squire/` |
| Try a generation | `php bin/sourdough new ./demo --raw --dry-run` |

`composer ci` runs `composer validate --strict`, the tests, Pint, PHPStan, the Squire copy check, the agent-ready files check, the docs site check and `composer audit`.

## With only Docker[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#with-only-docker)

The same lines work in PowerShell and sh:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

## From the repo root[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#from-the-repo-root)

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate --strict
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli php bin/sourdough new /tmp/demo --dry-run
```

## The smoke gate[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#the-smoke-gate)

The smoke gate is the proofing bench: it proofs one shape at a time before that shape may be claimed. It bakes the app with this checkout's own `bin/sourdough new <dir> --no-advisor --no-input` (the CLI under test is the one being shipped), then runs the generated app's own checks:

- `raw`: `composer install` and `composer ci`
- `raw-vue`: the raw steps plus `npm install` and `npm run build`, then a live server that must answer `/` with the built app, `/api/me` with the signed-in user (and `401` without a session), `/nope` with `404` and `/up` with `200`
- `blade`: the raw steps plus `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate` and `php artisan test`
- `blade-vue`: the blade steps plus `npm install` and `npm run build`
- `inertia`: the blade steps plus `npm install`, `npm run build` and `npm run typecheck`

Each step prints `ok`; the first `FAIL` stops the run and shows the command's tail. A shape is claimed in the docs only when its smoke is green.

Run it per OS. The image (`scripts/smoke.Dockerfile`) pins php 8.5, composer 2 and node 22, so the bake is identical everywhere:

linux

```bash
docker build -f scripts/smoke.Dockerfile -t sourdough-smoke:local .
docker run --rm -v "$PWD:/repo:ro" -v /work -e SMOKE_WORK=/work sourdough-smoke:local sh /repo/scripts/smoke-shape.sh <shape>
```

windows

```powershell
.\scripts\smoke-shape.ps1 <shape>
```

The PowerShell wrapper builds the same image once and reuses it. Pass `-WorkDir <dir>` to bind a local folder to `/work` so the scratch copy, the generated app and the per-step logs survive the run. On Windows, `.\scripts\smoke-shape.ps1 <shape>` runs it in one line.

Two environment variables control the scratch space:

- `SMOKE_WORK`: the scratch dir for the copy, the app and the logs. Defaults to a fresh temp dir that is removed on exit.
- `SMOKE_KEEP`: set to `1` to keep the scratch dir behind for inspection.

Note

On CI the gate runs as one job per shape: `smoke` (matrix) on GitHub, `smoke-raw`, `smoke-raw-vue`, `smoke-blade`, `smoke-blade-vue` and `smoke-inertia` on GitLab. All are `allow_failure: true` until the lead has seen every shape green on CI once; flip them to blocking after that proof.

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/testing.html#related)

- [CI](https://sourdough.apps.wayne.edu/docs/guides/ci.html)
- [Pick a shape](https://sourdough.apps.wayne.edu/docs/guides/shapes.html)
- [Same kitchen, many ovens](https://sourdough.apps.wayne.edu/docs/guides/ovens.html)
- [Maintaining](https://sourdough.apps.wayne.edu/docs/maintaining.html)
