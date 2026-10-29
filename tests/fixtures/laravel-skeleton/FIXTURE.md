# Laravel skeleton fixture

A trimmed copy of the real Laravel skeleton, so tests can exercise the
Laravel shapes without touching the network.

## Where it came from

Created on 2026-10-29 with Composer 2.10.3 (Docker image `composer:2.10.3`):

```
composer create-project laravel/laravel:^13.0 <dir> --no-install --no-scripts --no-interaction
```

That resolved **laravel/laravel v13.11.0**, whose `composer.json` requires
`laravel/framework: ^13.17` and `php: ^8.3`.

## What was kept

Only the files a generated app starts life with and Sourdough needs to see:

- `composer.json`, `artisan`, `.env.example`, `.gitignore`
- `app/Models/User.php`, `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`, `bootstrap/providers.php`
- `config/app.php`, `config/auth.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `public/index.php`
- `resources/views/welcome.blade.php`
- `routes/web.php`, `routes/console.php`

Everything else from the skeleton (asset pipeline, extra config, seeders,
factories, the skeleton's own test suite, storage dirs) was dropped. The
skeleton's tests are out on purpose: they would be picked up by Sourdough's
PHPUnit run and fail without an installed framework.

## Refreshing it

Run the command above again, copy the same file list over, and update the
version noted here. The fixture is reference material for
`FixtureSkeletonSource`; nothing in the test suite parses it for truth about
current Laravel, only for shape.
