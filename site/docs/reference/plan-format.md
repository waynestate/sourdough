---
title: "Plan format | Sourdough docs"
description: "The plan format behind resources/plan.schema.json, covering required and optional keys, permutations, parts, models, pages, and the .sourdough/plan.yaml every bake saves."
url: https://sourdough.apps.wayne.edu/docs/reference/plan-format.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Plan format[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#plan-format)

Reference for the plan that `sourdough new --describe` asks a model for, `sourdough new --plan <file>` reads back (as JSON, or as YAML), and every bake saves in the app as `.sourdough/plan.yaml`. Every plan is validated against `resources/plan.schema.json`. For the flags that produce a plan, see [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html).

## The plan object[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#the-plan-object)

A plan is a small JSON object:

```json
{
    "permutation": "raw",
    "parts": ["sesame", "helmet"],
    "name": "my-app",
    "models": [
        {
            "name": "Post",
            "fields": [
                { "name": "title", "type": "string" },
                { "name": "body", "type": "text" }
            ]
        }
    ],
    "pages": ["/", "/posts"]
}
```

`permutation`, `parts`, and `name` are required; `models` and `pages` are optional.

| Key | Required | Values |
| --- | --- | --- |
| `permutation` | yes | one of the five permutation values below |
| `parts` | yes | a subset of `sesame`, `helmet`, `proctor`, and `preflight` |
| `name` | yes | the app name |
| `models` | no | a list of model objects |
| `pages` | no | a list of page paths, such as `/` and `/posts` |

When a plan is printed as JSON, empty `models` and `pages` are omitted.

### Permutation values[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#permutation-values)

| Value | Flags | Name |
| --- | --- | --- |
| `raw` | (none) or `--raw` | Raw PHP with WayneUI's standalone path |
| `raw-vue` | `--raw --vue` | Raw PHP + Vue SPA over JSON |
| `laravel-blade` | `--laravel` | Laravel + Blade |
| `laravel-blade-vue` | `--laravel --vue` | Laravel + Blade + Vue islands |
| `laravel-inertia` | `--laravel --inertia` (or `--inertia`) | Laravel + Inertia/Vue |

Flag resolution, from `src/Permutation.php`:

- `--inertia` implies `--laravel`: Inertia here means Laravel.
- `--raw` and `--laravel` are mutually exclusive: pick one base.
- `--vue` is not needed with `--inertia`: Inertia already means Vue.
- Combinations that make no sense are refused with an error that names the five shapes and their flags.

In 0.1 all five permutations generate.

### Parts[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#parts)

From `src/Part.php`, the stack parts a Sourdough app is wired with:

| Value | Part |
| --- | --- |
| `sesame` | SESAME (auth) |
| `helmet` | HELMET (baseline runtime) |
| `proctor` | PROCTOR (checks) |
| `preflight` | the Preflight CI components |

A plan's `parts` is a subset of these.

### Models[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#models)

Each model object has a `name` and a `fields` list; each field has a `name` and a `type`, both strings, as in the example above.

### Pages[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#pages)

A list of strings, such as `["/", "/posts"]`.

## The saved plan[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#the-saved-plan)

Every bake saves its plan in the app as `.sourdough/plan.yaml`, next to the manifest (ADR-0014, decision 3): the recipe card taped inside the jar's lid. It is written after the bake succeeds, on both paths that bake:

