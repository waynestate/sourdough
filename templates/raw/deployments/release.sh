#!/bin/sh
# On Citadel this runs at release time; elsewhere, run the same steps in
# your own build (a Dockerfile comes later). The .ps1 twin is for a Windows
# developer machine.
set -e

composer install --no-dev --no-interaction
vendor/bin/proctor check
