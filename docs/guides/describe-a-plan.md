---
title: Describe a plan
description: Describe what your app does in a sentence and let an OpenAI-compatible model plan it against the plan schema.
---

# Describe a plan

Instead of choosing flags, describe what the app does in a sentence and let an OpenAI-compatible model plan it. This page covers the command, the model configuration, the plan format, and the steps a plan becomes; when you have a plan you trust, save it to a file and generate with `--plan`.

## Describe it in a sentence

```bash
sourdough new --describe "A blog with posts and comments"
```

Sourdough asks an OpenAI-compatible model for a plan, validates it against `resources/plan.schema.json`, and prints it. The model kneads the dough, but you decide when the loaf is ready to bake: if the answer is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`). You write the plan yourself and pass it in.

The model does the work but never gates. When an answer fails, Sourdough retries once, telling the model why the previous answer was not valid. If the second answer fails too, the command stops with:

```text
The model could not produce a valid plan. Write the plan yourself and pass it with --plan=<file>.
```

## See the plan without writing anything

```bash
sourdough new --describe "A blog with posts and comments" --plan-only
```

## Pass a ready-made plan

Generation from a plan is not built yet in 0.1. Save the plan to a file and pass it with `--plan`:

```bash
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name. A file ending in `.yaml` or `.yml` is read as YAML, anything else as JSON. A missing file, or a file that does not parse or does not match the schema, is refused with the reason printed.

## The plan stays with the app

The bake saves the plan in the app as `.sourdough/plan.yaml`, next to the manifest, so the app keeps a record of what it was asked to be. It is a valid plan file, so the same loaf bakes again from it:

```bash
sourdough new my-app-copy --plan my-app/.sourdough/plan.yaml
```

Sourdough never rewrites it after the bake: `sourdough feed` and `sourdough manifest --check` leave it alone, and you may edit it as the app grows. Commit it with the manifest. See [The saved plan](../reference/plan-format.md#the-saved-plan).

## Model configuration

`--describe` needs three environment variables (no defaults, no key in code):

| Variable | Meaning |
|---|---|
| `SOURDOUGH_LLM_BASE_URL` | the OpenAI-compatible endpoint, including the API path (usually `/v1`) |
| `SOURDOUGH_LLM_API_KEY` | the API key |
| `SOURDOUGH_LLM_MODEL` | the model name |

Any OpenAI-compatible endpoint works: a hosted API, a local server, or WarriorGPT. Sourdough does not care about the hostname; it only needs the base URL, key, and model.

> [!WARNING]
> `SOURDOUGH_LLM_API_KEY` is a credential. Set it in your environment and keep it out of the repository; Sourdough reads it from the environment and keeps no key in code.

If a variable is missing, the command names it and points you at the alternative:

```text
The SOURDOUGH_LLM_MODEL environment variable is not set. Set it, or pass a ready-made plan with --plan=<file>.
```

## What a plan looks like

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

`permutation`, `parts`, and `name` are required; `models` and `pages` are optional. `parts` is a subset of `sesame`, `helmet`, `proctor`, and `preflight`.

## The steps a plan produces

The plan becomes a list of plain steps, built from the permutation, the target dir, the local runtime flags (`--sail` and `--docker`) and whether ADVISOR is wired in (`--no-advisor` opts out). For the raw shape the steps are:

- `Copy the raw template into <dir>`
- `Replace {{app_name}} and {{app_slug}} in file names and contents`

The raw + Vue shape starts with `Copy the raw template, then the raw-vue template over it, into <dir>` and the same replace step. The Laravel shapes get one step to create the app in the target dir. Permutations that keep ADVISOR get `Wire ADVISOR into the app`; `--sail` adds `Use Sail for the local runtime (compose.yaml, laravel/sail in require-dev)` and `--docker` adds `Use Docker for the local runtime (Dockerfile, compose.yaml, MySQL 8.4)`. The two flags are mutually exclusive: together they are refused with `Choose one of --sail or --docker, not both.`

> [!NOTE]
> `--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP. Combining them with a raw permutation is refused.

In 0.1 all five permutations are generated.

## Related

- [Pick a shape](./shapes.md)
- [The manifest](./manifest.md)
