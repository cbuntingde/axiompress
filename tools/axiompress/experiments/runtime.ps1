# Trusted operator experiment only: nested privileged OCI toolbox, no daemon configuration changes.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
function Invoke-RuntimeDocker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw 'Runtime evaluation Docker operation failed.' }
}
Push-Location -LiteralPath $repositoryRoot
try {
    $cache = Join-Path $repositoryRoot '.cache/axiompress-runtime'
    New-Item -ItemType Directory -Force -Path $cache | Out-Null
    $archive = Join-Path $cache 'gvisor.tar.bz2'
    if (-not (Test-Path -LiteralPath $archive)) {
        Invoke-WebRequest 'https://github.com/google/gvisor/releases/download/release-20261026.0/gvisor-x86_64.tar.bz2' -OutFile $archive
    }
    $expected = '26e4a327565f5da0f73261a6387e2c20f4695a232e330095a3111b70efd604fdf09d734e8cc8f714dbb5c5607ce8c87ea80b26df549d83a146f2efd5a1af448a'
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA512).Hash.ToLowerInvariant() -ne $expected) {
        throw 'Pinned official gVisor release checksum mismatch.'
    }
    $worker = Invoke-RuntimeDocker -DockerArguments @('image', 'inspect', 'axiompress-worker:prototype', '--format', '{{.Id}}')
    $export = Invoke-RuntimeDocker -DockerArguments @('create', '--pull=never', $worker)
    try {
        Invoke-RuntimeDocker -DockerArguments @('export', '--output', (Join-Path $cache 'worker.tar'), $export)
    } finally {
        Invoke-RuntimeDocker -DockerArguments @('rm', $export) | Out-Null
    }
    Invoke-RuntimeDocker -DockerArguments @('build', '-f', 'tools/axiompress/experiments/runtime.Dockerfile',
        '-t', 'axiompress-runsc-evaluation:prototype', $cache)
    $toolbox = Invoke-RuntimeDocker -DockerArguments @('image', 'inspect', 'axiompress-runsc-evaluation:prototype', '--format', '{{.Id}}')
    $script = Join-Path $PSScriptRoot 'runtime-oci.py'
    $proof = Invoke-RuntimeDocker -DockerArguments @('run', '--rm', '--privileged', '--network=none', '--read-only',
        '--memory=512m', '--memory-swap=512m', '--pids-limit=256',
        '--tmpfs=/work:rw,exec,size=256m', '--tmpfs=/tmp:rw,exec,size=64m',
        '--mount', "type=bind,source=$script,target=/evaluation.py,readonly", '--entrypoint', 'python3',
        $toolbox, '/evaluation.py')
    $report = $proof | ConvertFrom-Json -ErrorAction Stop
    if ($report.outcome -ne 'passed') { throw 'OCI evaluation failed.' }
    $report | Add-Member -NotePropertyName artifact_sha512 -NotePropertyValue $expected
    $report | Add-Member -NotePropertyName toolbox_image -NotePropertyValue $toolbox
    $report | Add-Member -NotePropertyName worker_image -NotePropertyValue $worker
    $report | Add-Member -NotePropertyName script_sha256 -NotePropertyValue (Get-FileHash $script -Algorithm SHA256).Hash.ToLowerInvariant()
    $report | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath (Join-Path $repositoryRoot 'docs/axiompress-runsc-oci-last.json')
    'PASS: gVisor PHP/framed-worker compatibility and 11 boundary probes; production resource isolation remains unproven.'
} finally {
    Pop-Location
}
