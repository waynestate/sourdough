# Sourdough best practices

Status: draft 2. Draft 1 was checked against the design docs and the sibling repos on 2026-10-27. A second pass the same day re-checked it against the web (versions, advisories, prior art, official guidance). Items added or changed in that pass say "(second pass)" or are listed in "Second pass (2026-10-27)" near the end.

## What this covers

Sourdough is the starter for WSU applications. `sourdough new <dir>` makes an app for one of five permutations (raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Laravel with Blade and Vue islands, Laravel with Inertia and Vue), with the stack already wired in: SESAME for sign-in, HELMET for the runtime, PROCTOR for checks, Preflight for CI, a Citadel release script and a `/up` route.

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

Sourdough itself has no code on its main branch at the time of writing, and a delegate is building step 1 on a branch. So most tags describe the design, not a working tool. Sibling projects (SESAME, HELMET, PROCTOR, Preflight) do have code, and where a practice leans on them the tag says so.

---

## 1. Generating a project

### 1.1 Make the permutation an explicit, validated input
Take the permutation from flags (`--raw`, `--vue`, `--laravel`, `--inertia`) and refuse combinations that make no sense, such as `--inertia` without `--laravel`. Why: a wrong guess here produces an app that looks fine and fails later, and a clear error costs nothing. Laravel's own installer works the same way: you choose one starter kit and get one coherent result.
Sources: [S1], [S8].
Stack: `Documented`. the stack design notes lists the flag table and says `--inertia` without `--laravel` is an error. Step 1 plans tests for every row and for the invalid combinations.

### 1.2 Offer `--dry-run`, and make it write nothing
`--dry-run` prints the plan (files, packages, commands) and changes nothing on disk. Why: a developer can see what will happen before it happens, and a test can assert that nothing was written.
Sources: [S6].
Stack: `Documented`. Step 1 plans a test that `--dry-run` writes nothing.

### 1.3 Refuse a non-empty target; overwrite only on explicit request
Refuse to generate into a directory that already has files. If you ever allow overwriting, require a `--force` flag, and prompt only when the session is interactive. Why: the worst outcome of a scaffolder is silently damaging work that was already there.
Sources: [S6].
Stack: `Documented`. The refusal is in the stack design notes. There is no `--force` design yet, which is fine as long as nobody adds one without a test.

### 1.4 Use exit codes that scripts can rely on
Return 0 on success and distinct non-zero codes for the main failure modes. Document them. Why: release scripts, CI and agents decide what to do from the code alone.
Sources: [S6], [S8].
Stack: `Not yet`. PROCTOR uses 0 pass, 1 fail, 2 tool error. Symfony Console uses 2 for incorrect usage. Pick one meaning for 2 across the stack before Sourdough ships.
Second pass: the tools Sourdough drives disagree as well. Copier's `check-update --quiet` exits 2 when an update is available, and Copier exits 4 when a template uses unsafe features without `--trust` [S4], [S19]. Composer 2.10 changed `composer audit` to exit only 0 or 1 [S20]. So "2" already means three different things in the tools around Sourdough.

### 1.5 Keep output friendly to people and to machines
Send results to stdout and logs, warnings and errors to stderr. Offer `--json` for the plan. Turn colour off when output is not a terminal, when `NO_COLOR` is set, or with `--no-color`. Only prompt when stdin is a terminal, and provide `--no-input` so CI and agents never hang. Why: the same command is run by a person, a pipeline and an LLM agent, and each needs different output.
Sources: [S6], [S8].
Stack: `Not yet`. Design says "plain argument parsing (as in PROCTOR)" and does not mention any of these.

### 1.6 Test the generator on every permutation, in temp directories
Unit-test argument handling and the plan. Then generate each permutation into a temp directory and compare the result with a stored snapshot. Test commands through a tester class rather than a real terminal. Why: templates rot silently, and a snapshot diff is the cheapest way to see exactly what a change did.
Sources: [S8].
Stack: `Documented`. Step 1 plans tests for every table row and for raw generation into a temp directory. Laravel paths need the network, so their tests run in a pipeline job instead of the unit suite.

