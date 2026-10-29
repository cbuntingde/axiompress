param([int]$PublicPostId = 9, [int]$PrivatePostId = 25)
# Operator-only local development. Retains a loopback-only native service and its SELECT-only credential.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
function Invoke-NativeDocker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw 'Native Docker operation failed.' }
}
Push-Location -LiteralPath $repositoryRoot
try {
    if (-not (Test-Path -LiteralPath '.cache/axiompress-native/native.env')) {
        $rootSecret = Invoke-NativeDocker -DockerArguments @('compose', '--env-file', '.env', 'exec', '-T',
            'mysql', 'printenv', 'MYSQL_ROOT_PASSWORD')
        try {
            Invoke-NativeDocker -DockerArguments @('compose', '--env-file', '.env', 'exec', '-T',
                '--env', 'AXIOM_SETUP_LOCAL=1', '--env', "AXIOM_LOCAL_ROOT_PASSWORD=$rootSecret",
                'php', 'php', '/var/www/tools/axiompress/native/setup.php')
        } finally {
            $rootSecret = $null
        }
    }
    Invoke-NativeDocker -DockerArguments @('build', '-f', 'tools/axiompress/native/Dockerfile',
        '-t', 'axiompress-native:prototype', '.')
    $image = Invoke-NativeDocker -DockerArguments @('image', 'inspect', 'axiompress-native:prototype', '--format', '{{.Id}}')
    $dbId = Invoke-NativeDocker -DockerArguments @('compose', '--env-file', '.env', 'ps', '-q', 'mysql')
    $db = (Invoke-NativeDocker -DockerArguments @('inspect', $dbId)) | ConvertFrom-Json
    $networks = @($db[0].NetworkSettings.Networks.PSObject.Properties.Name)
    if ($networks.Count -ne 1) { throw 'One local database network required.' }
    Invoke-NativeDocker -DockerArguments @('run', '--rm', '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=16m',
        '--mount', "type=bind,source=$repositoryRoot,target=/work,readonly", '--entrypoint', 'php',
        $image, '/work/tests/axiompress/native-core-test.php')
    $secretMount = "type=bind,source=$repositoryRoot/.cache/axiompress-native/password,target=/run/secrets/native-db,readonly"
    Invoke-NativeDocker -DockerArguments @('run', '--rm', '--network', $networks[0], '--read-only',
        '--env-file', '.cache/axiompress-native/native.env', '--mount', $secretMount,
        '--mount', "type=bind,source=$repositoryRoot/tools/axiompress/native/database-test.php,target=/checks.php,readonly",
        '--entrypoint', 'php', $image, '/checks.php')
    $existing = Invoke-NativeDocker -DockerArguments @('ps', '-aq', '--filter', 'name=^/axiompress-native$')
    if ($existing) {
        $label = Invoke-NativeDocker -DockerArguments @('inspect', $existing, '--format',
            '{{index .Config.Labels "dev.axiompress.prototype"}}')
        if ($label -ne 'native-core-v1') { throw 'Refusing to replace an unrelated native container.' }
        Invoke-NativeDocker -DockerArguments @('rm', '-f', $existing) | Out-Null
    }
    Invoke-NativeDocker -DockerArguments @('run', '-d', '--name', 'axiompress-native',
        '--label', 'dev.axiompress.prototype=native-core-v1', '--network', $networks[0],
        '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
        '--memory=128m', '--memory-swap=128m', '--cpus=1', '--pids-limit=32', '--ulimit=nofile=64:64',
        '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m', '--log-driver=none',
        '--env-file', '.cache/axiompress-native/native.env', '--mount', $secretMount,
        '--publish', '127.0.0.1:8890:8080', $image) | Out-Null
    $ready = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        try {
            $probe = Invoke-WebRequest -Uri 'http://127.0.0.1:8890/' -SkipHttpErrorCheck -TimeoutSec 2
            if ($probe.StatusCode -eq 404) { $ready = $true; break }
        } catch [System.Net.Http.HttpRequestException] {
            Start-Sleep -Milliseconds 100
        }
    }
    if (-not $ready) { throw 'Native entrypoint did not become ready.' }
    $result = & python tests/axiompress/native-http-test.py --public $PublicPostId --private $PrivatePostId
    if ($LASTEXITCODE -ne 0) { throw 'Native HTTP checks failed.' }
    $result
    [IO.File]::WriteAllLines((Join-Path $repositoryRoot 'docs/axiompress-native-http-last.jsonl'), [string[]]$result)
    "Native AxiomPress: http://127.0.0.1:8890/v1/sites/1/content/$PublicPostId"
} finally {
    Pop-Location
}
