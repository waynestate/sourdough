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

Where the code lives: the canonical repository is [enterprise-apps/sourdough](https://git.wayne.edu/enterprise-apps/sourdough) on git.wayne.edu. Open issues and merge requests there; tags are made there, by the maintainer, with an approver. GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) is a read-only push mirror of it: nobody pushes to it, and Packagist reads from it. git.wayne.edu is the starter culture now; GitHub is the slice Packagist eats.

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
