# Sourdough best practices

Status: draft 1, checked against the design docs and the sibling repos on 2026-10-27.

## What this covers

Sourdough is the starter for WSU applications. `sourdough new <dir>` makes an app for one of five permutations (raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Laravel with Blade and Vue islands, Laravel with Inertia and Vue), with the stack already wired in: SESAME for sign-in, HELMET for the runtime, PROCTOR for checks, PREFLIGHT for CI, a Citadel release script and a `/up` route.

This guide is for two groups:
- WSU developers who generate and then own a Sourdough app.
- The people who maintain Sourdough and its templates.

A starter is a loaf you keep feeding. A project that was fresh on the day it was generated is stale a month later, so most of this guide is about keeping generated apps current as well as making them correctly in the first place.

## How to read the tags

Each practice ends with one tag that says how the stack holds it today.
- `Enforced`: code makes it impossible, or CI fails. The text names what.
- `Checked`: PROCTOR or a CI job warns or fails.
- `Documented`: written down in an ADR or design doc only. Where the design says a test will cover it, the text says so, but the test does not exist yet.
- `Not yet`: a gap. Nothing holds it.

Sourdough itself has no code on its main branch at the time of writing, and a delegate is building step 1 on a branch. So most tags describe the design, not a working tool. Sibling projects (SESAME, HELMET, PROCTOR, PREFLIGHT) do have code, and where a practice leans on them the tag says so.

---

## 1. Generating a project

### 1.1 Make the permutation an explicit, validated input
Take the permutation from flags (`--raw`, `--vue`, `--laravel`, `--inertia`) and refuse combinations that make no sense, such as `--inertia` without `--laravel`. Why: a wrong guess here produces an app that looks fine and fails later, and a clear error costs nothing. Laravel's own installer works the same way: you choose one starter kit and get one coherent result.
Sources: [S1], [S8].
Stack: `Documented`. next-repos.md lists the flag table and says `--inertia` without `--laravel` is an error. Step 1 plans tests for every row and for the invalid combinations.

### 1.2 Offer `--dry-run`, and make it write nothing
`--dry-run` prints the plan (files, packages, commands) and changes nothing on disk. Why: a developer can see what will happen before it happens, and a test can assert that nothing was written.
Sources: [S6].
Stack: `Documented`. Step 1 plans a test that `--dry-run` writes nothing.

### 1.3 Refuse a non-empty target; overwrite only on explicit request
Refuse to generate into a directory that already has files. If you ever allow overwriting, require a `--force` flag, and prompt only when the session is interactive. Why: the worst outcome of a scaffolder is silently damaging work that was already there.
Sources: [S6].
Stack: `Documented`. The refusal is in next-repos.md. There is no `--force` design yet, which is fine as long as nobody adds one without a test.

### 1.4 Use exit codes that scripts can rely on
Return 0 on success and distinct non-zero codes for the main failure modes. Document them. Why: release scripts, CI and agents decide what to do from the code alone.
Sources: [S6], [S8].
Stack: `Not yet`. PROCTOR uses 0 pass, 1 fail, 2 tool error. Symfony Console uses 2 for incorrect usage. Pick one meaning for 2 across the stack before Sourdough ships.

### 1.5 Keep output friendly to people and to machines
Send results to stdout and logs, warnings and errors to stderr. Offer `--json` for the plan. Turn colour off when output is not a terminal, when `NO_COLOR` is set, or with `--no-color`. Only prompt when stdin is a terminal, and provide `--no-input` so CI and agents never hang. Why: the same command is run by a person, a pipeline and an LLM agent, and each needs different output.
Sources: [S6], [S8].
Stack: `Not yet`. Design says "plain argument parsing (as in PROCTOR)" and does not mention any of these.

### 1.6 Test the generator on every permutation, in temp directories
Unit-test argument handling and the plan. Then generate each permutation into a temp directory and compare the result with a stored snapshot. Test commands through a tester class rather than a real terminal. Why: templates rot silently, and a snapshot diff is the cheapest way to see exactly what a change did.
Sources: [S8].
Stack: `Documented`. Step 1 plans tests for every table row and for raw generation into a temp directory. Laravel paths need the network, so their tests run in a pipeline job instead of the unit suite.

