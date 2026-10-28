# Changelog

All notable changes to Sourdough are recorded here. The format follows Keep a Changelog; the project follows Semantic
Versioning once 1.0 is out.

## Unreleased (0.1)

### Added

- The raw app now installs SQUIRE's deploy files from a checked copy at `templates/squire/`: `build.sh`,
  `deployments/001-first-release.sh`, `scripts/release.sh`, `scripts/parity.sh`, `scripts/parity.ps1`, `health.php`,
  `public/up.php`, `docker-compose.squire.yml`, `.env.squire.example`, and the `.squire/` build context. SQUIRE owns
  them; Sourdough copies them as-is and records them in the manifest.
- `composer squire-copy:check`: verify that `templates/squire/` still matches a fresh export from the SQUIRE checkout
  (`SQUIRE_PATH`, default `/squire`). Skips with exit 0 when no checkout is mounted.

### Removed

- The raw template's own `deployments/release.sh` and `deployments/release.ps1`; the release steps now come from SQUIRE.
