# On Citadel the .sh runs; this .ps1 is for a Windows developer machine.
# Elsewhere, run the same steps in your own build (a Dockerfile comes later).

composer install --no-dev --no-interaction
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

vendor/bin/proctor check
exit $LASTEXITCODE
