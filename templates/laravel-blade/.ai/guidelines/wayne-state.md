## Wayne State app stack

This app was baked by Sourdough from the Wayne State app stack (Laravel with Blade views). These conventions hold on top of Laravel's own.

- Sign-in is SESAME. It registers `/login` and `/logout`, and the `auth.sesame` middleware protects a route; locally it runs the fake driver (`SESAME_DRIVER=fake`). Never add Laravel's own auth routes, Fortify or Breeze.
- WayneUI draws the interface: views use its `wsu-` classes, and `resources/views/layouts/app.blade.php` loads its stylesheet and theme script. There is no Tailwind.
- HELMET sets the security headers, the CSP nonce and the trusted proxies (`HELMET_PRESET`, `HELMET_PROXIES`), and answers `/up` without touching the database. Never paste `trustProxies(at: '*')`.
- Call `env()` only inside `config/*.php`: after `config:cache` it returns null anywhere else.
- Deploys run on MySQL 5.6: keep indexed strings within 191 characters and use no JSON columns (`.ai/rules/migrations.md`).
- Oracle is wired but dormant. Fill in the `ORACLE_*` settings, then check them with `php artisan oracle:check`.
- Give every scheduled closure a `->name()`; `tests/Feature/SchedulerNamingTest.php` fails otherwise.
- Write tests in Pest. `composer ci` runs what CI runs (Composer validate, Pest, Pint, PHPStan, `composer audit`), and `composer proctor` measures the app against the stack's version policy.
- Squire owns the deploy files (`build.sh`, `deployments/`, `scripts/release.sh`, `.squire/`). Refresh them with `sourdough feed`, never by hand.
- Laravel Boost is a dev tool and never ships: it stays in `require-dev`, and every release installs with `composer install --no-dev`.
- When ADVISOR is installed (`waynestate/advisor` in `require-dev`), its MCP server answers Wayne State stack questions (versions, standards, which tool checks what) from bundled data. Ask it before answering from memory.