---

## 2. Building on Laravel

### 2.1 Build on the official installer and the starter-kit contract
For Laravel paths, wrap the supported entry points instead of copying Laravel's skeleton by hand. The installer accepts a community starter kit published on Packagist (`laravel new my-app --using=vendor/kit`). A kit declares its environment variables in `.env.example` and its setup steps in the `post-create-project-cmd` list of its `composer.json`. Why: you inherit Laravel's upgrades to the skeleton, and developers already know the command.
Sources: [S1], [S3].
Stack: `Documented`, with a conflict. The task description says Laravel starters build on `laravel new --using`. next-repos.md says they wrap `composer create-project laravel/laravel` and then add Inertia, Boost and Sail or Docker. Decide which, and write it in one place.

### 2.2 Switch off kit features that do not fit WSU sign-in
Laravel's kits ship registration, password reset, email verification and two-factor login through Fortify. A WSU app signs in through SESAME. Turn off the features you do not use, and remove their routes from the front end too. In the Vue, React and Svelte kits a leftover reference to a disabled route breaks the build, because routes are generated at build time. Why: a live `/register` route in an app that is supposed to use central sign-in is a security hole and a support burden.
Sources: [S1].
Stack: `Not yet`. Nothing in the design says what Sourdough removes from the Laravel kit. A PROCTOR route check could catch it later.

### 2.3 Install Laravel Boost as a dev dependency and keep its files current
For the Inertia reference path, install Boost with `--dev`, and refresh its generated guideline and skill files with `boost:update`. The Boost docs suggest running it from Composer's `post-update-cmd`. Why: stale agent guidance teaches an agent last year's API.
Sources: [S17].
Stack: `Not yet`. The portfolio says Boost is preinstalled on the reference path. No design says how it is kept fresh or whether its generated files are committed.

---

## 3. Keeping generated apps current

### 3.1 Record where the app came from
Write the template name, template version and the answers given into a file in the generated project (for example `.sourdough.json`). Copier does this with `.copier-answers.yml` and cruft with `.cruft.json`. Why: without it there is nothing to compare against, so no update is possible. Do not hand-edit that file.
Sources: [S4], [S5].
Stack: `Not yet`.

### 3.2 Provide a check that fails CI when an app falls behind
A `sourdough check` (or `--check`) compares the project with the template version it records and exits non-zero when the project is behind. Cruft's `cruft check` does this and is meant for pipelines. Why: this is what makes a starter "fed". Without a check, nobody finds out until the drift is large.
Sources: [S5].
Stack: `Not yet`. ADR-0001 already requires `--check` gates for generated files, and the task brief says a starter must be fed, but next-repos.md does not describe it for Sourdough.

### 3.3 Provide an update that merges, not overwrites
Update by regenerating the project from the new template version, comparing, and applying the difference on top of the developer's changes (a three-way merge). Require a clean git state first. Mark conflicts with inline markers or `.rej` files, and add a hook or CI check that blocks a commit containing leftover markers. Why: Laravel says its kits are code you own and "there is no need to update" them. That is true for a hobby app, not for an app that must stay on supported PHP. A merge keeps the developer's work.
Sources: [S4], [S5], [S1].
Stack: `Not yet`.

### 3.4 Version templates with tags and write migration notes
Tag template releases with semantic versions and put a short "what changed, what to do" entry in the changelog for each. Copier picks the newest tag by default. Why: an update tool is only as good as the history it can read.
Sources: [S4].
Stack: `Not yet`.

### 3.5 Run compatibility CI that generates, installs and tests each permutation
On every change to Sourdough, and on every release of WayneUI, SESAME, HELMET and PROCTOR, generate each permutation, install it and run its own tests and the PROCTOR check. Why: the starter is the place where all the stack libraries meet, so it is where a break shows first.
Sources: [S16].
Stack: `Documented`. ADR-0008 asks for compatibility CI against WayneUI releases. next-repos.md puts the Laravel generation tests in a pipeline job. No pipeline exists yet.

