---
title: Install
description: Install Sourdough with composer global require, or scaffold a project without installing it with composer create-project.
---

# Install

Sourdough is one PHP command, so it works on Windows, macOS, and Linux.

## Requirements

- PHP 8.3 or newer (`^8.3` in `composer.json`).
- Composer, for the install lines below.
- No PHP extensions are declared in `composer.json`.

Where the code lives: the canonical repository is on nougat: [enterprise-apps/sourdough](https://nougat.snu.gs/enterprise-apps/sourdough). git.wayne.edu and GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) are read-only mirrors of it. Open issues and merge requests on nougat, not on the mirrors. Nougat is the starter culture; the mirrors are just slices.

## Install

Two ways in: install the `sourdough` command on your machine, or scaffold without installing.

```bash tab="composer global require"
composer global require waynestate/sourdough
```

```bash tab="composer create-project"
composer create-project waynestate/sourdough
```

> [!NOTE]
> After `composer global require`, the `sourdough` command lives in `composer global config bin-dir --absolute`; put that directory on your PATH.

## Verify

```bash tab="sourdough --version"
sourdough --version
```

Expected:

```
Sourdough 0.1.6
```

The CLI is a Symfony Console application named Sourdough, version 0.1.6 (`bin/sourdough`), so `--version` prints the name and the version.

## Next steps

- [Quick start](guides/quick-start.md): bake your first app and run its checks.
- [Pick a shape](guides/shapes.md): choose the permutation your app needs.
- [Commands](reference/commands.md): every flag `sourdough new` accepts.
