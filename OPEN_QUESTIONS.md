# Sourdough open questions

What Sourdough could not settle without an outside owner or a machine we do not have, and the default 1.0 ships with. Roles, not names. A row here is known and open at 1.0; none of them gates the tag, and each is updated when its owner answers.

## Known open at 1.0

| # | Question | Who can answer | What it blocks | Default built |
|---|---|---|---|---|
| 1 | Should the "new app" page return a ready archive and create the repository, not only show the equivalent `sourdough new` command? (BEST_PRACTICES backlog 16.) | The stack maintainer, with the git.wayne.edu owners for repository creation | A one-click start from the web page | The page shows the command built from the CLI's own plan code; nobody gets an archive or a repository from it. |
| 2 | Should a previous-Laravel-major smoke job run in CI beside the current one? (Backlog 20; see 3.5.) | The stack maintainer | Early warning when the next Laravel major breaks a template | The nightly proof covers it. The `smoke-floor`, `smoke-php84` and 8.5 jobs run on every push; a previous-major job is not built. |
| 3 | How does the Title II accessibility rule apply to the generated apps and the "new app" page? (Backlog 22; see 6.4.) | The accessibility office | Wording any accessibility claim beyond what the automated check proves | The starters and the front page's order counter pass the automated check; nothing claims legal conformance. |
| 4 | Does Citadel publish the proxy addresses an app sits behind? (BEST_PRACTICES 2.5.) | The Citadel platform team | Listing real addresses in the generated trusted-proxies setting | The generated apps read the proxy list from the environment and set `trustHosts`; no address is baked in. |
| 5 | What is the MySQL 5.6 timeline for the shared database the Laravel shapes run on? (BEST_PRACTICES 2.6.) | The database owners and the CREED owner | Dropping the Laravel-outside-vendor-support note from the generated README | Both Laravel shapes keep the 191 string default, every pipeline runs the migrations against MySQL 5.6.51 (D-078), and PROCTOR's `mysql-eol` check warns. |

## Missing configuration

Nothing in a bake needs a secret, key, token or host. A generated app's pipeline uses the job's own CI token for Composer.