---

## 4. Dependencies and configuration

### 4.1 Commit the lockfile, bound every constraint, audit what is locked
Generated apps commit `composer.lock`, have no `dev-*` or `*` constraints on runtime packages, and run `composer audit --locked` in CI. Note that `composer install` does not audit by default. Why: the lockfile is what makes two builds the same, and the audit is what tells you when a locked package is known to be vulnerable.
Sources: [S3].
Stack: `Checked`. PROCTOR has `lock-committed`, `unbounded-constraints` and `composer-audit` checks, and PREFLIGHT has a `composer-audit` component. Sourdough's job is to wire both into the generated `.gitlab-ci.yml`, which is not built yet.

### 4.2 Take the PHP floor from policy, not from the template
Write the `php` constraint in the generated `composer.json` from CREED's `policy.json` at generation time. Why: the floor moves (to 8.3 on 2027-01-01), and a hard-coded value in a template goes out of date without anyone noticing.
Sources: stack ADR-0003.
Stack: `Not yet`. PROCTOR's `php-floor` check will catch an out-of-date floor in the app, but nothing yet stops the template from generating one.

### 4.3 Pin CI components to a tag, or better a commit
Include PREFLIGHT components with an exact version. GitLab supports a commit SHA, a tag or a branch. Its guidance is to pin to a SHA, or to a release tag from a maintainer you trust, and to avoid `~latest`. Why: an unpinned component changes your pipeline without a commit in your repo.
Sources: [S16].
Stack: `Documented`. next-repos.md says the pipeline includes PREFLIGHT components "pinned by tag". Tags can be moved by whoever owns the project, so decide whether a SHA is worth the extra upkeep.

### 4.4 Keep configuration in the environment and out of the template
Commit `.env.example` with names and safe placeholders, never `.env`. Templates hold no secrets, no real hostnames and no per-environment branches. A good test: could the generated repo be published right now without leaking anything? Why: config that varies per deploy belongs outside the code.
Sources: [S14], [S1].
Stack: `Not yet`. PROCTOR's v0.2 plan includes a banned-pattern rule for `env()` outside config, which would help Laravel apps. Raw PHP has nothing.

### 4.5 Make the development sign-in impossible to ship
The starter signs in with SESAME's `fake` driver locally and `cas` in production. The `fake` driver must refuse to start in production, not fall back quietly. Why: a dev bypass that reaches production is a full authentication bypass.
Sources: [S14].
Stack: `Enforced`. SESAME's `FakeDriver` throws `FakeDriverInProduction` when the environment is `production`. This only protects an app whose environment name is set correctly, so the starter should set it from env and the release script should check it.

### 4.6 Never put secrets in front-end variables
Vite exposes only variables whose names start with `VITE_`, and bakes them into the bundle at build time. Treat everything with that prefix as public. Why: a key in a `VITE_` variable is visible to every visitor.
Sources: [S13].
Stack: `Not yet`. A PROCTOR rule for secret-looking `VITE_` names would be cheap.

### 4.7 Give raw PHP a real way to load Vite assets
Vite does not inject production tags for a backend that renders its own HTML. The backend has to read the manifest and emit the stylesheet, script and preload tags, including imported chunks. Laravel has this built in. Raw PHP needs a small, tested manifest reader that ships with the template. Why: otherwise every raw-PHP team writes its own and gets caching and hashing wrong.
Sources: [S12].
Stack: `Not yet`. Step 3 (`--raw --vue`) is not built. Whether apps build assets at release time or commit them is still open (D-009).

---

## 5. Every developer, every operating system

### 5.1 Ship `.gitattributes` in every generated app
Use `* text=auto eol=lf`, and set `.ps1`, `.bat` and `.cmd` to CRLF. Prefer `.gitattributes` to the per-user `core.autocrlf` setting, because the file travels with the repo and gives every contributor the same rules. Why: Git on Windows converts line endings, and anything that runs on Citadel must be LF.
Sources: [S7].
Stack: `Documented`. ADR-0009 says generated apps carry it from day one. The repos already use it.

