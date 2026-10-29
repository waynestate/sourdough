# Changelog

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic
Versioning once 1.0 is out.

## Unreleased

### Changed

- The Squire template copy in `templates/squire/` now comes from the `waynestate/squire` Composer package (new dev
  dependency, `^0.2`) instead of a Squire checkout. `composer squire-copy:sync` rewrites the copy from
  `vendor/waynestate/squire`, and `composer squire-copy:check` (now part of `composer ci`) exits 1 when the committed
  copy has drifted. The refresh brings Squire 0.2.0's deploy files: `scripts/release.sh` gains the
  `SQUIRE_PROCTOR=enforce` handling, and the `static` target joins the catalog. Fresh flour, straight from the mill.
- Changed: project names written as words except acronyms.
- Changed: generated apps require SESAME ^0.2 (Entra sign-in).
- Changed: the Squire copy comes from Squire 0.2.2.

### Fixed

- Fixed: `--laravel --inertia` is accepted.
- Fixed: stale capability claims in the site and README.

## 0.1.3 (2026-10-29)

### Added

- `sourdough new` wires PLAYBOOK into generated apps. By default the app's `composer.json` gets a `suggest` for `waynestate/playbook`, `.env.example` lists the seven WSU shared setting names, the README gains a "WSU values (PLAYBOOK)" section, and the command prints a hint line pointing at it. `--playbook` adds the VCS repository and requires `waynestate/playbook` `^0.1`; `--no-playbook` skips all of it; the two flags together are refused with exit 1.

## 0.1.2 (2026-10-29)

### Added

- `sourdough new` validates the app name before writing anything: it must start with a letter or number and use only letters, numbers, spaces, dots, dashes and underscores, at most 63 characters (`RawGenerator::assertValidName`). Hostile names (quotes, backslashes, newlines, NUL bytes, placeholders, path traversal, overlong or empty names) are refused with exit 1 and write nothing.

### Changed

- The raw template now requires HELMET `^0.2` and PROCTOR `^0.2`, so `sourdough new` installs the current stack releases instead of the 0.1 line. SESAME stays at `^0.1` until it cuts 0.2.

## 0.1.1 (2026-10-29)

### Changed

- PHPStan and Pint now take their settings from Pinstripe (`waynestate/pinstripe` ^0.1) instead of a hand
  copy: `phpstan.neon` includes the raw preset, and `pint.json` extends the per preset. Same cut, off the rack.

## 0.1.0 (2026-10-29)

### Added

- The raw app now installs SQUIRE's deploy files from a checked copy at `templates/squire/`: `build.sh`,
  `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`,
  `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. SQUIRE owns
  them; Sourdough copies them as-is and records them in the manifest.
- `composer squire-copy:check`: verify that `templates/squire/` still matches a fresh export from the SQUIRE checkout
  (`SQUIRE_PATH`, default `/squire`). Skips with exit 0 when no checkout is mounted.

### Changed

- Refreshed the SQUIRE template copy in `templates/squire/` from e66f07b to 2a893c0: the Vue layer's `.npmrc` and
  `docker-compose.squire.yml` joined the batch. Fresh flour, same recipe.

### Removed

- The raw template's own `deployments/release.sh` and `deployments/release.ps1`; the release steps now come from SQUIRE.
