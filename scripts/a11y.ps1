# Site accessibility gate (axe-core in Playwright), run in Docker so no host
# browser is needed. Windows PowerShell 5.1 and PowerShell 7; on Linux
# and macOS use scripts/a11y.sh.
#
# Every committed HTML page under site/ is checked: the front page and the
# docs site. The list comes from git, so a new page is covered the moment it
# is committed.
#
# The repo is mounted read-only; only tools/a11y/node_modules lives in a named
# volume, so npm installs once and later runs reuse it.
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$image = "mcr.microsoft.com/playwright:v1.49.1-noble"

$pages = & git ls-files 'site/*.html' | ForEach-Object { $_ -replace '^site/', '' }
$pageArgs = @($pages | ForEach-Object { "--page={0}" -f $_ }) -join " "

$inner = "if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=site $pageArgs"

docker run --rm `
  -v "${PWD}:/work:ro" `
  -v sourdough-a11y-node-modules:/work/tools/a11y/node_modules `
  -w /work `
  $image `
  sh -c $inner

exit $LASTEXITCODE