### 1.7 Treat names, paths and templates as untrusted input (second pass)
Check `<dir>` and any app or vendor name against an allow-list (lower-case letters, digits, hyphens, and Composer's package-name pattern) before the value reaches a file name, a `composer.json` or a command line. Refuse any write whose resolved path falls outside the target directory. Keep templates as data: placeholder substitution only, with no code in a template running during generation. Why: scaffolders have turned template content into code execution before. Backstage's scaffolder let anyone who could edit a registered template run code on the host (CVE-2023-35926, fixed in 1.15.0). Copier refuses templates that use tasks, migrations or Jinja extensions unless the caller passes `--trust`. Composer's own 2026 fixes (package-name validation and bin-path traversal protection in 2.10.2, another path traversal in 2.10.3) show the same class of bug in a PHP tool.
Sources: [S18], [S19], [S20].
Stack: `Enforced` for names: `RawGenerator::assertValidName()` checks every name against an allow-list (letter or number first; then letters, numbers, spaces, dots, dashes and underscores; at most 63 characters) before generation touches the disk, and the test suite generates with hostile names (quotes, backslash, newline, NUL byte, `{{app_slug}}`, path traversal, 64 characters, empty, dashes only, leading dash or dot, a Unicode look-alike), asserting the exception and an absent or empty target dir. Paths stay inside the target by construction: the slug is `[a-z0-9-]` only and every write path is built under the target dir.

### 1.8 Make the "new app" page and the CLI one engine (second pass)
The page should call the same plan code as `sourdough new --dry-run --json`, show the equivalent command so a developer can repeat it, validate on the server with the rules in 1.7, and return the same archive the CLI would write. A snapshot test should compare the two. Why: two generators drift apart, and a page that quietly differs from the CLI produces apps nobody can reproduce. Spring Initializr is the model the portfolio names: it is open source under Apache 2.0, can be run as an organisation's own instance, exposes generation over HTTP, and the Spring CLI can be pointed at a custom server (from the project's reference docs, seen in search results; the page was not opened). The page must also pass the stack's accessibility gate (see 6.4).
Sources: [S21].
Stack: `Documented` for the page itself (the stack's design names the start.spring.io pattern). `Not yet` for the shared-engine rule. Only brand assets exist under `site/`.

---

## 2. Building on Laravel

### 2.1 Build on the official installer and the starter-kit contract
For Laravel paths, wrap the supported entry points instead of copying Laravel's skeleton by hand. The installer accepts a community starter kit published on Packagist (`laravel new my-app --using=vendor/kit`). A kit declares its environment variables in `.env.example` and its setup steps in the `post-create-project-cmd` list of its `composer.json`. Why: you inherit Laravel's upgrades to the skeleton, and developers already know the command.
Second pass: draft 1 cited the 12.x docs. Laravel 13 (released 2026-03-17, PHP 8.3 to 8.5) is current. The first-party kits are now React, Svelte, Vue and Livewire (Inertia 3 for the first three), each with optional teams and a WorkOS AuthKit variant. There is no plain Blade kit, so the `--laravel` (Blade) and Blade-plus-Vue-islands permutations are a bare Laravel install plus Sourdough's own layer, not a vendor kit. The `--using` and `post-create-project-cmd` contract quoted above is unchanged in 13 [S47].
Sources: [S1], [S3], [S47].
Stack: `Documented`, with a conflict. The project brief says Laravel starters build on `laravel new --using`. the stack design notes says they wrap `composer create-project laravel/laravel` and then add Inertia, Boost and Sail or Docker. Decide which, and write it in one place.

### 2.2 Switch off kit features that do not fit WSU sign-in
Laravel's kits ship registration, password reset, email verification and two-factor login through Fortify. A WSU app signs in through SESAME. Turn off the features you do not use, and remove their routes from the front end too. In the Vue, React and Svelte kits a leftover reference to a disabled route breaks the build, because routes are generated at build time. Why: a live `/register` route in an app that is supposed to use central sign-in is a security hole and a support burden.
Second pass: the Laravel 13 docs confirm all of this [S47]. Registration, password reset, email verification and two-factor are enabled by default in `config/fortify.php`, and a disabled feature still breaks the front-end build if its Wayfinder route is referenced. The kits also set a login rate limit (5 a minute per email and IP in the docs' example). The WorkOS variants would add a second hosted identity provider beside SESAME, so leave them out.
Sources: [S1], [S47].
Stack: `Not yet`. Nothing in the design says what Sourdough removes from the Laravel kit. A PROCTOR route check could catch it later.

### 2.3 Install Laravel Boost as a dev dependency and keep its files current
For the Inertia reference path, install Boost with `--dev`, and refresh its generated guideline and skill files with `boost:update`. The Boost docs suggest running it from Composer's `post-update-cmd`. Why: stale agent guidance teaches an agent last year's API.
Second pass: Boost runs on Laravel 10 to 13 with PHP 8.1 or higher [S48], [S49]. Its docs say the generated `.mcp.json`, guideline files (`CLAUDE.md`, `AGENTS.md`, `junie/`) and `boost.json` may go in `.gitignore`, because `boost:install` and `boost:update` regenerate them. The `.ai/rules` directory (project rules recorded by agents) should be committed. Boost's MCP server can run database queries and code through Tinker, so the development `.env` must point at a local database and never at the shared server. Untested: check that the `post-update-cmd` entry does not break `composer update --no-dev`, where Boost is absent.
Sources: [S17], [S48], [S49].
Stack: `Not yet`. The portfolio says Boost is preinstalled on the reference path. No design says how it is kept fresh. Question 11 in the backlog is half answered: the vendor says which files to ignore and which to commit, and Sourdough still has to choose.

### 2.4 Generate the newest Laravel major, and write down each template's support window (second pass)
Laravel gives each major 18 months of bug fixes and two years of security fixes. From its release notes: 11 reached the end of security fixes on 2026-03-12; 12 (PHP 8.2 to 8.5) gets security fixes until 2027-02-24; 13 (PHP 8.3 to 8.5) until 2028-03-17. `composer create-project laravel/laravel` picks the newest major, which is right, but the generator's tests should name the major they expect, so a new release cannot change output unseen. Print the end-of-security date in the generated README and in `sourdough check` (see 3.2). Why: the app inherits its framework's clock. Laravel 13 needs PHP 8.3, which is also the stack's floor from 2027-01-01 (php.net: 8.2 security support ends 2026-12-31).
Sources: [S22], [S23].
Stack: `Not yet`.

### 2.5 Trust the HA-Proxy, not every sender (second pass)
Citadel ends TLS at HA-Proxy, so the app sees plain HTTP. Laravel's docs say that behind a balancer that ends TLS, the `url` helper can produce `http` links until `trustProxies` is set in `bootstrap/app.php`. The docs also show `at: '*'`, meant for clouds where balancer addresses are unknown. The generated app should list the proxy addresses (from env) and also set `trustHosts`. Laravel 13 formalised `PreventRequestForgery`, which adds origin-aware checks; a wrong scheme or host seen by the app could plausibly trip it (my inference, not tested). Raw PHP gets the same behaviour from HELMET.
Sources: [S24], [S22].
Stack: `Documented`. the stack design notes has HELMET's Laravel adapter set `trustProxies(at:)` from HELMET's list. Whether Citadel publishes its proxy addresses was not checked.

### 2.6 Make the Laravel templates work on the shared MySQL 5.6, or say they cannot (second pass)
Laravel 13's database docs list MySQL 5.7+ and MariaDB 10.3+ as supported [S25]. The shared server is MySQL 5.6, which left extended support on 2021-02-28 (5.7 on 2023-10-31, 8.0 on 2026-04-30; 8.4 LTS is supported) [S26]. So the server is below Laravel's own minimum. The MySQL manual gives the practical limit: an index key prefix is 767 bytes for REDUNDANT and COMPACT tables, which is 191 characters in utf8mb4 [S27]. Laravel's default string column is 255 characters, so a unique index on it can fail with error 1071 unless the server uses DYNAMIC rows with large prefixes (the Citadel server's settings were not checked). The long-known Laravel workaround is `Schema::defaultStringLength(191)` in a service provider (seen in a Laracasts thread via search). Sail's MySQL is current, so a developer's migrations can pass locally and fail on deploy.
So: (1) the Laravel templates set the 191 default and avoid JSON columns, as Squire's S18 already asks; (2) CI runs the migrations against a 5.6 server in a service container (image availability and CPU architecture not checked); (3) the generated README says Laravel is outside the vendor's support on this database until the MySQL timeline question is settled. Open question for the stack: the PHP rule bans EOL PHP, and the database has no matching rule yet.
Sources: [S25], [S26], [S27].
Stack: `Not yet`. The MySQL timeline question is still open. Squire's S18 is `Documented`.

---

## 3. Keeping generated apps current

### 3.1 Record where the app came from
Write the template name, template version and the answers given into a file in the generated project (for example `.sourdough.json`). Copier does this with `.copier-answers.yml` and cruft with `.cruft.json`. Why: without it there is nothing to compare against, so no update is possible. Do not hand-edit that file.
Sources: [S4], [S5].
Stack: `Not yet`.

### 3.2 Provide a check that fails CI when an app falls behind
A `sourdough check` (or `--check`) compares the project with the template version it records and exits non-zero when the project is behind. Cruft's `cruft check` does this and is meant for pipelines. Why: this is what makes a starter "fed". Without a check, nobody finds out until the drift is large.
Second pass: Copier has no `check` command, but `copier check-update` does the same job. It supports `--output-format json`, and `--quiet` makes it exit 2 when an update exists [S4]. That is a second model for the shape of `sourdough check`. The cruft project page showed no release or commit dates when fetched, so its maintenance status is unconfirmed.
Sources: [S5], [S4].
Stack: `Not yet`. The core-plus-adapters rule already requires `--check` gates for generated files, and the project brief says a starter must be fed, but the stack design notes does not describe it for Sourdough.

### 3.3 Provide an update that merges, not overwrites
Update by regenerating the project from the new template version, comparing, and applying the difference on top of the developer's changes (a three-way merge). Require a clean git state first. (Second pass: Copier recommends a clean `git status` but does not enforce it, so Sourdough should enforce it.) Mark conflicts with inline markers or `.rej` files, and add a hook or CI check that blocks a commit containing leftover markers. Why: Laravel says its kits are code you own and "there is no need to update" them. That is true for a hobby app, not for an app that must stay on supported PHP. A merge keeps the developer's work.
Sources: [S4], [S5], [S1].
Stack: `Not yet`.

### 3.4 Version templates with tags and write migration notes
Tag template releases with semantic versions and put a short "what changed, what to do" entry in the changelog for each. Copier picks the newest tag by default. Why: an update tool is only as good as the history it can read.
Sources: [S4].
Stack: `Not yet`.

### 3.5 Run compatibility CI that generates, installs and tests each permutation
On every change to Sourdough, and on every release of WayneUI, SESAME, HELMET and PROCTOR, generate each permutation, install it and run its own tests and the PROCTOR check. Why: the starter is the place where all the stack libraries meet, so it is where a break shows first.
Second pass: also run it on a schedule (nightly), not only on changes, and cover PHP 8.4 and 8.5 and the current and previous Laravel major (see 2.4 and 4.13). A starter with no commits still rots, because the things it depends on keep moving.
Sources: [S16].
Stack: `Documented`. The one-repo-per-project decision asks for compatibility CI against WayneUI releases. the stack design notes puts the Laravel generation tests in a pipeline job. No pipeline exists yet.

### 3.6 Keep the template thin; put shared behaviour in libraries (second pass)
Other universities ship starters as a Composer package plus an install command. Cornell's CIT Custom Development publishes a "starter kit and library": a new site installs it with `composer require` and `php artisan starterkit:install`, and an existing site can require it without publishing the project files (the README warns that publishing overwrites files). The README describes no update process and its releases list was empty when fetched, which is the gap `sourdough check` is meant to fill. Northwestern's system development team also publishes a Laravel starter on Packagist; the repository URL I tried returned 404, so only its Packagist listing (via search) was seen. Why: code that lives in SESAME, HELMET and PROCTOR updates with `composer update`, so only the glue lives in the template and the merge in 3.3 stays small. This is the "core plus adapters" rule applied to the starter itself.
Sources: [S28], [S29].
Stack: `Not yet`. Nothing yet limits what the templates may contain.

### 3.7 Give every template an owner, a support window and a sunset path (second pass)
Starters die quietly. The React team deprecated Create React App on 2025-02-14, saying it had no active maintainers. Installing it now prints a warning that points at current frameworks. Laravel replaced Breeze and Jetstream with new kits when Laravel 12 shipped (Feb 2025), and search summaries say the old kits stopped getting new features. One September 2026 article says Breeze still gets compatibility releases, so "abandoned" would be too strong. Sourdough should name an owner for each permutation, tie its window to the framework and PHP end dates (2.4), run the scheduled build in 3.5, and have `sourdough check` print a deprecation notice with the migration note from 3.4.
Sources: [S30], [S31].
Stack: `Not yet`.

---

## 4. Dependencies and configuration

### 4.1 Commit the lockfile, bound every constraint, audit what is locked
Generated apps commit `composer.lock`, have no `dev-*` or `*` constraints on runtime packages, and run `composer audit --locked` in CI. Why: the lockfile is what makes two builds the same, and the audit is what tells you when a locked package is known to be vulnerable.
Second pass, correction: draft 1 said `composer install` does not audit by default. Composer's CLI page does not say either way, so that claim is unverified. Keep the explicit `composer audit --locked` step. Composer's own behaviour has also changed [S20], [S32]. Version 2.9.0 (2025-11-13) blocks packages with known advisories on update (`audit.block-insecure`, on by default). Version 2.10.0 (2026-05-28) adds a `policy` block covering advisories, abandoned packages, malware and a release cooldown; malware blocking is on by default; the old `audit` block is deprecated; and `composer audit` now exits only 0 or 1. The generated `composer.json` should set `policy` explicitly rather than rely on defaults.
Sources: [S3], [S20], [S32].
Stack: `Checked`. PROCTOR has `lock-committed`, `unbounded-constraints` and `composer-audit` checks, and Preflight has a `composer-audit` component. Sourdough's job is to wire both into the generated `.gitlab-ci.yml`, which is not built yet. Read in the second pass: PROCTOR's audit check runs `composer audit --format=json --locked` and decides from the JSON on stdout, not from the exit code, so the 2.10 exit-code change should not hurt it. It has not been run against 2.10 output, which can include malware and cooldown findings. `lock-committed` looks only for `composer.lock`; nothing checks `package-lock.json` (see 4.10).

### 4.2 Take the PHP floor from policy, not from the template
Write the `php` constraint in the generated `composer.json` from CREED's `policy.json` at generation time. Why: the floor moves (to 8.3 on 2027-01-01), and a hard-coded value in a template goes out of date without anyone noticing.
Sources: the stack's version policy.
Stack: `Not yet`. PROCTOR's `php-floor` check will catch an out-of-date floor in the app, but nothing yet stops the template from generating one.

### 4.3 Pin CI components to a tag, or better a commit
Include Preflight components with an exact version. GitLab supports a commit SHA, a tag or a branch. Its guidance is to pin to a SHA, or to a release tag from a maintainer you trust, and to avoid `~latest`. Why: an unpinned component changes your pipeline without a commit in your repo.
Second pass: re-checked against the GitLab page. It says to pin to a commit SHA (preferred) or a release tag, to trust tags only from a maintainer you trust, to avoid `latest`, and to review changes before moving a pin. It also says component inputs are validated and variables are not, so pass settings as `inputs`. Components have been generally available since GitLab 17.0.
Sources: [S16].
Stack: `Documented`. the stack design notes says the pipeline includes Preflight components "pinned by tag". Tags can be moved by whoever owns the project, so decide whether a SHA is worth the extra upkeep.

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
Stack: `Not yet`. Step 3 (`--raw --vue`) is not built. Whether apps build assets at release time or commit them is still an open question.

### 4.8 Run a current Composer in CI and in the build (second pass)
Composer had a run of security fixes in 2026 [S20], [S33]. Version 2.9.6 (2026-04-14) fixed two Perforce command injections (CVE-2026-40261 and CVE-2026-40176), and Packagist stopped publishing Perforce source metadata on 2026-04-10. Version 2.10.2 (2026-07-01) added package-name validation and bin-path traversal protection. Version 2.10.3 (2026-08-27) fixed a path traversal through symlinked bin paths (CVE-2026-59944), another Perforce command injection, and a credential leak to the wrong GitLab domain. Earlier, 2.9.3 (2025-12-30) fixed ANSI sequence injection (CVE-2025-67746). Version 2.10.0 also stopped falling back from dist (zip) to source (git) installs, which can surprise a CI job that relied on it. A 2.11.0 release candidate (2026-10-22) exists and is not for production. Why: `composer install` runs on developer PCs and in CI, and it parses data from packages that other people publish. Pin the Composer version in the CI image, update it on a schedule, and find out whether Citadel's build image does the same (not known).
Sources: [S20], [S33].
Stack: `Not yet`.

### 4.9 Set a release cooldown for new package versions (second pass)
Self-spreading npm worms hit in September 2025 (Shai-Hulud), November 2025 (Shai-Hulud 2.0), May 2026 (Mini Shai-Hulud, over 170 npm packages by Microsoft's count) and August 2026 (a Keyv-related wave, Singapore CSA advisory AD-2026-009) [S35], [S36]. OWASP's Top 10:2025 added "Software Supply Chain Failures" as A03 [S57]. A short wait before adopting a new version removes much of the exposure, and the tools now have the setting. npm has `min-release-age` (in days; default null, so off) [S34]. Composer has `policy.cooldown.period` (default null) [S32]. pnpm has `minimumReleaseAge` (in minutes; a secondary source says pnpm 11 defaults it to one day; not checked in pnpm's docs). Generated apps should set a cooldown: pick a number, test it, and give security fixes an override. Update bots must use the same wait, or they propose versions the installer then refuses. Provenance alone is not enough: a June 2026 briefing says one wave abused trusted publishing to push bad versions without stolen credentials (secondary source, unconfirmed).
Sources: [S32], [S34], [S35], [S36], [S57].
Stack: `Not yet`.

### 4.10 Install JavaScript dependencies from the lock file with install scripts off (second pass)
Commit `package-lock.json`, install with `npm ci`, and set `ignore-scripts=true` for CI installs (npm's default is `false`, so scripts run) [S34]. Shai-Hulud 2.0 added a `preinstall` script to each package it infected [S35], and `ignore-scripts` stops that script from running. Some tools need a post-install step, for example to fetch a binary. Allow those by name and test the build. Add `npm audit` with `audit-level` set to the generated pipeline. Why: install scripts run with the developer's or the runner's rights.
Sources: [S34], [S35].
Stack: `Not yet`. PROCTOR's `lock-committed` check covers `composer.lock` only.

### 4.11 Pin Node to a supported LTS line, as with PHP (second pass)
Per endoflife.date: Node 20 ended 2026-04-30, 22 ends 2027-04-30, 24 ends 2028-04-30, and 26 becomes LTS on 2026-10-28 [S37]. Generated apps declare `engines` and `.nvmrc` from a policy file, as for the PHP floor in 4.2, and CI uses the same version. The nodejs.org release page was fetched too, but its dates did not match this source (the summary looked garbled), so check nodejs.org's schedule before relying on these. Sail installs Node 24 by default [S38].
Sources: [S37], [S38].
Stack: `Not yet`. CREED's `policy.json` carries MySQL dates per Squire; whether it carries Node is not known.

### 4.12 Keep the Vite dev server on localhost, and keep Vite current (second pass)
CVE-2025-62522 (published 2025-10-20; fixed in Vite 5.4.21, 6.4.1, 7.0.8 and 7.1.11) let a request with a trailing backslash read files that `server.fs.deny` should block, such as `.env`, on Windows when the dev server was reachable from the network [S39]. A later Windows `fs.deny` bypass, CVE-2026-53571 (2026-06-22), appeared in search results and its details were not checked. In a container the dev server must listen on all interfaces, so publish its port on the host's loopback only (`127.0.0.1:5173:5173` in the compose file), not on every interface. Set a Vite version floor in the template and run `npm audit`. Vite's docs also ask that `*.local` be in `.gitignore`, because `.env.*.local` files can hold secrets [S13].
Sources: [S39], [S13].
Stack: `Not yet`.

### 4.13 Test on PHP 8.4 and 8.5, with deprecations as failures (second pass)
PHP 8.5 was released on 2025-11-20 and has security support until 2029-12-31 [S23]. It deprecates things a new template can trip on: the backtick operator, the casts `(boolean)`, `(integer)`, `(double)` and `(binary)`, `null` as an array key, `__sleep` and `__wakeup` (soft), and `register_argc_argv` on web SAPIs. It also deprecates `PDO::MYSQL_ATTR_*` in favour of `Pdo\Mysql::ATTR_*`; Laravel 13's database config already branches on `PHP_VERSION_ID >= 80500` [S41], [S25]. The raw template and the CI jobs should run the tests on both versions with `error_reporting=E_ALL` and deprecations turned into failures.
Sources: [S41], [S23], [S25].
Stack: `Not yet`.

### 4.14 Never ship, commit or reuse an APP_KEY (second pass)
Laravel's app key protects encrypted values such as cookies. A leaked key lets an attacker forge them, and that has led to remote code execution in documented cases: CVE-2018-15133 (fixed in Laravel 5.6.30) is in CISA's known-exploited list according to search results, and a 2025 Hacker News headline reports over 600 Laravel apps exposed through leaked keys (headline and the Synacktiv analysis seen in search only, not opened) [S52], [S56]. In the template, `.env.example` carries an empty `APP_KEY=`, setup runs `php artisan key:generate`, and each environment gets its own key. The release script checks that the key is set and that `APP_ENV=production` and `APP_DEBUG=false` (common Laravel guidance, not re-checked in this pass).
Sources: [S52], [S56].
Stack: `Not yet`. This extends 4.4 and 4.5.

### 4.15 Produce a bill of materials in CI (second pass, lower priority)
`cyclonedx/cyclonedx-php-composer` makes a CycloneDX SBOM from `composer.lock` (Packagist lists PHP ^8.1 and Composer ^2.3). It covers Composer packages only; JavaScript and image contents need their own steps. Keep the file as a CI artifact, so that when the next Composer or npm advisory lands, the affected apps can be found by searching one file per app. Whether GitLab's dependency scanning reads this format was not checked.
Sources: [S42].
Stack: `Not yet`.

---

## 5. Every developer, every operating system

### 5.1 Ship `.gitattributes` in every generated app
Use `* text=auto eol=lf`, and set `.ps1`, `.bat` and `.cmd` to CRLF. Prefer `.gitattributes` to the per-user `core.autocrlf` setting, because the file travels with the repo and gives every contributor the same rules. Why: Git on Windows converts line endings, and anything that runs on Citadel must be LF.
Sources: [S7].
Stack: `Documented`. The developer-OS rule says generated apps carry it from day one. The repos already use it.

### 5.2 Check server-run files for CRLF and for file-name case clashes
Fail when `build.sh`, release scripts or `deployments/*` contain CRLF, and when two paths differ only by case. Why: macOS and Windows ignore case and Citadel does not, so an app can work on every laptop and break on deploy.
Sources: [S7].
Stack: `Not yet`. The developer-OS rule says PROCTOR will flag these. The PROCTOR source does not have the checks yet.

### 5.3 Give each OS its own native path
A script that developers run ships as a `.ps1` and a `.sh` that do the same thing, or is one PHP command. Windows never needs bash. Scripts for macOS are written for its stock bash and BSD tools. Prefer the single PHP command when the logic is more than a few lines. Be aware that PowerShell's default policy on Windows clients can block scripts that were downloaded from the internet (Microsoft calls the policy defence in depth, not a security boundary). Scripts the generator writes locally are not affected, but the docs should say what to do if a script is blocked.
Second pass: Laravel's own install page gives Windows users `Set-ExecutionPolicy Bypass -Scope Process -Force` followed by downloading a script and passing it to `iex` [S49]. WSU's docs should not copy that pattern. The same page also shows `composer global require laravel/installer`, which is the better route.
Sources: [S15], [S49].
Stack: `Documented`. The developer-OS rule sets it. No generated scripts exist yet to test it on.

### 5.4 Start subprocesses with argument arrays
Whenever Sourdough runs Composer, npm, git or Docker, pass the command as an array of arguments, never as a shell string. Accept paths with spaces and Windows drive letters. Set timeouts. Why: fewer quoting differences between PowerShell and bash, and no shell parsing of a directory name on Linux and macOS.
Second pass, correction: draft 1 also promised "no escaping bugs" and "no injection". That is too strong on Windows. PHP's array form of `proc_open` had a command injection on Windows (CVE-2024-1874), and its first fix was incomplete (CVE-2024-5585); the fixes are in PHP 8.1.29, 8.2.20 and 8.3.8 [S53]. It belongs to the "BatBadBut" family: when the target is a `.bat` or `.cmd` file, Windows starts `cmd.exe`, which splits arguments by its own rules, and callers must escape for it [S54]. Composer and npm are usually `.bat` or `.cmd` shims on Windows. So: keep the array form; where you can, run `php.exe` with `composer.phar` and `node.exe` with npm's JavaScript entry point instead of the shims; never pass user-chosen text (an app name, a path) to a shim; validate names as in 1.7. The PHP floor already rules out the unpatched builds.
Sources: [S9], [S53], [S54].
Stack: `Documented`. The developer-OS rule, second point. No code yet. The shim case was not checked against the developer-OS rule.

### 5.5 Offer more than one local runtime, and be honest about Sail on Windows
Sail is Laravel's Docker development environment. On Windows it needs WSL2 and its `sail` script is shell. Docker's own advice for WSL2 is to keep source code inside the Linux file system, because bind mounts from the Windows side are slower and do not deliver file-change events. So offer: Docker Compose with a Citadel-parity image as the route that works natively on every OS, Sail as an option where it fits, and plain PHP with Composer. Why: a starter that only works for macOS users is not a starter for WSU.
Second pass: re-checked and still correct. Sail's docs say Windows is supported "via WSL2" [S38], and Docker's WSL page says to keep bind-mounted source in the Linux file system, because Windows-side mounts are slower and Linux containers get no file-change events from them [S11]. Laravel's docs also offer Herd, a native macOS and Windows environment with PHP, Nginx, Node and the `laravel` CLI; Herd Pro adds local databases [S49]. It is a Laravel product. Its licence and its fit for WSU were not checked.
Sources: [S2], [S11], [S38], [S49].
Stack: `Documented`. The developer-OS rule's fourth point lists the routes and says Sail is never the only Windows route. The Docker image is Squire's and does not exist yet.

### 5.6 Treat Sail as a development tool
Sail's compose file and images are for local work. Do not reuse them as the production image. Why: they carry dev tools, ports and defaults that a production container should not.
Sources: [S2].
Stack: `Documented`. The design deploys on Citadel's own images and does not use Sail for that.

### 5.7 Build Docker images the safe way
If Sourdough generates a Dockerfile: pin the base image to an exact version (a digest is stronger), use a multi-stage build, run as a non-root user with a fixed UID, add a `.dockerignore`, never leave secrets in a layer, and rebuild regularly with `--pull`. State the CPU architecture. Citadel's images are x86-64 only as far as is known, so Apple Silicon users run them under emulation. Why: images are immutable, so a stale one stays stale.
Second pass: Docker's page confirms these points. It adds that pinning to a digest also opts you out of automatic security fixes, so pair the pin with a scheduled update (it names Dependabot; the GitLab equivalent is not chosen) [S10]. Docker made its Hardened Images catalog free under Apache 2.0 on 2025-12-17, per press coverage [S51]. Production images here are Citadel's, so that matters only for development tooling.
Sources: [S10], [S51].
Stack: `Not yet`. The developer-OS rule requires the architecture statement for Squire's image only.

### 5.8 Test on all three systems
Run the generator and the generated app's tests on Windows, macOS and Linux, or run a written smoke test before each minor release. Why: path, newline and shell bugs only appear on the system you did not test.
Sources: [S15], [S11].
Stack: `Not yet`. CI is Linux only, and Windows and macOS runners are an open question.

### 5.9 Fetch templates as archives, and keep Git current (second pass)
CVE-2025-48384 turned a carriage return in a submodule path into code execution during a recursive clone. It was fixed on 2025-07-08 in Git 2.43.7, 2.44.4, 2.45.4, 2.46.4, 2.47.3, 2.48.2, 2.49.1 and 2.50.1, and CISA lists it as exploited [S50]. Sourdough should get templates as Composer dist archives, not with `git clone --recurse-submodules`, and the docs should name the Git floor for developers. Composer 2.10 no longer falls back from dist to source by default, which helps here (see 4.8).
Sources: [S50], [S20].
Stack: `Not yet`.

---

## 6. Wiring the stack in

### 6.1 Wire the stack in by default, not as an option
Every generated app has SESAME, HELMET, PROCTOR as a dev dependency, a `.gitlab-ci.yml` that includes the Preflight components, a `/up` route that does not touch the database, and a README that points at the CREED handbook. Why: Laravel's own kits include sign-in, rate limiting and two-factor login out of the box for the same reason. Defaults decide what most apps end up doing.
Sources: [S1].
Stack: `Documented`. The libraries are tagged (SESAME, HELMET with a health check, PROCTOR, Preflight templates), and `sourdough new --raw` wires them into every generated app. Only the raw shape generates in 0.1; the Laravel shapes are not built yet.

### 6.2 Have the release script run the check, but do not call it the gate
The Citadel release script runs `proctor check` and stops on exit 1. Treat that as a report. A failure in a release script cannot stop bad code from going live on a Quick Update, so the real gates are CI before merge and, later, a platform check. Why: promising a gate that does not hold is worse than having none.
Sources: the stack's staged-enforcement and release-gate decisions.
Stack: `Documented`. The limitation is written in the stack's staged-enforcement decision. Nothing prevents someone from describing the script as a gate in a README.

### 6.3 Keep the start-up path small and boring
Generated commands should run and exit on their own, with no prompts and no network calls after install. Why: developers, CI and coding agents all run them, and a prompt that waits for input breaks all three.
Sources: [S6].
Stack: `Not yet`.

### 6.4 Put an accessibility gate in every starter and in the "new app" page (second pass)
The US Justice Department's Title II web rule sets WCAG 2.1 Level AA for state and local government web content. An interim final rule published on 2026-04-20 moved the first compliance date to 2027-04-26 for entities of 50,000 people or more and to 2028-04-26 for smaller ones. The standard and the ongoing duty to keep content accessible did not change [S43], [S44]. (I read search-result summaries of the Federal Register notice, not the full text.) Whether and when the rule applies to a given WSU app is not decided here. Ask WSU's accessibility office. For Sourdough: each permutation's generated home page and sign-in flow get an automated accessibility check in CI, including the Vue paths (focus after navigation, announced route changes); WayneUI's accessible templates are the default markup; and the "new app" page passes the same gate. Automated checks find only part of the problems, so the generated README should say so.
Sources: [S43], [S44].
Stack: `Not yet`. WayneUI treats accessibility as a runnable gate. Sourdough does not wire it yet.

### 6.5 Generate a pipeline that protects deploy credentials (second pass)
GitLab's docs say masking a variable does not reliably stop a malicious user from reading it; protected variables exist only in pipelines on protected branches and tags; a pipeline for a merge request from a fork should run in the parent project only after review, because malicious code can compromise masked and protected variables; and sensitive values belong in an external secrets manager [S45]. A project's job-token allowlist includes only the project itself by default, and GitLab calls disabling it a security risk. From GitLab 19.0 job tokens use the JWT format by default, and the legacy format is available only until 20.0 [S46]. The generated `.gitlab-ci.yml` should run deploy jobs only on a protected branch or tag with protected variables, put no secrets in component `inputs`, leave the allowlist on, and add other projects to it by name when the pipeline must fetch from them. Which GitLab version WSU runs was not checked.
Sources: [S45], [S46], [S16].
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
8. Write the Docker image rules (pinning, non-root, multi-stage, architecture) before Squire's image or `--docker` is built.
9. Add a compatibility pipeline that generates, installs and tests all five permutations.
10. Get Windows and macOS runners, or write the smoke-test checklist.
11. Say how Boost's generated files are refreshed and whether they are committed. (Second pass: the vendor says the generated files may be ignored and `.ai/rules` should be committed; Sourdough still has to choose.)
12. Decide between pinning Preflight components by tag or by commit. (GitLab prefers a commit SHA.)

Added in the second pass:

13. Settle the database story for the Laravel paths. The shared MySQL 5.6 is below Laravel 13's MySQL 5.7+ minimum, and Laravel's default 255-character indexed strings exceed the 767-byte key limit. Set the 191 default, avoid JSON columns, and run CI migrations against 5.6 (2.6, the MySQL timeline question).
14. Generate the newest Laravel major, name it in tests, and print each template's end-of-security date in the README and in `sourdough check` (2.4, 3.7).
15. Validate names and paths and keep templates free of code in the generator; add hostile-input tests (1.7).
16. Make the "new app" page call the same engine as the CLI and show the equivalent command (1.8).
17. Require a current Composer (2.10.3 or later at the time of writing) in CI and the build, and set Composer's `policy` block explicitly. Re-test PROCTOR's `composer-audit` check against 2.10 output (4.1, 4.8).
18. Set a release cooldown for Composer and npm, install JavaScript from the lock file with `ignore-scripts`, add a `package-lock.json` check to PROCTOR, and pin a supported Node LTS (4.9, 4.10, 4.11).
19. Keep the Vite dev server on localhost, raise the Vite floor, and publish container ports on loopback only (4.12).
20. Run CI on PHP 8.4 and 8.5 with deprecations as failures, and nightly (3.5, 4.13).
21. Keep `APP_KEY` out of git, generate it per environment, and have the release script check it and `APP_DEBUG` (4.14).
22. Add an accessibility check to the generated apps' CI and to the "new app" page, and ask the accessibility office how the Title II rule applies (6.4).
23. Generate a `.gitlab-ci.yml` that protects deploy credentials (protected variables, job-token allowlist, no secrets in `inputs`) (6.5).
24. Write the Windows rule for `.bat` and `.cmd` shims (run the real executables, never pass user text to a shim) into the developer-OS decision or the Sourdough docs (5.4).
25. Name an owner and a support window per permutation (3.6, 3.7).
26. Produce an SBOM in CI (4.15), low priority.

---

## Sources

Checked on 2026-10-27.

- [S1] Laravel 12.x docs, Starter Kits (draft 1; superseded by S47, because Laravel 13 is current): https://laravel.com/docs/12.x/starter-kits
- [S2] Laravel 12.x docs, Sail (draft 1; see S38 for the 13.x page): https://laravel.com/docs/12.x/sail
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
- [S17] Laravel 12.x docs, Boost (draft 1; see S48 for the 13.x page): https://laravel.com/docs/12.x/boost

Added in the second pass, checked 2026-10-27. "Opened" means the page was fetched and read; "search only" means a search-result summary was read and the page was not opened, so treat its details as unconfirmed.

- [S18] NVD, CVE-2023-35926, Backstage scaffolder template sandbox escape (search only): https://nvd.nist.gov/vuln/detail/CVE-2023-35926
- [S19] Copier docs, Configuring a template (security, `--trust`, answers file) (opened): https://copier.readthedocs.io/en/stable/configuring/
- [S20] Composer CHANGELOG, 2.9.0 to 2.11.0-RC1 (opened): https://github.com/composer/composer/blob/main/CHANGELOG.md
- [S21] Spring Initializr reference documentation (search only): https://docs.spring.io/initializr/docs/current-SNAPSHOT/reference/html/
- [S22] Laravel docs, Release notes and support policy (serves 13.x; opened): https://laravel.com/docs/releases
- [S23] php.net, Supported versions (opened): https://www.php.net/supported-versions.php
- [S24] Laravel docs, HTTP requests, trusted proxies and hosts (opened): https://laravel.com/docs/requests
- [S25] Laravel docs, Database (MySQL 5.7+, PHP 8.5 PDO constant; opened): https://laravel.com/docs/database
- [S26] endoflife.date, MySQL (opened): https://endoflife.date/mysql
- [S27] MySQL 5.7 manual, InnoDB limits (index key prefix; opened): https://dev.mysql.com/doc/refman/5.7/en/innodb-limits.html
- [S28] Cornell CIT Custom Development, Laravel starter kit (opened): https://github.com/CU-CommunityApps/CD-LaravelStarterKit
- [S29] Northwestern system development, Packagist listing (search only): https://packagist.org/packages/northwestern-sysdev/
- [S30] React blog, Sunsetting Create React App, 2025-02-14 (search only, via a mirror): https://vi.react.dev/blog/2025/02/14/sunsetting-create-react-app
- [S31] Laravel blog, Starter Kits: A New Beginning (search only): https://laravel.com/blog/laravel-starter-kits-a-new-beginning-for-your-next-project
- [S32] Composer docs, Config (`policy`, `audit`, `allow-plugins`; opened): https://getcomposer.org/doc/06-config.md
- [S33] The Hacker News, New PHP Composer flaws, April 2026 (search only): https://thehackernews.com/2026/04/new-php-composer-flaws-enable-arbitrary.html
- [S34] npm docs, config (`min-release-age`, `ignore-scripts`, `audit-level`; opened): https://docs.npmjs.com/cli/v11/using-npm/config
- [S35] Microsoft Security blog, Shai-Hulud 2.0 guidance (search only): https://www.microsoft.com/en-us/security/blog/2025/12/09/shai-hulud-2-0-guidance-for-detecting-investigating-and-defending-against-the-supply-chain-attack/
- [S36] Cyber Security Agency of Singapore, advisory AD-2026-009 (search only): https://csa.gov.sg/alerts-and-advisories/advisories/ad-2026-009
- [S37] endoflife.date, Node.js release data (opened): https://endoflife.date/api/nodejs.json
- [S38] Laravel docs, Sail (13.x; opened): https://laravel.com/docs/sail
- [S39] OSV, CVE-2025-62522, Vite `server.fs.deny` bypass on Windows (search only): https://osv.dev/vulnerability/CVE-2025-62522
- [S41] php.net manual, PHP 8.5 deprecated features (opened): https://www.php.net/manual/en/migration85.deprecated.php
- [S42] Packagist, cyclonedx/cyclonedx-php-composer (search only): https://packagist.org/packages/cyclonedx/cyclonedx-php-composer
- [S43] Federal Register, Extension of compliance dates, Title II web rule, 2026-04-20 (search only): https://www.federalregister.gov/documents/2026/04/20/2026-07663/extension-of-compliance-dates-for-nondiscrimination-on-the-basis-of-disability-accessibility-of-web
- [S44] ADA.gov, fact sheet on the web rule (search only): https://www.ada.gov/resources/2024-03-08-web-rule/
- [S45] GitLab docs, CI/CD variables (opened): https://docs.gitlab.com/ci/variables/
- [S46] GitLab docs, CI/CD job token (opened): https://docs.gitlab.com/ci/jobs/ci_job_token/
- [S47] Laravel docs, Starter Kits (serves 13.x; opened): https://laravel.com/docs/starter-kits
- [S48] Laravel docs, Boost (13.x; opened): https://laravel.com/docs/boost
- [S49] Laravel docs, Installation (13.x; opened): https://laravel.com/docs/installation
- [S50] Ubuntu security, CVE-2025-48384, Git (search only): https://ubuntu.com/security/CVE-2025-48384
- [S51] Docker press release, Hardened Images free (search only): https://www.docker.com/press-release/docker-makes-hardened-images-free-open-and-transparent-for-everyone/
- [S52] GitLab advisory database, CVE-2018-15133, Laravel (search only): https://advisories.gitlab.com/pkg/composer/laravel/framework/CVE-2018-15133/
- [S53] CIRCL vulnerability lookup, CVE-2024-1874 and CVE-2024-5585, PHP `proc_open` on Windows (search only): https://vulnerability.circl.lu/vuln/cve-2024-1874
- [S54] The Hacker News, BatBadBut, 2024 (search only): https://thehackernews.com/2024/04/critical-batbadbut-rust-vulnerability.html
- [S56] The Hacker News, Over 600 Laravel apps exposed, 2025-07 (headline only, search): https://thehackernews.com/2025/07/over-600-laravel-apps-exposed-to-remote.html
- [S57] OWASP Top 10:2025, Introduction (search only): https://owasp.org/Top10/2025/0x00_2025-Introduction/
- [S58] OWASP, Application Security Verification Standard 5.0.0, released 2025-05-30 (search only): https://owasp.org/ASVS/

Not used: no S40 or S55 (numbers skipped when a draft source was reused: S13 for Vite env, S4 for Copier updating).

Stack documents used: the stack design notes and decisions, and the SESAME, HELMET, PROCTOR and Preflight repos as of 2026-10-27.
Also read in the second pass: Squire's best-practices notes (S18, S44 to S47 on MySQL 5.6) and the PROCTOR source (`ComposerAuditCheck`, `LockCommittedCheck`). The sourdough folder holds only brand assets under `site/`, so no tag above is `Enforced` or `Checked` for Sourdough itself.

---

## Second pass (2026-10-27)

A second reviewer re-read draft 1 and ran new web searches and page fetches. Draft 1's research was not redone. The aim was what it missed or got wrong.

### Added (18 practices)
- Generating: 1.7 untrusted names, paths and templates; 1.8 one engine for the "new app" page and the CLI.
- Laravel: 2.4 newest major and support windows; 2.5 trusted proxies behind HA-Proxy; 2.6 the shared MySQL 5.6 against Laravel's MySQL 5.7+ minimum.
- Keeping current: 3.6 thin templates and shared libraries (Cornell as prior art); 3.7 owner, support window and sunset path (Create React App, Breeze and Jetstream as stories).
- Dependencies: 4.8 current Composer; 4.9 release cooldown; 4.10 npm lock file and `ignore-scripts`; 4.11 Node LTS floor; 4.12 Vite dev server; 4.13 PHP 8.4 and 8.5 with deprecations as failures; 4.14 APP_KEY; 4.15 SBOM.
- Platforms: 5.9 fetch templates as archives, keep Git current.
- Wiring: 6.4 accessibility gate and the Title II dates; 6.5 GitLab pipeline protection of deploy credentials.
- Backlog items 13 to 26 were added, and items 11 and 12 were annotated.

### Corrected or updated
1. 4.1: draft 1 said `composer install` does not audit by default. Composer's docs do not say, so the claim is marked unverified. The section now records the Composer 2.9 and 2.10 changes (insecure-version blocking, the `policy` block, malware blocking, exit codes 0 or 1).
2. 5.4: draft 1 said array arguments mean "no escaping bugs" and "no injection". That is wrong on Windows. PHP's array form of `proc_open` had command injection (CVE-2024-1874, CVE-2024-5585), and `.bat` and `.cmd` shims are re-parsed by `cmd.exe`.
3. Sources S1, S2, S17 and sections 2.1 to 2.3 cited the Laravel 12.x docs. Laravel 13 is current (2026-03-17). The kit list, the PHP 8.3 minimum and the lack of a plain Blade kit are now stated.
4. 2.3 and backlog 11: Boost's files can be git-ignored and `.ai/rules` should be committed, per the vendor. The question is half answered.
5. 1.4: exit code 2 means a third thing in Copier's `check-update --quiet`, and Composer 2.10 changed audit's exit codes.
6. 3.3: Copier recommends a clean git state and does not enforce it; the text now says Sourdough should enforce it.
7. 5.3 and 5.5: noted Laravel's Windows one-liner (`Bypass` plus `iex`) as a pattern not to copy, and Herd as a native Windows option.

### Draft 1 claims that checked out
PHP support dates and the 2027-01-01 floor (php.net); the GitLab pinning guidance; Laravel's "no need to update" starter-kit wording, the `--using` and `post-create-project-cmd` contract, and the Wayfinder build failure; Copier's newest-tag rule (PEP 440) and its inline and `.rej` conflict modes; cruft's `check` command; Boost's `--dev` install and `post-update-cmd` entry; Docker's WSL2 advice; Vite's `VITE_` exposure rule; Sail's WSL2 requirement.

### Not re-checked in this pass
S6 (clig.dev), S7 (gitattributes), S8 and S9 (Symfony Console and Process), S12 (Vite backend integration), S14 (Twelve-Factor), S15 (PowerShell policy text), and the stack design decisions. Several new sources are marked "search only" above. OWASP Top 10:2025 (S57) and ASVS 5.0.0 (S58) are worth mapping to PROCTOR rules later; that mapping was not done.

### Content in fetched pages
No instructions aimed at the reader were found in any fetched page or search result.
