# Changelog

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic
Versioning once 1.0 is out.

## Unreleased

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
