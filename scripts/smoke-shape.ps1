<#
.SYNOPSIS
    Bakes one Sourdough shape for real and runs the generated app's own checks.

.DESCRIPTION
    The Windows companion to scripts/smoke-shape.sh. Builds
    scripts/smoke.Dockerfile once (image sourdough-smoke:local) and then
    docker-runs the script with this repository mounted read-only and a
    scratch volume for the output. A shape's gate is green only when every
    step prints ok; the first FAIL stops the run with the command's tail.

.PARAMETER Shape
    Which shape to bake: raw, blade or inertia.

.PARAMETER WorkDir
    Optional. Bind this local directory to /work so the scratch copy, the
    generated app and the per-step logs survive the run for inspection.
    Without it a throwaway volume is used.

.EXAMPLE
    .\scripts\smoke-shape.ps1 raw

.EXAMPLE
    .\scripts\smoke-shape.ps1 inertia -WorkDir C:\temp\smoke-inertia
    Re-run after a failure to read the full step logs under C:\temp\smoke-inertia\logs.
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('raw', 'blade', 'inertia')]
    [string]$Shape,

    [string]$WorkDir
)

$ErrorActionPreference = 'Stop'

$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir
$image = 'sourdough-smoke:local'

function Invoke-Docker {
    param([string[]]$Arguments)

    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($Arguments -join ' ') failed with exit code $LASTEXITCODE"
    }
}

# Build the image once; later runs reuse it.
& docker image inspect $image *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Building $image (first run)..."
    Invoke-Docker @('build', '-q', '-f', (Join-Path $scriptDir 'smoke.Dockerfile'), '-t', $image, $repoRoot)
}

# Scratch for the copy, the app and the step logs. A throwaway volume by
# default; a local directory when -WorkDir is given, so a failed run leaves
# its logs behind to read.
$workMount = '/work'
if ($WorkDir) {
    if (-not (Test-Path -LiteralPath $WorkDir)) {
        New-Item -ItemType Directory -Path $WorkDir | Out-Null
    }
    $workMount = "$(Resolve-Path -LiteralPath $WorkDir):/work"
}

Invoke-Docker @(
    'run', '--rm',
    '-v', "${repoRoot}:/repo:ro",
    '-v', $workMount,
    '-e', 'SMOKE_WORK=/work',
    $image,
    'sh', '/repo/scripts/smoke-shape.sh', $Shape
)
exit $LASTEXITCODE
