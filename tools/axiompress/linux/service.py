"""Trusted Linux extension-service provisioning within a systemd-delegated cgroup only."""

import argparse
import grp
import json
import os
import pwd
from pathlib import Path
import re
import runpy
import secrets
import subprocess

CODE = Path(__file__).resolve().parents[1]
runtime = runpy.run_path(str(CODE / 'runtime/direct.py'))


def load(path):
    path = runtime['controlled'](path, private=True)
    if path.stat().st_size > 16384:
        raise RuntimeError('Service configuration size limit')
    value = json.loads(path.read_text())
    if set(value) != {'format', 'instance', 'runsc', 'runsc_sha256', 'php', 'store', 'policy', 'core_uid'}:
        raise RuntimeError('Unexpected service configuration fields')
    if value['format'] != 'axiompress.extensions-service/1' or not re.fullmatch('axiompress-[a-z0-9-]{1,48}', value['instance']):
        raise RuntimeError('Canonical AxiomPress service instance required')
    if type(value['core_uid']) is not int or not 1 <= value['core_uid'] <= 2147483647:
        raise RuntimeError('Dedicated non-root core UID required')
    try:
        identity = pwd.getpwuid(value['core_uid'])
        grp.getgrgid(value['core_uid'])
    except KeyError:
        raise RuntimeError('Provision the dedicated core user and matching group before installation') from None
    if identity.pw_gid != value['core_uid']:
        raise RuntimeError('The dedicated core UID and primary GID must match')
    for label in ('runsc', 'php', 'store', 'policy'):
        if not re.fullmatch('/[a-zA-Z0-9/_.-]+', value[label]) or '..' in Path(value[label]).parts:
            raise RuntimeError('Canonical absolute service paths required')
        runtime['controlled'](value[label], directory=label == 'store', private=label in ('store', 'policy'))
    if not re.fullmatch('[a-f0-9]{64}', value['runsc_sha256']) or runtime['digest_file'](Path(value['runsc'])) != value['runsc_sha256']:
        raise RuntimeError('Verified gVisor digest required')
    for label in ('php', 'runsc'):
        if not os.access(value[label], os.X_OK):
            raise RuntimeError('Executable host runtime required')
    check = subprocess.run([value['php'], '-r',
        "echo json_encode([PHP_VERSION_ID,PHP_INT_SIZE,extension_loaded('pcntl'),extension_loaded('pdo_sqlite')]);"],
        capture_output=True, timeout=5, check=True, env={'PATH': '/usr/bin:/bin', 'LANG': 'C'})
    if len(check.stdout) > 256:
        raise RuntimeError('Unexpected PHP runtime response')
    version, size, pcntl, sqlite = json.loads(check.stdout)
    if not 80500 <= version < 80600 or size != 8 or pcntl is not True or sqlite is not True:
        raise RuntimeError('64-bit PHP 8.5 with pcntl and PDO SQLite required')
    return value


def unit(configuration, value):
    path = Path(configuration)
    if not all(re.fullmatch('/[a-zA-Z0-9/_.-]+', str(item)) for item in (path, CODE)):
        raise RuntimeError('Canonical configuration and release paths required')
    name = value['instance']
    return f'''[Unit]
Description=AxiomPress isolated extension service
After=local-fs.target
StartLimitIntervalSec=60
StartLimitBurst=3

[Service]
Type=exec
User=root
Group={value['core_uid']}
UMask=0077
ExecStart=/usr/bin/python3 {CODE}/linux/service.py --config {path}
Delegate=cpu memory pids
DelegateSubgroup=controller
StateDirectory=axiompress/{name}
StateDirectoryMode=0700
RuntimeDirectory=axiompress/{name}
RuntimeDirectoryMode=0750
ReadWritePaths=/var/lib/axiompress/{name} /run/axiompress/{name}
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateMounts=yes
PrivateNetwork=yes
# The privileged gVisor launcher requires privilege transitions. Guest processes
# retain noNewPrivileges=true and empty capability sets in the fixed OCI profile.
RestrictRealtime=yes
LockPersonality=yes
MemoryMax=768M
MemorySwapMax=0
TasksMax=384
LimitNOFILE=4096
KillMode=control-group
TimeoutStopSec=70
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
'''


