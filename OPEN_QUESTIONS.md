# Sourdough open questions

What Sourdough could not settle without an outside owner or a machine we do not have, and the default 1.0 ships with. Roles, not names. A row here is known and open at 1.0; none of them gates the tag, and each is updated when its owner answers.

## Known open at 1.0

| # | Question | Who can answer | What it blocks | Default built |
|---|---|---|---|---|
| 1 | Should the "new app" page return a ready archive and create the repository, not only show the equivalent `sourdough new` command? (BEST_PRACTICES backlog 16.) | The stack maintainer, with the git.wayne.edu owners for repository creation | A one-click start from the web page | The page shows the command built from the CLI's own plan code; nobody gets an archive or a repository from it. |
| 2 | Should a previous-Laravel-major smoke job run in CI beside the current one? (Backlog 20; see 3.5.) | The stack maintainer | Early warning when the next Laravel major breaks a template | The nightly proof covers it. The `smoke-floor`, `smoke-php84` and 8.5 jobs run on every push; a previous-major job is not built. |
| 3 | How does the Title II accessibility rule apply to the generated apps and the "new app" page? (Backlog 22; see 6.4.) | The accessibility office | Wording any accessibility claim beyond what the automated check proves | The starters and the front page's order counter pass the automated check; nothing claims legal conformance. |
| 4 | Does the department's proxy for Citadel's package downloads need a setting in a generated app? | The Citadel platform team | A proxy line in `.env.squire.example` | Apps pull from the public registries through the cache the pipeline already uses. |
| 5 | Which MySQL 5.6 end-of-life date does the Laravel shapes' migration job stop covering? | The CREED owner | Dropping the `mysql56-migrate` component | Every Laravel pipeline keeps running its migrations against MySQL 5.6.51 (D-078) until CREED says otherwise. |

## Missing configuration

Nothing in a bake needs a secret, key, token or host. A generated app's pipeline uses the job's own CI token for Composer.
