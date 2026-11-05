---
title: "Plan format | Sourdough docs"
description: "The JSON plan format behind resources/plan.schema.json, covering required and optional keys, permutations, parts, models and pages."
url: https://sourdough.apps.wayne.edu/docs/reference/plan-format.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Plan format[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#plan-format)

Reference for the JSON plan that `sourdough new --describe` asks a model for and `sourdough new --plan <file>` reads back. Every plan is validated against `resources/plan.schema.json`. For the flags that produce a plan, see [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html).

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

In 0.1 the `raw`, `laravel-blade`, `laravel-blade-vue` and `laravel-inertia` permutations generate; the generation step for the others says "not available in this release".

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

Generation from a plan is not built yet in 0.1. Save the plan to a file and pass it with `--plan`:

```
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name.

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
| any other permutation | `Create the <label> app in <dir>`, with ` (not available in this release)` appended when the permutation does not generate |
| ADVISOR wired in, permutation generates | `Wire ADVISOR into the app` |
| `--sail` | `Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)` |
| `--docker` | `Use Docker for the local runtime (Dockerfile, compose.yaml, MySQL 8.4)` |

`--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP, and combining either flag with a raw permutation is refused.

## Related[#](https://sourdough.apps.wayne.edu/docs/reference/plan-format.html#related)

- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html)
- [Wiring the stack in](https://sourdough.apps.wayne.edu/docs/guides/wiring-the-stack.html)