def prepare(value):
    name = value['instance']
    state = runtime['controlled'](Path('/var/lib/axiompress') / name, directory=True, private=True)
    channel = runtime['controlled'](Path('/run/axiompress') / name, directory=True)
    if channel.stat().st_gid != value['core_uid'] or channel.stat().st_mode & 0o777 != 0o750:
        raise RuntimeError('Dedicated core channel permissions required')
    for child in ('admission', 'workers', 'runtime'):
        (state / child).mkdir(mode=0o700, exist_ok=True)
        runtime['controlled'](state / child, directory=True, private=True)
    # Never walk or alter a shared host cgroup parent. systemd places this process in
    # the controller subgroup and delegates only the matching service's descendants.
    entries = Path('/proc/self/cgroup').read_text().splitlines()
    if len(entries) != 1 or not entries[0].startswith('0::/'):
        raise RuntimeError('Unified cgroups v2 required')
    relative = Path(entries[0][3:])
    if '..' in relative.parts or relative.name != 'controller' or relative.parent.name != name + '.service':
        raise RuntimeError('Matching systemd DelegateSubgroup required')
    parent = runtime['controlled'](Path('/sys/fs/cgroup') / str(relative.parent).lstrip('/'), directory=True)
    if not {'cpu', 'memory', 'pids'} <= set((parent / 'cgroup.controllers').read_text().split()):
        raise RuntimeError('CPU, memory and task delegation required')
    (parent / 'cgroup.subtree_control').write_text('+cpu +memory +pids')
    workers = parent / 'workers'
    workers.mkdir(mode=0o700, exist_ok=True)
    runtime['controlled'](workers, directory=True)
    (workers / 'cgroup.subtree_control').write_text('+cpu +memory +pids')
    if not (workers / 'cgroup.kill').is_file() or not {'cpu', 'memory', 'pids'} <= set(
        (workers / 'cgroup.controllers').read_text().split()
    ):
        raise RuntimeError('Whole-worker termination and delegated resource controls required')
    pool_path = state / 'pool'
    if not pool_path.exists():
        with pool_path.open('xb') as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(secrets.token_hex(16).encode())
            stream.flush()
            os.fsync(stream.fileno())
    runtime['controlled'](pool_path, private=True)
    pool = pool_path.read_text()
    if not re.fullmatch('[a-f0-9]{32}', pool):
        raise RuntimeError('Persistent pool identity is invalid; operator recovery required')
    configuration = state / 'runtime.json'
    if configuration.exists() or configuration.is_symlink():
        runtime['controlled'](configuration, private=True)
    temporary = state / ('runtime-' + secrets.token_hex(12) + '.json')
    runtime['durable_json'](temporary, {'runsc': value['runsc'], 'runsc_sha256': value['runsc_sha256'],
        'store': value['store'], 'state': str(state / 'runtime'), 'cgroup_parent': str(workers)})
    os.replace(temporary, configuration)
    descriptor = os.open(state, os.O_DIRECTORY | os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    runtime['Runtime'](configuration)
    return {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'HOME': '/nonexistent',
        'AXIOM_WORKER_BACKEND': 'direct', 'AXIOM_SERVICE_RUNTIME': 'runsc',
        'AXIOM_DIRECT_CONFIG': str(configuration), 'AXIOM_PHP_BINARY': value['php'],
        'AXIOM_POLICY_FILE': value['policy'], 'AXIOM_CORE_UID': str(value['core_uid']),
        'AXIOM_STATE_DIRECTORY': str(state), 'AXIOM_CHANNEL_DIRECTORY': str(channel), 'AXIOM_SERVICE_POOL': pool}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    options = parser.parse_args()
    try:
        settings = load(options.config)
        environment = prepare(settings)
        os.execve('/usr/bin/python3', ['/usr/bin/python3', str(CODE / 'service/server.py')], environment)
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        raise SystemExit('AxiomPress service prerequisites failed; operator inspection required') from None
