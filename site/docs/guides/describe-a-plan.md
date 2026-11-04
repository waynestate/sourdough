---
title: "Describe a plan | Sourdough docs"
description: "Describe what your app does in a sentence and let an OpenAI-compatible model plan it against the plan schema."
url: https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Describe a plan[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#describe-a-plan)

Instead of choosing flags, describe what the app does in a sentence and let an OpenAI-compatible model plan it. This page covers the command, the model configuration, the plan format, and the steps a plan becomes; when you have a plan you trust, save it to a file and generate with `--plan`.

## Describe it in a sentence[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#describe-it-in-a-sentence)

```bash
sourdough new --describe "A blog with posts and comments"
```

Sourdough asks an OpenAI-compatible model for a plan, validates it against `resources/plan.schema.json`, and prints it. The model kneads the dough, but you decide when the loaf is ready to bake: if the answer is not valid JSON, does not match the schema, or the model gives up after one retry, Sourdough refuses with a message pointing you to `--plan=<file>` (exit `1`). You write the plan yourself and pass it in.

The model does the work but never gates. When an answer fails, Sourdough retries once, telling the model why the previous answer was not valid. If the second answer fails too, the command stops with:

```text
The model could not produce a valid plan. Write the plan yourself and pass it with --plan=<file>.
```

## See the plan without writing anything[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#see-the-plan-without-writing-anything)

```bash
sourdough new --describe "A blog with posts and comments" --plan-only
```

## Pass a ready-made plan[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#pass-a-ready-made-plan)

Generation from a plan is not built yet in 0.1. Save the plan to a file and pass it with `--plan`:

```bash
sourdough new my-app --plan plan.json
```

`--plan` reads the file, validates it against the schema, and continues the normal generation flow using the plan's permutation and name. A missing file, or a file that is not valid JSON matching the schema, is refused with the reason printed.

## Model configuration[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#model-configuration)

`--describe` needs three environment variables (no defaults, no key in code):

| Variable | Meaning |
| --- | --- |
| `SOURDOUGH_LLM_BASE_URL` | the OpenAI-compatible endpoint, including the API path (usually `/v1`) |
| `SOURDOUGH_LLM_API_KEY` | the API key |
| `SOURDOUGH_LLM_MODEL` | the model name |

Any OpenAI-compatible endpoint works: a hosted API, a local server, or WarriorGPT. Sourdough does not care about the hostname; it only needs the base URL, key, and model.

Warning

`SOURDOUGH_LLM_API_KEY` is a credential. Set it in your environment and keep it out of the repository; Sourdough reads it from the environment and keeps no key in code.

If a variable is missing, the command names it and points you at the alternative:

```text
The SOURDOUGH_LLM_MODEL environment variable is not set. Set it, or pass a ready-made plan with --plan=<file>.
```

## What a plan looks like[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#what-a-plan-looks-like)

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

## The steps a plan produces[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#the-steps-a-plan-produces)

The plan becomes a list of plain steps, built from the permutation, the target dir, the local runtime flags (`--sail` and `--docker`) and whether ADVISOR is wired in (`--no-advisor` opts out). For the raw shape the steps are:

- `Copy the raw template into <dir>`
- `Replace {{app_name}} and {{app_slug}} in file names and contents`

The Laravel shapes get a step to create the app in the target dir, suffixed with ` (not available in this release)` for the permutations this release does not generate. Generatable permutations that keep ADVISOR get `Wire ADVISOR into the app`; `--sail` adds `Use Sail for the local runtime` and `--docker` adds `Use Docker for the local runtime`.

Note

`--sail` and `--docker` are for the Laravel paths: the raw paths run on plain PHP. Combining them with a raw permutation is refused.

In 0.1 the raw, Laravel + Blade, Laravel + Blade + Vue islands and Laravel + Inertia/Vue permutations are generated; the generation step for the others says "not available in this release".

## Related[#](https://sourdough.apps.wayne.edu/docs/guides/describe-a-plan.html#related)

- [Pick a shape](https://sourdough.apps.wayne.edu/docs/guides/shapes.html)
- [The manifest](https://sourdough.apps.wayne.edu/docs/guides/manifest.html)