### 5.2 Check server-run files for CRLF and for file-name case clashes
Fail when `build.sh`, release scripts or `deployments/*` contain CRLF, and when two paths differ only by case. Why: macOS and Windows ignore case and Citadel does not, so an app can work on every laptop and break on deploy.
Sources: [S7].
Stack: `Not yet`. ADR-0009 says PROCTOR will flag these. The PROCTOR source does not have the checks yet.

### 5.3 Give each OS its own native path
A script that developers run ships as a `.ps1` and a `.sh` that do the same thing, or is one PHP command. Windows never needs bash. Scripts for macOS are written for its stock bash and BSD tools. Prefer the single PHP command when the logic is more than a few lines. Be aware that PowerShell's default policy on Windows clients can block scripts that were downloaded from the internet (Microsoft calls the policy defence in depth, not a security boundary). Scripts the generator writes locally are not affected, but the docs should say what to do if a script is blocked.
Sources: [S15].
Stack: `Documented`. ADR-0009 sets the rule. No generated scripts exist yet to test it on.

### 5.4 Start subprocesses with argument arrays
Whenever Sourdough runs Composer, npm, git or Docker, pass the command as an array of arguments, never as a shell string. Accept paths with spaces and Windows drive letters. Set timeouts. Why: no escaping bugs, no quoting differences between PowerShell and bash, and no injection from a directory name.
Sources: [S9].
Stack: `Documented`. ADR-0009 item 2. No code yet.

### 5.5 Offer more than one local runtime, and be honest about Sail on Windows
Sail is Laravel's Docker development environment. On Windows it needs WSL2 and its `sail` script is shell. Docker's own advice for WSL2 is to keep source code inside the Linux file system, because bind mounts from the Windows side are slower and do not deliver file-change events. So offer: Docker Compose with a Citadel-parity image as the route that works natively on every OS, Sail as an option where it fits, and plain PHP with Composer. Why: a starter that only works for macOS users is not a starter for WSU.
Sources: [S2], [S11].
Stack: `Documented`. ADR-0009 item 4 lists the routes and says Sail is never the only Windows route. The Docker image is SQUIRE's and does not exist yet.

### 5.6 Treat Sail as a development tool
Sail's compose file and images are for local work. Do not reuse them as the production image. Why: they carry dev tools, ports and defaults that a production container should not.
Sources: [S2].
Stack: `Documented`. The design deploys on Citadel's own images and does not use Sail for that.

### 5.7 Build Docker images the safe way
If Sourdough generates a Dockerfile: pin the base image to an exact version (a digest is stronger), use a multi-stage build, run as a non-root user with a fixed UID, add a `.dockerignore`, never leave secrets in a layer, and rebuild regularly with `--pull`. State the CPU architecture. Citadel's images are x86-64 only as far as is known, so Apple Silicon users run them under emulation. Why: images are immutable, so a stale one stays stale.
Sources: [S10].
Stack: `Not yet`. ADR-0009 requires the architecture statement for SQUIRE's image only.

### 5.8 Test on all three systems
Run the generator and the generated app's tests on Windows, macOS and Linux, or run a written smoke test before each minor release. Why: path, newline and shell bugs only appear on the system you did not test.
Sources: [S15], [S11].
Stack: `Not yet`. CI is Linux only, and Windows and macOS runners are an open question (D-023).

---

## 6. Wiring the stack in

### 6.1 Wire the stack in by default, not as an option
Every generated app has SESAME, HELMET, PROCTOR as a dev dependency, a `.gitlab-ci.yml` that includes the PREFLIGHT components, a `/up` route that does not touch the database, and a README that points at the CREED handbook. Why: Laravel's own kits include sign-in, rate limiting and two-factor login out of the box for the same reason. Defaults decide what most apps end up doing.
Sources: [S1].
Stack: `Documented`. The libraries themselves exist (SESAME, HELMET with a health check, PROCTOR, PREFLIGHT templates), but Sourdough does not wire them yet. Until they are tagged, generated `composer.json` files carry a TODO and the generator tests do not install them.

