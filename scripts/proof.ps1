<#
.SYNOPSIS
    Proofs every Sourdough loaf in Docker and writes the Last run table.

.DESCRIPTION
    The Windows companion to scripts/proof.sh. Builds the smoke image
    (scripts/smoke.Dockerfile, tag sourdough-smoke:local), hands it the
    committed tree as one tar file, and runs scripts/proof.sh --here inside:
    every shape, and every Laravel shape in each oven, through
    scripts/smoke-shape.sh, one after another.

    The tree is the committed one plus any edits to tracked files
    (git stash create); untracked files are left out, and the commit reads
    "+ local edits" when there were any. The source goes in as a single tar
    file rather than a bind-mounted folder, because Docker Desktop on Windows
    can list a mounted folder only partially, and a loaf missing half its
    flour still rises, just badly.

    Results land in -OutDir: last-run.md (the table for
    docs/reference/proof.md), summary.txt and logs\<run>.txt plus each run's
    step logs. Exit 0 when every run passed, 1 when any failed, 2 when the
    harness itself could not start.

.PARAMETER Runs
    The runs to proof: raw, raw-vue, blade, blade-vue, inertia, or a Laravel
    shape in an oven (blade+sail, blade+docker, blade-vue+sail,
    blade-vue+docker, inertia+sail, inertia+docker). None means all eleven.

.PARAMETER OutDir
    Where the table, the summary and the logs go (default: .\proof-out).

.PARAMETER FeedFrom
    The released Sourdough to feed forward from (default: the newest release
    in CHANGELOG.md; "none" skips it).

.EXAMPLE
    .\scripts\proof.ps1

.EXAMPLE
    .\scripts\proof.ps1 raw inertia+docker -OutDir C:\temp\proof
#>
param(
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]]$Runs = @(),

    [string]$OutDir = (Join-Path (Get-Location) 'proof-out'),

    [string]$FeedFrom = ''
)

$ErrorActionPreference = 'Stop'

$allRuns = @(
    'raw', 'raw-vue', 'blade', 'blade-vue', 'inertia',
    'blade+sail', 'blade+docker', 'blade-vue+sail', 'blade-vue+docker', 'inertia+sail', 'inertia+docker'
)
foreach ($run in $Runs) {
    if ($allRuns -notcontains $run) {
        Write-Error "proof.ps1: unknown run `"$run`". Runs: $($allRuns -join ' ')" -ErrorAction Continue
        exit 2
    }
}

foreach ($tool in @('git', 'docker')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        Write-Error "proof.ps1: $tool is not on PATH" -ErrorAction Continue
        exit 2
    }
}

$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir
$image = 'sourdough-smoke:local'

if (-not (Test-Path -LiteralPath $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir | Out-Null
}
$OutDir = (Resolve-Path -LiteralPath $OutDir).Path

# The tree to proof: the commit, plus edits to tracked files when there are any.
$commit = (& git -C $repoRoot rev-parse --short HEAD).Trim()
if ($LASTEXITCODE -ne 0) { exit 2 }
$tree = (& git -C $repoRoot stash create) -join ''
if ($tree) {
    $commit = "$commit + local edits"
} else {
    $tree = 'HEAD'
}
& git -C $repoRoot archive --format=tar -o (Join-Path $OutDir 'source.tar') $tree
if ($LASTEXITCODE -ne 0) { exit 2 }

# The Dockerfile copies nothing from a build context, so it goes in on stdin
# with none: no need to ship vendor/ and node_modules/ to the daemon.
Get-Content -Raw -LiteralPath (Join-Path $scriptDir 'smoke.Dockerfile') | & docker build -q -t $image - | Out-Null
if ($LASTEXITCODE -ne 0) { exit 2 }

$inside = 'mkdir -p /src && tar -xf /out/source.tar -C /src && rm /out/source.tar && exec sh /src/scripts/proof.sh --here "$@"'
$dockerArgs = @(
    'run', '--rm',
    '-v', "${OutDir}:/out",
    '-e', 'PROOF_OUT=/out',
    '-e', "PROOF_COMMIT=$commit",
    '-e', "PROOF_DATE=$(Get-Date -Format 'yyyy-MM-dd')",
    '-e', "PROOF_FEED_FROM=$FeedFrom",
    $image,
    'sh', '-c', $inside, 'proof'
) + $Runs

& docker @dockerArgs
exit $LASTEXITCODE
