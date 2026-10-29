# Operator-only: the supervisor receives Docker authority; application containers do not.
Set-StrictMode -Version Latest
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
function Invoke-Docker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed (exit $LASTEXITCODE)." }
}
Push-Location -LiteralPath $repositoryRoot
try {
    Invoke-Docker -DockerArguments @('build', '--target', 'worker', '-f',
        'tools/axiompress/supervisor/Dockerfile', '-t', 'axiompress-worker:prototype', 'tools/axiompress')
    Invoke-Docker -DockerArguments @('build', '--target', 'supervisor', '-f',
        'tools/axiompress/supervisor/Dockerfile', '-t', 'axiompress-supervisor:prototype', 'tools/axiompress')
    $workerImageId = Invoke-Docker -DockerArguments @('image', 'inspect', 'axiompress-worker:prototype', '--format', '{{.Id}}')
    $supervisorImageId = Invoke-Docker -DockerArguments @('image', 'inspect', 'axiompress-supervisor:prototype', '--format', '{{.Id}}')
    $runtimeOutput = & docker run --rm --name axiompress-supervisor-check --network none --read-only `
        --tmpfs /tmp:rw,size=32m --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock `
        --env "AXIOM_WORKER_IMAGE=$workerImageId" $supervisorImageId
    $runtimeExit = $LASTEXITCODE
    $resultsDirectory = Join-Path $repositoryRoot 'docs'
    if (-not (Test-Path -LiteralPath $resultsDirectory)) {
        New-Item -ItemType Directory -Path $resultsDirectory | Out-Null
    }
    [IO.File]::WriteAllLines((Join-Path $resultsDirectory 'axiompress-supervisor-runtime-last.jsonl'),
        [string[]]$runtimeOutput)
    $runtimeOutput | ForEach-Object { if ($_.Length -lt 400) { $_ } }
    if ($runtimeExit -ne 0) { throw "Runtime checks failed (exit $runtimeExit)." }
    foreach ($line in $runtimeOutput) { $null = $line | ConvertFrom-Json -ErrorAction Stop }
} finally {
    Pop-Location
}