| How you baked | What the saved plan holds |
| --- | --- |
| `--plan <file>` | that plan, with the name the app was baked with (`--name` wins over the plan's `name`) |
| flags (`--raw`, `--laravel`, `--vue`, `--inertia`) | the shape, the name, and all four parts, because every shape wires all four in |

`--dry-run` and a refused bake write nothing, so they save no plan. `--describe` prints a plan and writes nothing (generation from a description is not built yet), so it saves none either; pass its plan with `--plan` and that bake saves it. The plan from the example above is saved as:

```yaml
# The plan this app was baked from, saved by sourdough new (ADR-0014).
# It matches Sourdough's plan schema, so it bakes the same shape again:
#   sourdough new <dir> --plan=.sourdough/plan.yaml
# Sourdough never rewrites it after the bake: sourdough feed and
# sourdough manifest --check leave it alone. Commit it with the app.

permutation: raw
parts:
  - sesame
  - helmet
name: my-app
models:
  - name: Post
    fields:
      - { name: title, type: string }
      - { name: body, type: text }
pages:
  - /
  - /posts
```

The keys are the plan object's, in the same order, and the comment lines are YAML comments, so the file still matches the schema (which allows no extra keys). `--plan` reads a file ending in `.yaml` or `.yml` as YAML and anything else as JSON, through the same schema, so the saved plan bakes the same loaf again:

```bash
sourdough new my-app-copy --plan my-app/.sourdough/plan.yaml
```

### Neither managed nor seeded[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#neither-managed-nor-seeded)

The saved plan is not in the manifest. The manifest lists the files the template wrote, and the plan is not template output: it is what you asked for. Like the manifest itself and `.sourdough/base/`, it is Sourdough's record about the app, not a file of the app, so it carries neither class:

- `sourdough manifest --check` never reports it, however you edit it.
- `sourdough feed` never reads, rewrites or removes it. Feed bakes from the shape and answers in the manifest, so an edited plan changes nothing a feed does, and an app baked before the plan was saved (Sourdough 0.1.7 and earlier) gets no plan written for it: feed does not guess at what nobody wrote down.

Edit it as the app grows (a new model, a new page), so it stays the record of what the app is for, and commit it with the manifest.

## Getting a plan[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#getting-a-plan)

Instead of choosing flags, describe what the app does and let a model plan it:

```
sourdough new --describe "A blog with posts and comments"
```

Sourdough asks an OpenAI-compatible model for a plan, validates it against `resources/plan.schema.json`, and prints it. The model kneads the dough, but you decide when the loaf is ready to bake: if the answer is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`). You write the plan yourself and pass it in.

To see the plan as JSON without writing anything:

```
sourdough new --describe "A blog with posts and comments" --plan-only
```

`--describe` stops at the plan: baking straight from a description is not built yet. Save the plan to a file and pass it with `--plan`, which bakes it:

```
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name. A YAML plan (`.yaml` or `.yml`) works the same way, and the bake saves the plan as [the saved plan](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#the-saved-plan).

### Model configuration[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#model-configuration)

`--describe` needs three environment variables (no defaults, no key in code):

| Variable | Meaning |
| --- | --- |
| `SOURDOUGH_LLM_BASE_URL` | the OpenAI-compatible endpoint, including the API path (usually `/v1`) |
| `SOURDOUGH_LLM_API_KEY` | the API key |
| `SOURDOUGH_LLM_MODEL` | the model name |

Any OpenAI-compatible endpoint works: a hosted API, a local server, or WarriorGPT. Sourdough does not care about the hostname; it only needs the base URL, key, and model.

Danger

`SOURDOUGH_LLM_API_KEY` is a secret. It has no default, and there is no key in code: set it in the environment for the run.

## The steps plan[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#the-steps-plan)

The app plan is the what: permutation, parts, name, models, pages. The steps plan in `src/Plan.php` is the how, that is, what `sourdough new` will do, and `--dry-run` prints it. It carries the permutation, the target dir, and a list of steps:

| Condition | Step |
| --- | --- |
| raw permutation | `Copy the raw template into <dir>` |
| raw permutation | `Replace {{app_name}} and {{app_slug}} in file names and contents` |
| raw-vue permutation | `Copy the raw template, then the raw-vue template over it, into <dir>` |
| raw-vue permutation | `Replace {{app_name}} and {{app_slug}} in file names and contents` |
| any Laravel permutation | `Create the <label> app in <dir>` |
| ADVISOR wired in | `Wire ADVISOR into the app` |
| `--sail` | `Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)` |
| `--docker` | `Use Docker for the local runtime (Dockerfile, compose.yaml, MySQL 8.4)` |

`--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP, and combining either flag with a raw permutation is refused.

## Related[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#related)

- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
