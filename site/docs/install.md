---
title: "Install | Sourdough docs"
description: "Install Sourdough with composer global require, or scaffold a project without installing it with composer create-project."
url: https://sourdough.apps.wayne.edu/docs/install.html
llms: https://sourdough.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install[#](https://sourdough.apps.wayne.edu/docs/install.html#install)

Sourdough is one PHP command, so it works on Windows, macOS, and Linux.

## Requirements[#](https://sourdough.apps.wayne.edu/docs/install.html#requirements)

- PHP 8.3 or newer (`^8.3` in `composer.json`).
- Composer, for the install lines below.
- No PHP extensions are declared in `composer.json`.

Where the code lives: the canonical repository is on nougat: [enterprise-apps/sourdough](https://nougat.snu.gs/enterprise-apps/sourdough). git.wayne.edu and GitHub ([waynestate/sourdough](https://github.com/waynestate/sourdough)) are read-only mirrors of it. Open issues and merge requests on nougat, not on the mirrors. Nougat is the starter culture; the mirrors are just slices.

## Install[#](https://sourdough.apps.wayne.edu/docs/install.html#install-1)

Two ways in: install the `sourdough` command on your machine, or scaffold without installing.

```bash
composer global require waynestate/sourdough
```

```bash
composer create-project waynestate/sourdough
```

Note

After `composer global require`, the `sourdough` command lives in `composer global config bin-dir --absolute`; put that directory on your PATH.

## Verify[#](https://sourdough.apps.wayne.edu/docs/install.html#verify)

```bash
sourdough --version
```

Expected:

```
Sourdough 0.1.5
```

The CLI is a Symfony Console application named Sourdough, version 0.1.5 (`bin/sourdough`), so `--version` prints the name and the version.

## Next steps[#](https://sourdough.apps.wayne.edu/docs/install.html#next-steps)

- [Quick start](https://sourdough.apps.wayne.edu/docs/guides/quick-start.html): bake your first app and run its checks.
- [Pick a shape](https://sourdough.apps.wayne.edu/docs/guides/shapes.html): choose the permutation your app needs.
- [Commands](https://sourdough.apps.wayne.edu/docs/reference/commands.html): every flag `sourdough new` accepts.