### 6.2 Have the release script run the check, but do not call it the gate
The Citadel release script runs `proctor check` and stops on exit 1. Treat that as a report. A failure in a release script cannot stop bad code from going live on a Quick Update, so the real gates are CI before merge and, later, a platform check. Why: promising a gate that does not hold is worse than having none.
Sources: stack ADR-0002, ADR-0006.
Stack: `Documented`. The limitation is written in ADR-0002. Nothing prevents someone from describing the script as a gate in a README.

### 6.3 Keep the start-up path small and boring
Generated commands should run and exit on their own, with no prompts and no network calls after install. Why: developers, CI and coding agents all run them, and a prompt that waits for input breaks all three.
Sources: [S6].
Stack: `Not yet`.

---

## Gaps for the backlog

1. Decide how Laravel paths are built (`laravel new --using` or `composer create-project`) and write it in one place.
2. Design the update story: `.sourdough.json`, `sourdough check`, a merging `sourdough update`, tagged template releases.
3. Define exit codes, `--json`, `NO_COLOR` and `--no-input` once for the whole stack, and match PROCTOR.
4. Generate the PHP floor from CREED's `policy.json` instead of a template constant.
5. List which Laravel kit features Sourdough removes (registration, password reset, two-factor) and test that they are gone.
6. Add PROCTOR checks for CRLF in server-run files, name-case clashes, secret-looking `VITE_` variables and `env()` outside config.
7. Ship a tested Vite manifest reader for the raw PHP plus Vue path.
8. Write the Docker image rules (pinning, non-root, multi-stage, architecture) before SQUIRE's image or `--docker` is built.
9. Add a compatibility pipeline that generates, installs and tests all five permutations.
10. Get Windows and macOS runners, or write the smoke-test checklist (D-023).
11. Say how Boost's generated files are refreshed and whether they are committed.
12. Decide between pinning PREFLIGHT components by tag or by commit.

---

## Sources

Checked on 2026-10-27.

- [S1] Laravel 12.x docs, Starter Kits: https://laravel.com/docs/12.x/starter-kits
- [S2] Laravel 12.x docs, Sail: https://laravel.com/docs/12.x/sail
- [S3] Composer CLI reference (create-project, audit): https://getcomposer.org/doc/03-cli.md
- [S4] Copier docs, Updating a project: https://copier.readthedocs.io/en/stable/updating/
- [S5] Cruft, keeping projects in sync with Cookiecutter templates: https://cruft.github.io/cruft/
- [S6] Command Line Interface Guidelines: https://clig.dev/
- [S7] Git documentation, gitattributes: https://git-scm.com/docs/gitattributes
- [S8] Symfony Console component: https://symfony.com/doc/current/components/console.html
- [S9] Symfony Process component: https://symfony.com/doc/current/components/process.html
- [S10] Docker docs, Building best practices: https://docs.docker.com/build/building/best-practices/
- [S11] Docker Desktop docs, WSL 2 best practices: https://docs.docker.com/desktop/features/wsl/best-practices/
- [S12] Vite guide, Backend integration: https://vite.dev/guide/backend-integration
- [S13] Vite guide, Env variables and modes: https://vite.dev/guide/env-and-mode
- [S14] The Twelve-Factor App, Config: https://12factor.net/config
- [S15] Microsoft Learn, about_Execution_Policies (PowerShell 7.6): https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_execution_policies
- [S16] GitLab docs, CI/CD components: https://docs.gitlab.com/ci/components/
- [S17] Laravel 12.x docs, Boost: https://laravel.com/docs/12.x/boost

Stack documents used: ADR-0001, ADR-0002, ADR-0003, ADR-0005, ADR-0006, ADR-0008, ADR-0009, design/next-repos.md, design/portfolio.md, design/DECISIONS.md, and the SESAME, HELMET, PROCTOR and PREFLIGHT repos as of 2026-10-27.
