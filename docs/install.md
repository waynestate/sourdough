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
Sourdough 0.5.1
```

The CLI is a Symfony Console application named Sourdough, version 0.5.1 (`bin/sourdough`), so `--version` prints the name and the version.

## Update notice

After a command finishes, Sourdough checks Packagist for a newer stable release of itself and, when there is one, prints one line on stderr:

```
sourdough 0.5.0 is out of date: 0.6.0 is the newest release. Update: composer global update waynestate/sourdough
```

The command at the end depends on how this copy was installed: `git pull` for a source checkout, `composer global update waynestate/sourdough` for a Composer global install (Sourdough ships no PHAR, so a PHAR copy points there too), and `composer update waynestate/sourdough` for a project dependency. When the project's `^` or `~` pin excludes the newest release, the line offers a `composer require waynestate/sourdough:^X.Y` of the newest major.minor instead (`--dev` when the pin sits in `require-dev`). When the newest release has a higher major, the line ends with a link to the [upgrading notes](upgrading.md).

The check reads Packagist at most once a day; the result is cached in the user cache directory (`waynestate/sourdough/update-check.json`). It never writes to stdout and never changes the exit code, and it stays silent under CI, when `WAYNE_NO_UPDATE_CHECK=1` is set, or when the command runs with `--quiet`.

## Next steps

- [Quick start](guides/quick-start.md): bake your first app and run its checks.
- [Pick a shape](guides/shapes.md): choose the permutation your app needs.
- [Commands](reference/commands.md): every flag `sourdough new` accepts.
