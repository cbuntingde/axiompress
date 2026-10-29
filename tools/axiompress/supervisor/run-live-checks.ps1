param([int]$PublicPostId = 9, [int]$AdminUserId = 1, [int]$SiteId = 1)
# Operator-only live integration. Core and supervisor containers have different authority.
Set-StrictMode -Version Latest
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
function Invoke-LiveDocker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed (exit $LASTEXITCODE)." }
}
if ($PublicPostId -lt 1 -or $AdminUserId -lt 1 -or $SiteId -lt 1) { throw 'Positive IDs required.' }
Push-Location -LiteralPath $repositoryRoot
try {
    Invoke-LiveDocker -DockerArguments @('build', '--target', 'worker-sdk', '-f',
        'tools/axiompress/supervisor/Dockerfile', '-t', 'axiompress-worker-sdk:prototype', 'tools/axiompress')
    Invoke-LiveDocker -DockerArguments @('build', '--target', 'worker', '-f',
        'tools/axiompress/supervisor/Dockerfile', '-t', 'axiompress-worker:prototype', 'tools/axiompress')
    Invoke-LiveDocker -DockerArguments @('build', '--target', 'supervisor', '-f',
        'tools/axiompress/supervisor/Dockerfile', '-t', 'axiompress-supervisor:prototype', 'tools/axiompress')
    $sdkImageId = Invoke-LiveDocker -DockerArguments @('image', 'inspect', 'axiompress-worker-sdk:prototype', '--format', '{{.Id}}')
    $supervisorImageId = Invoke-LiveDocker -DockerArguments @('image', 'inspect', 'axiompress-supervisor:prototype', '--format', '{{.Id}}')
    Invoke-LiveDocker -DockerArguments @('volume', 'create', 'axiompress-policy-prototype') | Out-Null
    Invoke-LiveDocker -DockerArguments @('volume', 'create', 'axiompress-admission-prototype') | Out-Null
    $installationId = 'probe-' + [Guid]::NewGuid().ToString('N')
    $approval = Invoke-LiveDocker -DockerArguments @('run', '--rm', '--name', 'axiompress-supervisor-check',
        '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=32m',
        '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
        '--mount', 'type=volume,source=axiompress-policy-prototype,target=/policy',
                '--mount', 'type=volume,source=axiompress-admission-prototype,target=/admission',
        '--env', "AXIOM_SDK_IMAGE=$sdkImageId", '--env', "AXIOM_INSTALLATION=$installationId",
        '--env', "AXIOM_SITE=site:$SiteId", '--env', 'AXIOM_APPROVE_LIVE_TEST=1',
        '--entrypoint', 'php', $supervisorImageId, '/app/policy/setup-live.php')
    $approvalRecord = $approval | ConvertFrom-Json -ErrorAction Stop
    if ($approvalRecord.installation -ne $installationId -or $approvalRecord.generation -ne 2) {
        throw 'Unexpected installation approval.'
    }
    Invoke-LiveDocker -DockerArguments @('run', '--rm', '--network=none', '--read-only',
        '--tmpfs=/tmp:rw,size=32m', '--entrypoint', 'php', $supervisorImageId, '/app/policy/test.php')
    $policyProof = Invoke-LiveDocker -DockerArguments @('run', '--rm', '--name', 'axiompress-supervisor-check',
        '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=32m',
        '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
        '--env', "AXIOM_APPROVED_IMAGE=$($approvalRecord.image)", '--env', "AXIOM_SDK_IMAGE=$sdkImageId",
        '--entrypoint', 'php', $supervisorImageId, '/app/policy/runtime-test.php')
    $policyRecord = $policyProof | ConvertFrom-Json -ErrorAction Stop
    if ($policyRecord.outcome -ne 'passed' -or $policyRecord.reenabled_generation -ne 4) {
        throw 'Persistent grant runtime checks failed.'
    }
    [IO.File]::WriteAllLines((Join-Path $repositoryRoot 'docs/axiompress-policy-runtime-last.jsonl'), [string[]]$policyProof)
    $cliId = Invoke-LiveDocker -DockerArguments @('compose', '--env-file', '.env', 'ps', '-q', 'cli')
    if (-not $cliId) { throw 'Existing local CLI service is required.' }
    $cliInspection = (Invoke-LiveDocker -DockerArguments @('inspect', $cliId)) | ConvertFrom-Json
    $networkNames = @($cliInspection[0].NetworkSettings.Networks.PSObject.Properties.Name)
    if ($networkNames.Count -ne 1) { throw 'Expected exactly one core database network.' }
    $coreImageId = $cliInspection[0].Image
    $fixtureToken = [Guid]::NewGuid().ToString('N')
    $createdPost = Invoke-LiveDocker -DockerArguments @('compose', '--env-file', '.env', 'exec', '-T', '--user', 'wp_php',
        'cli', 'wp', 'post', 'create', '--post_title=AxiomPress isolated private fixture',
        '--post_content=Private alpha beta.', '--post_status=private', "--post_author=$AdminUserId",
        "--post_name=axiompress-probe-$fixtureToken", '--porcelain', '--path=/var/www/src')
    $privateIds = @($createdPost | Where-Object { $_ -match '^[1-9][0-9]*$' })
    if ($privateIds.Count -ne 1) { throw 'Could not identify private fixture post.' }
    $privatePostId = $privateIds[0]
    $cases = @(
        @{ Post=$PublicPostId; User=$AdminUserId; Expected='passed' },
        @{ Post=$PublicPostId; User=0; Expected='passed' },
        @{ Post=$privatePostId; User=$AdminUserId; Expected='passed' },
        @{ Post=$privatePostId; User=0; Expected='denied' },
        @{ Post=$PublicPostId; User=2147483647; Expected='denied' },
        @{ Post=$privatePostId; User=0; Expected='revocation-passed' }
    )
    $results = @()
    foreach ($case in $cases) {
        $coreName = 'axiompress-core-' + [Guid]::NewGuid().ToString('N').Substring(0,24)
        try {
            Invoke-LiveDocker -DockerArguments @('create', '--name', $coreName,
                '--label', 'dev.axiompress.prototype=core-bridge-v1', '--read-only', '--user=1000:1000',
                '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=256m', '--memory-swap=256m',
                '--pids-limit=32', '--log-driver=none', '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=32m',
                '--network', $networkNames[0], '--volumes-from', "${cliId}:ro", '-i', '--entrypoint', 'php',
                $coreImageId, '-d', 'display_errors=stderr', '/var/www/tools/axiompress/broker/core-bridge.php',
                "$SiteId", "$($case.Post)", "$($case.User)") | Out-Null
            $actor = if ($case.User -eq 0) { 'anonymous' } else { "user:$($case.User)" }
            $revocation = if ($case.Expected -eq 'revocation-passed') { '1' } else { '0' }
            $output = Invoke-LiveDocker -DockerArguments @('run', '--rm', '--name', 'axiompress-supervisor-check',
                '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=32m',
                '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
                '--mount', 'type=volume,source=axiompress-policy-prototype,target=/policy',
                '--mount', 'type=volume,source=axiompress-admission-prototype,target=/admission',
                '--env', "AXIOM_INSTALLATION=$installationId", '--env', "AXIOM_CORE_CONTAINER=$coreName",
                '--env', "AXIOM_CORE_IMAGE=$coreImageId", '--env', "AXIOM_SITE=site:$SiteId",
                '--env', "AXIOM_ACTOR=$actor", '--env', "AXIOM_CONTENT=post:$($case.Post)",
                '--env', "AXIOM_TEST_REVOCATION=$revocation", '--env', "AXIOM_FIXTURE_CLI=$cliId",
                '--env', "AXIOM_FIXTURE_TOKEN=$fixtureToken",
                '--entrypoint', 'php', $supervisorImageId, '/app/supervisor/live-test.php')
            $record = $output | ConvertFrom-Json -ErrorAction Stop
            if ($record.outcome -ne $case.Expected) { throw 'Unexpected live access result.' }
            $results += $output
            $output
        } finally {
            Invoke-LiveDocker -DockerArguments @('rm', '-f', $coreName) | Out-Null
        }
    }
    [IO.File]::WriteAllLines((Join-Path $repositoryRoot 'docs/axiompress-live-worker-last.jsonl'), [string[]]$results)
    "PASS: 6 live core/worker cases; retained private fixture post $privatePostId"
} finally {
    Pop-Location
}
