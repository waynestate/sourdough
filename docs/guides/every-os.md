---
title: Every developer, every operating system
description: Why Sourdough is one PHP command, so the same workflow works on Windows, macOS and Linux for every developer.
---

# Every developer, every operating system

Sourdough is one PHP command, so it works on Windows, macOS, and Linux: the same `sourdough new` invocation, the same flags, the same output, no matter which OS you sit at. This page covers why that holds and the practices that keep generated apps portable across all three systems. If you are baking an app, start with [Quick start](./quick-start.md) and come back here when a coworker on another OS hits a snag.

## One PHP command, three operating systems

Because the CLI is a single PHP command, there is no per-OS build and no shell dependency in the happy path. Developer scripts follow the same rule: a script that developers run ships as a `.ps1` and a `.sh` that do the same thing, or is one PHP command. Prefer the single PHP command when the logic is more than a few lines. Windows never needs bash or WSL to run the CLI.

## Line endings travel with the repo

Every generated app ships a `.gitattributes` with `* text=auto eol=lf`, and sets `.ps1`, `.bat` and `.cmd` to CRLF. The file travels with the repo and gives every contributor the same rules, which beats the per-user `core.autocrlf` setting. Anything that runs on Citadel must be LF, so the checks fail when `build.sh`, release scripts or `deployments/*` contain CRLF, and when two paths differ only by case: macOS and Windows ignore case and Citadel does not, so an app can work on every laptop and break on deploy.

## Native paths for each OS

Windows gets PowerShell (`.ps1`), which never needs bash or WSL. Linux and macOS get bash (`.sh`), written for macOS's stock bash 3.2 and BSD tools. Be aware that PowerShell's default policy on Windows clients can block scripts that were downloaded from the internet (Microsoft calls the policy defense in depth, not a security boundary). Scripts the generator writes locally are not affected, but the docs should say what to do if a script is blocked. Laravel's own install page gives Windows users `Set-ExecutionPolicy Bypass -Scope Process -Force` followed by downloading a script and passing it to `iex`; that is a pattern WSU docs should not copy.

## Subprocesses as argument arrays

Whenever Sourdough runs Composer, npm, git or Docker, it passes the command as an array of arguments, never as a shell string. That accepts paths with spaces and Windows drive letters, keeps quoting differences between PowerShell and bash out of the picture, and skips shell parsing of a directory name on Linux and macOS. Set timeouts on every subprocess.

Windows has a catch. When the target is a `.bat` or `.cmd` file, Windows starts `cmd.exe`, which splits arguments by its own rules, and callers must escape for it; Composer and npm are usually `.bat` or `.cmd` shims on Windows. PHP's array form of `proc_open` had a command injection on Windows, and its first fix was incomplete; the fixes are in PHP 8.1.29, 8.2.20 and 8.3.8, and the stack's PHP floor already rules out the unpatched builds. So: keep the array form; where you can, run `php.exe` with `composer.phar` and `node.exe` with npm's JavaScript entry point instead of the shims; never pass user-chosen text (an app name, a path) to a shim; and validate names before they reach a command line.

## More than one local runtime

Offer more than one local runtime, and be honest about Sail on Windows. Sail is Laravel's Docker development environment; on Windows it needs WSL2 and its `sail` script is shell. Docker's own advice for WSL2 is to keep source code inside the Linux file system, because bind mounts from the Windows side are slower and do not deliver file-change events. So the routes are: Docker Compose on the app's own images (`--docker`) as the route that works natively on every OS, Sail as an option where it fits, and plain PHP with Composer. When "it works here" has to mean "it works on Citadel", add [Parity](https://git.wayne.edu/enterprise-apps/parity): one program for Windows, macOS and Linux, on Podman or Docker, that runs the same folder on Citadel's own PHP image, Apache, `php.ini`, proxy and MySQL 5.6.51. It is a separate install, never a requirement, and every baked README's "Run it like Citadel" says how. Laravel's docs also offer Herd, a native macOS and Windows environment with PHP, Nginx, Node and the `laravel` CLI.

> [!NOTE]
> Treat Sail as a development tool. Its compose file and images are for local work; do not reuse them as the production image, because they carry dev tools, ports and defaults that a production container should not.

## Docker images the safe way

If Sourdough generates a Dockerfile: pin the base image to an exact version (a digest is stronger), use a multi-stage build, run as a non-root user with a fixed UID, add a `.dockerignore`, never leave secrets in a layer, and rebuild regularly with `--pull`. State the CPU architecture: the base images behind `--docker` are pinned by manifest-list digest, so one pin resolves natively on amd64 and arm64 and Apple Silicon users do not run under emulation. Pinning to a digest also opts you out of automatic security fixes, so pair the pin with a scheduled update.

## Test on all three systems

Run the generator and the generated app's tests on Windows, macOS and Linux, or run a written smoke test before each minor release. Path, newline and shell bugs only appear on the system you did not test.

## Templates as archives

Fetch templates as Composer dist archives, not with `git clone --recurse-submodules`. A carriage return in a submodule path turned into code execution during a recursive clone in a Git vulnerability, fixed across many releases and listed as exploited by CISA. Name the Git floor for developers and keep Git current. Composer 2.10 no longer falls back from dist to source by default, which helps here.

## Related

- [Pick a shape](./shapes.md)
- [Building on Laravel](./laravel.md)
- [Testing](./testing.md)
- [CI](./ci.md)
