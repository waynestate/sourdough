---
title: "Support and sunset | Sourdough docs"
description: "Who owns each shape, how long a shape is supported, how one is retired one release ahead, and where the PHP floor every shape starts on comes from."
url: https://sourdough.apps.wayne.edu/docs/guides/support.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Support and sunset [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#support-and-sunset)

Starters die quietly when nobody owns them. This page is the policy for Sourdough's five shapes: who owns each one, how long it is supported, and how one is retired without surprising the apps baked from it. Even the best loaf goes stale; the point is to say so before it does.

The facts behind the policy are data, not prose: `resources/shapes.json` holds each shape's owner, status and sunset fields, and `sourdough new --dry-run --json` reports them with the dates the app inherits from CREED.

## Owners [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#owners)

Every shape names an owner role, never a person. Today all five belong to the same role:

| Shape | Owner | Status |
| --- | --- | --- |
| `raw` | Sourdough maintainers | supported |
| `raw-vue` | Sourdough maintainers | supported |
| `laravel-blade` | Sourdough maintainers | supported |
| `laravel-blade-vue` | Sourdough maintainers | supported |
| `laravel-inertia` | Sourdough maintainers | supported |

The Sourdough maintainers are the role that releases Sourdough (Enterprise Applications). The owner keeps the shape green in the smoke and proof jobs, answers its issues, and decides when it is deprecated. A change to the Inertia shape also lands in WELD, which is generated from it, in the same round of work.

## How long a shape is supported [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#how-long-a-shape-is-supported)

Note

The windows below are a proposal and are not confirmed yet. The rules for marking and announcing a deprecation are in force now.

- A shape is supported while `resources/shapes.json` lists it as `supported`. Only the newest Sourdough release gets fixes; an app stays supported by feeding (`sourdough feed`), which its own `sourdough-feed` CI job reminds it to do.
- The app inherits its framework's clock. The Laravel shapes generate the newest Laravel major, and CREED's policy holds that major's end of security support (`sourdough new` prints it; for Laravel 13 it is 2028-03-17). Proposal: Sourdough moves the Laravel shapes to a new major within 90 days of its release, so a fed app is never stuck on a major that is about to end.
- The raw shapes follow PHP alone: their floor comes from CREED (see [The PHP floor](https://sourdough.apps.wayne.edu/docs/guides/support.html#the-php-floor)), and `sourdough new` prints that line's end of security support.
- Proposal: a shape is supported for as long as its framework line has a supported successor that the shape can move to. When it has none, or no one in the owner role can keep it green, it is deprecated as below.

## How a shape is retired [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#how-a-shape-is-retired)

A retirement is announced one release ahead, the same way Preflight retires a component (see [Preflight: deprecating a component](https://preflight.apps.wayne.edu/docs/guides/deprecation.html)):

1. **Deprecate.** In a release, the shape's entry in `resources/shapes.json` changes to `"status": "deprecated"` with `deprecated_in` (this release), `removed_in` (a later release) and `replacement` (the shape to move to). The CHANGELOG gets a `Deprecated:` bullet naming the shape and its replacement, and the upgrading page says what to do.
2. **Say so on every run.** From that release, `sourdough new` prints `Deprecated: The <shape> shape is deprecated since Sourdough <x> and is removed in <y>; move to <shape>.` and `sourdough feed` adds the same line to its notes, so every pipeline's `sourdough-feed` job shows it. Nothing fails because of it.
3. **Keep it bakeable.** The shape keeps baking and feeding for at least one release, and (proposal) at least 6 months after the deprecation release.
4. **Remove.** In the `removed_in` release the generator goes, and the entry changes to `"status": "removed"`. Feeding an app of that shape stops with the same notice, naming the replacement. Published tags are never moved, so an older Sourdough release still bakes the old shape for anyone who needs it.

`tests/ShapeSupportTest.php` holds the catalog to these rules: every shape Sourdough bakes is listed with an owner; a supported shape carries no sunset fields; a deprecated or removed shape names the release that deprecated it, a later release that removes it, and a replacement Sourdough still bakes; and a removed shape is one Sourdough no longer bakes.

## The fields [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#the-fields)

`resources/shapes.json`, one entry per shape:

| Field | Meaning |
| --- | --- |
| `owner` | the owner role (never a person) |
| `status` | `supported`, `deprecated` or `removed` |
| `deprecated_in` | the Sourdough release that deprecated the shape, or `null` |
| `removed_in` | the release that removes it (later than `deprecated_in`), or `null` |
| `replacement` | the shape to move to, or `null` |

`sourdough new <dir> --dry-run --json` adds two objects to the plan:

- `support`: the shape's entry plus `policy` (this page) and `runs_on`, the line the app inherits its clock from (`{"component": "laravel", "version": "13", "security_until": "2028-03-17"}` for a Laravel shape, the PHP floor line for a raw one).
- `php`: the floor the bake writes (`constraint`, `platform`, `versions`), the floor line's `floor_security_until`, and where it came from (`source`, `origin`, `refreshed_on`, `horizon_days`, and `reason` when the fallback was used).

## The PHP floor [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#the-php-floor)

A new app's PHP floor is not a number in a template: Sourdough reads it from CREED's `policy.json` when it bakes, the same file PROCTOR's `php-floor` check reads in the app.

- **Source.** The policy installed with `waynestate/creed` (a Sourdough dependency), or the file the `SOURDOUGH_POLICY` environment variable names.
- **The rule.** As of the policy's own `refreshed_on` date, look 365 days ahead; the floor is the oldest PHP line CREED's reader still calls supported on that later date. A new app never starts on a line that ends inside its first year. The Laravel shapes raise it to the Laravel major's minimum PHP when that is higher. The policy's date is used, not today's clock, so the same Sourdough and the same policy always bake the same loaf, and the floor moves only when the policy does.
- **What it writes.** `require.php` (`^8.3`) and `config.platform.php` (`8.3.0`) in `composer.json`; the `php_versions` matrix in `.gitlab-ci.yml` (every line from the floor up that is supported and has a Docker image in the policy, capped at the Laravel major's maximum PHP); the comment above it; and the README's test-suite line.
- **Fallback.** When the policy cannot be read or does not answer, the bake still works on the pinned values the templates carry (`^8.3`, tested on 8.3, 8.4 and 8.5) and says `pinned fallback` with the reason. A test fails when the policy and the fallback disagree, so the pin is moved with the policy.
- **Moving an app.** When CREED moves the floor, the next Sourdough release bakes the new one, and `sourdough feed` brings it into apps already baked, merged like any template change.

Note

The 365-day horizon is a proposal. CREED is the natural owner of it; until CREED's policy carries one, the number lives in `src/PhpFloor.php`.

## Related [#](https://sourdough.apps.wayne.edu/docs/guides/support.html#related)

- [Keeping apps current](https://sourdough.apps.wayne.edu/docs/guides/keeping-current.html)
- [Upgrading](https://sourdough.apps.wayne.edu/docs/upgrading.html)
- [Templates](https://sourdough.apps.wayne.edu/docs/reference/templates.html)
- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [CREED: reading the policy](https://creed.apps.wayne.edu/docs/guides/reading-the-policy.html)
