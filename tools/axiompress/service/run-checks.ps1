param([switch]$DevelopmentRunc)
# Operator-only: the disposable harness has Docker authority; core and plugins do not.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$runtime = if ($DevelopmentRunc) { 'runc' } else { 'runsc' }
function Invoke-ServiceDocker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw 'Phase 2 Docker check failed.' }
}
Push-Location -LiteralPath $repositoryRoot
try {
    $runtimes = (Invoke-ServiceDocker -DockerArguments @('info', '--format', '{{json .Runtimes}}')) | ConvertFrom-Json
    if ($runtime -notin @($runtimes.PSObject.Properties.Name)) {
        throw "Required runtime '$runtime' is not registered. No fallback is allowed. See service/README.md."
    }
    foreach ($target in @('worker-sdk', 'worker', 'supervisor')) {
        Invoke-ServiceDocker -DockerArguments @('build', '--target', $target, '-f',
            'tools/axiompress/supervisor/Dockerfile', '-t', "axiompress-$($target):phase2", 'tools/axiompress')
    }
    # PackageBuilder validates this trusted SDK identity before and after each fixed-template build.
    Invoke-ServiceDocker -DockerArguments @('tag', 'axiompress-worker-sdk:phase2', 'axiompress-worker-sdk:prototype')
    $sdk = Invoke-ServiceDocker -DockerArguments @('image', 'inspect', 'axiompress-worker-sdk:phase2', '--format', '{{.Id}}')
    $worker = Invoke-ServiceDocker -DockerArguments @('image', 'inspect', 'axiompress-worker:phase2', '--format', '{{.Id}}')
    $image = Invoke-ServiceDocker -DockerArguments @('image', 'inspect', 'axiompress-supervisor:phase2', '--format', '{{.Id}}')
    $result = @(Invoke-ServiceDocker -DockerArguments @('run', '--rm', '--network=none', '--read-only',
        '--tmpfs=/tmp:rw,size=64m', '--tmpfs=/policy:rw,size=16m', '--tmpfs=/state:rw,size=16m',
        '--tmpfs=/channel:rw,size=1m', '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
        '--mount', "type=bind,source=$repositoryRoot/src/axiompress,target=/core,readonly",
        '--env', "AXIOM_SDK_IMAGE=$sdk", '--env', "AXIOM_TEST_RUNTIME=$runtime", '--env', "AXIOM_TEST_WORKER=$worker",
        '--entrypoint', 'python3', $image, '/app/service/test.py'))
    $report = $result[-1] | ConvertFrom-Json -ErrorAction Stop
    if ($report.outcome -ne 'passed') { throw 'Service verification did not pass.' }
    $report | Add-Member -NotePropertyName supervisor_image -NotePropertyValue $image
    $report | Add-Member -NotePropertyName worker_image -NotePropertyValue $worker
    New-Item -ItemType Directory -Force -Path 'docs' | Out-Null
    $report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath 'docs/axiompress-phase2-service-last.json'
    "PASS: $($report.checks.Count) service checks; runtime=$runtime; cold p95=$([Math]::Round($report.cold_p95_ms, 2)) ms."
    if ($DevelopmentRunc) { 'Development evidence only: native-host gVisor acceptance is still required.' }
} finally {
    Pop-Location
}
