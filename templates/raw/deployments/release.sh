#!/bin/sh
# On Citadel the .sh runs; the .ps1 is for a Windows developer machine.
set -e

composer install --no-dev --no-interaction
vendor/bin/proctor check
