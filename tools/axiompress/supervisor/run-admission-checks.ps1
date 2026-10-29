# Operator-only: run with the prototype worker namespace idle, without concurrent stress/live runners.
Set-StrictMode -Version Latest
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
Push-Location -LiteralPath $repositoryRoot
try {
    & docker build --target supervisor -f tools/axiompress/supervisor/Dockerfile -t axiompress-supervisor:prototype tools/axiompress
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor build failed.' }
    $supervisorImage = & docker image inspect axiompress-supervisor:prototype --format '{{.Id}}'
    if ($LASTEXITCODE -ne 0) { throw 'Supervisor image missing.' }
    $workerImage = & docker image inspect axiompress-worker:prototype --format '{{.Id}}'
    if ($LASTEXITCODE -ne 0) { throw 'Run run-checks.ps1 to build the worker fixture first.' }
    $result = & docker run --rm --network=none --read-only --tmpfs=/tmp:rw,size=32m `
        --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock `
        --env "AXIOM_WORKER_IMAGE=$workerImage" --entrypoint php $supervisorImage /app/supervisor/admission-test.php
    if ($LASTEXITCODE -ne 0) { $result; throw 'Admission/recovery verification failed.' }
    $result
    [IO.File]::WriteAllLines((Join-Path $repositoryRoot 'docs/axiompress-admission-runtime-last.txt'), [string[]]$result)
} finally {
    Pop-Location
}
