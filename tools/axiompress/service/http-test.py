"""Local operator-only separated HTTP integration, using the existing development DB projection."""

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import secrets
import signal
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[3]
parser = argparse.ArgumentParser()
parser.add_argument('--development-runc', action='store_true')
parser.add_argument('--public', type=int, default=9)
parser.add_argument('--private', type=int, default=25)
parser.add_argument('--disposable-database', action='store_true')
args = parser.parse_args()
runtime = 'runc' if args.development_runc else 'runsc'
pool = secrets.token_hex(16)
prefix = 'axiompress-http-' + pool
service = prefix + '-service'
core = prefix + '-core'
volumes = {name: prefix + '-' + name for name in ('policy', 'state', 'channel')}
containers = []
created_volumes = []
checks = []
database_network = None
temporary = None


def docker(*arguments, timeout=30, input=None):
    result = subprocess.run(['docker', *arguments], input=input, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError('Docker operation failed: ' + result.stderr[-1000:])
    return result.stdout.strip()


def check(name, value):
    if not value:
        raise AssertionError(name)
    checks.append(name)


def get(path, method='GET'):
    request = urllib.request.Request(base + path, method=method)
    try:
        response = urllib.request.urlopen(request, timeout=18)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        data = response.read(262145)
        check('bounded HTTP response', len(data) <= 262144)
        return response.status, json.loads(data) if data else None


def wait_ready():
    for _ in range(60):
        if 'ready runtime=' + runtime in docker('logs', service):
            return
        time.sleep(0.1)
    raise RuntimeError('Service did not become ready')


def disposable_database():
    global database_network, temporary
    if os.name != 'posix':
        raise RuntimeError('Disposable database fixture requires the Linux daemon host')
    (root / '.cache/axiompress-service').mkdir(parents=True, exist_ok=True)
    temporary = tempfile.TemporaryDirectory(prefix='http-db-', dir=root / '.cache/axiompress-service')
    directory = Path(temporary.name)
    password = secrets.token_hex(32)
    root_password = secrets.token_hex(32)
    secret = directory / 'reader-password'
    secret.write_text(password)
    os.chown(secret, 10001, 10001)
    os.chmod(secret, 0o400)
    (directory / 'root-password').write_text(root_password)
    (directory / 'client.cnf').write_text('[client]\nuser=root\npassword=' + root_password + '\n')
    for name in ('root-password', 'client.cnf'):
        os.chmod(directory / name, 0o400)
    database_network = prefix + '-network'
    # A local development bridge permits loopback HTTP publishing; DB itself has no published port.
    docker('network', 'create', database_network)
    name = prefix + '-database'
    containers.append(name)
    docker('run', '-d', '--name', name, '--network', database_network, '--network-alias=mysql',
           '--tmpfs=/var/lib/mysql:rw,size=512m', '--memory=768m', '--pids-limit=128',
           '--env', 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets/root-password',
           '--mount', 'type=bind,source=' + str(directory / 'root-password')
           + ',target=/run/secrets/root-password,readonly',
           '--mount', 'type=bind,source=' + str(directory / 'client.cnf') + ',target=/client.cnf,readonly',
           'mysql@sha256:86b390ef642f89a0157d6215dee33ddc928b8b0ecab173117f56f6c40cb24eab', timeout=180)
    # TCP readiness excludes the transient initialization server, which accepts only socket clients.
    command = ['docker', 'exec', name, 'mysql', '--defaults-extra-file=/client.cnf', '--protocol=TCP',
               '--host=127.0.0.1', '--execute=SELECT 1']
    for _ in range(120):
        ready = subprocess.run(command, capture_output=True, timeout=5)
        if ready.returncode == 0:
            break
        time.sleep(0.5)
    else:
        raise RuntimeError('Disposable database did not become ready')
    sql = """CREATE DATABASE axiompress_phase2 CHARACTER SET utf8mb4;
USE axiompress_phase2;
CREATE TABLE posts (ID BIGINT PRIMARY KEY, post_title TEXT, post_content TEXT, post_status VARCHAR(20),
 post_password VARCHAR(255), post_type VARCHAR(20), post_modified_gmt DATETIME, post_date_gmt DATETIME);
INSERT INTO posts VALUES (9,'Public fixture','<p>Hello κόσμε three words</p>','publish','','post',NOW(),NOW()),
 (25,'Private fixture','Never send to a plugin','private','','post',NOW(),NOW());
CREATE SQL SECURITY DEFINER VIEW axiompress_public_phase2 AS SELECT * FROM posts
 WHERE post_status='publish' AND post_password='' AND post_type IN ('post','page');
CREATE USER 'axiom_phase2_reader'@'%' IDENTIFIED BY '""" + password + """';
GRANT SELECT ON axiompress_phase2.axiompress_public_phase2 TO 'axiom_phase2_reader'@'%';
"""
    docker('exec', '-i', name, 'mysql', '--defaults-extra-file=/client.cnf', input=sql)
    configuration = directory / 'native.env'
    configuration.write_text('AXIOM_DB_HOST=mysql\nAXIOM_DB_PORT=3306\nAXIOM_DB_NAME=axiompress_phase2\n'
        'AXIOM_DB_USER=axiom_phase2_reader\nAXIOM_DB_PASSWORD_FILE=/run/secrets/native-db\n'
        'AXIOM_PUBLIC_VIEW=axiompress_public_phase2\nAXIOM_SITE_ID=1\n')
    return configuration, secret, [database_network]


try:
    info = json.loads(docker('info', '--format', '{{json .Runtimes}}'))
    if runtime not in info:
        raise RuntimeError('Required runtime unavailable; no fallback')
    configuration = root / '.cache/axiompress-native/native.env'
    secret = root / '.cache/axiompress-native/password'
    if not args.disposable_database and (not configuration.is_file() or not secret.is_file()):
        raise RuntimeError('Run native/run-checks.ps1 to provision the local public projection first')
    for target in ('worker-sdk', 'supervisor'):
        docker('build', '--target', target, '-f', str(root / 'tools/axiompress/supervisor/Dockerfile'),
               '-t', 'axiompress-' + target + ':phase2', str(root / 'tools/axiompress'), timeout=180)
    docker('tag', 'axiompress-worker-sdk:phase2', 'axiompress-worker-sdk:prototype')
    sdk = docker('image', 'inspect', 'axiompress-worker-sdk:phase2', '--format', '{{.Id}}')
    image = docker('image', 'inspect', 'axiompress-supervisor:phase2', '--format', '{{.Id}}')
    docker('build', '-f', str(root / 'tools/axiompress/native/Dockerfile'),
           '-t', 'axiompress-native:phase2', str(root), timeout=180)
    native_image = docker('image', 'inspect', 'axiompress-native:phase2', '--format', '{{.Id}}')
    if args.disposable_database:
        configuration, secret, networks = disposable_database()
    else:
        existing = json.loads(docker('inspect', 'axiompress-native'))[0]
        networks = list(existing['NetworkSettings']['Networks'])
    check('single development DB network', len(networks) == 1)
    for volume in volumes.values():
        docker('volume', 'create', volume)
        created_volumes.append(volume)
    mounts = [part for name, volume in volumes.items()
              for part in ('--mount', 'type=volume,source=' + volume + ',target=/' + name)]
    prepare = ('import os,pathlib; '
               '[pathlib.Path(p).mkdir(exist_ok=True) for p in ("/state/admission","/state/workers")]; '
               '[os.chmod(p,0o700) for p in ("/state","/state/admission","/state/workers","/policy")]; '
               'os.chown("/channel",0,10001);os.chmod("/channel",0o750); '
               'pathlib.Path("/state/pool").write_text(os.environ["AXIOM_SERVICE_POOL"])')
    docker('run', '--rm', '--network=none', *mounts, '--env', 'AXIOM_SERVICE_POOL=' + pool,
           '--entrypoint', 'python3', image, '-c', prepare)
    daemon = 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'
    approved_image = docker('run', '--rm', '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=64m',
           '--mount', daemon, *mounts, '--env', 'AXIOM_SDK_IMAGE=' + sdk, '--env', 'AXIOM_SERVICE_TEST=1',
           '--entrypoint', 'php', image, '/app/service/test-setup.php', timeout=90)
    containers.append(service)
    docker('run', '-d', '--name', service, '--network=none', '--read-only', '--user=0:10001',
           '--restart=unless-stopped',
           '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=256m', '--pids-limit=128',
           '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m', '--mount', daemon,
           '--mount', 'type=volume,source=' + volumes['policy'] + ',target=/policy,readonly',
           '--mount', 'type=volume,source=' + volumes['state'] + ',target=/state',
           '--mount', 'type=volume,source=' + volumes['channel'] + ',target=/channel',
           '--env', 'AXIOM_SERVICE_POOL=' + pool, '--env', 'AXIOM_SERVICE_RUNTIME=' + runtime,
           '--env', 'AXIOM_ALLOW_DEV_RUNC=' + ('1' if args.development_runc else '0'),
           '--entrypoint', 'python3', image, '/app/service/server.py')
    service_started = time.monotonic()
    wait_ready()
    containers.append(core)
    docker('run', '-d', '--name', core, '--network', networks[0], '--read-only',
           '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=128m', '--pids-limit=32',
           '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m', '--publish', '127.0.0.1::8080',
           '--env-file', str(configuration), '--env', 'AXIOMPRESS_BROKER_SOCKET=/channel/broker.sock',
           '--env', 'AXIOMPRESS_READING_INSTALLATION=service-reading',
           '--mount', 'type=bind,source=' + str(secret) + ',target=/run/secrets/native-db,readonly',
           '--mount', 'type=volume,source=' + volumes['channel'] + ',target=/channel,readonly', native_image)
    port = docker('port', core, '8080/tcp')
    base = 'http://' + port
    for _ in range(30):
        try:
            if get('/')[0] == 404:
                break
        except urllib.error.URLError:
            time.sleep(0.1)
    else:
        raise RuntimeError('Native HTTP service not ready')
    public = '/v1/sites/1/content/' + str(args.public)
    status, data = get(public)
    check('real article rendered by isolated package', status == 200
          and data.get('reading_summary', '').startswith('<span class="axiompress-reading-summary">'))
    check('private article denied', get('/v1/sites/1/content/' + str(args.private))[0] == 404)
    check('cross-site article denied', get('/v1/sites/2/content/' + str(args.public))[0] == 404)
    check('actor query cannot confer authority', get(public + '?actor=admin')[0] == 404)
    check('HEAD does not execute plugin', get(public, 'HEAD') == (200, None))
    check('writes denied', get(public, 'POST')[0] == 405)
    inspection = json.loads(docker('inspect', core))[0]
    check('core has only read-only secret and channel mounts',
          len(inspection['Mounts']) == 2 and all(not mount['RW'] for mount in inspection['Mounts'])
          and {mount['Destination'] for mount in inspection['Mounts']} == {'/channel', '/run/secrets/native-db'})
    check('core uses unprivileged read-only image', inspection['Config']['User'] == '10001:10001'
          and inspection['HostConfig']['ReadonlyRootfs'])
    policy_mount = 'type=volume,source=' + volumes['policy'] + ',target=/policy'
    hostile = json.loads(docker('run', '--rm', '--network=none', '--read-only', '--mount', policy_mount,
        '--entrypoint', 'php', image, '/app/policy/manage.php', 'describe', 'service-hostile', 'site:1'))
    docker('run', '--rm', '--network=none', '--read-only', '--mount', policy_mount,
        '--entrypoint', 'php', image, '/app/policy/manage.php', 'approve-read',
        'service-reading', 'site:1', '2', hostile['image'])
    # Docker activates restart policy after a successful ten-second startup window.
    automatic_restart = args.disposable_database and os.name == 'posix'
    if automatic_restart:
        time.sleep(max(0, 10.5 - (time.monotonic() - service_started)))
    with ThreadPoolExecutor(max_workers=1) as executor:
        pending = executor.submit(get, public)
        until = time.monotonic() + 3
        while time.monotonic() < until:
            running = docker('ps', '-q', '--filter', 'label=dev.axiompress.service=' + pool)
            if running:
                break
            time.sleep(0.01)
        else:
            raise AssertionError('Did not observe in-flight worker for forced interruption')
        if automatic_restart:
            pid = json.loads(docker('inspect', service))[0]['State']['Pid']
            check('kill targets the owned service process', pid > 1
                  and Path('/proc', str(pid), 'comm').read_text().strip() == 'python3')
            os.kill(pid, signal.SIGKILL)
        else:
            docker('kill', service)
        status, data = pending.result(timeout=18)
        check('broker death keeps public core available', status == 200 and 'extension_error' in data
              and 'reading_summary' not in data)
    # Rollback is a new generation, never replay of the previous generation/worker handles.
    rollback = json.loads(docker('run', '--rm', '--network=none', '--read-only', '--mount', policy_mount,
        '--entrypoint', 'php', image, '/app/policy/manage.php', 'approve-read',
        'service-reading', 'site:1', '3', approved_image))
    check('operator rollback advances generation', rollback['generation'] == 4)
    if automatic_restart:
        for _ in range(50):
            service_state = json.loads(docker('inspect', service))[0]
            if service_state['State']['Running'] and service_state['RestartCount'] > 0:
                break
            time.sleep(0.1)
        else:
            raise AssertionError('Service manager did not restart broker')
        check('service manager automatically restarts broker', True)
    else:
        docker('start', service)
        check('operator restarts broker after manual Docker kill', True)
    # Logs include the old readiness message; the successful retry below is the recovery gate.
    for _ in range(30):
        status, data = get(public)
        if status == 200 and 'reading_summary' in data:
            break
        time.sleep(0.1)
    else:
        raise AssertionError('Service did not recover after forced interruption')
    check('persistent service state recovers after SIGKILL', True)
    docker('stop', '--time=25', service)
    check('pool inventory empty after shutdown', not docker('ps', '-aq', '--filter',
          'label=dev.axiompress.service=' + pool))
    report = {'outcome': 'passed', 'runtime': runtime, 'checks': checks, 'core_image': native_image,
              'supervisor_image': image, 'gvisor_acceptance': False}
finally:
    for name in reversed(containers):
        matching = docker('ps', '-aq', '--filter', 'name=^/' + name + '$').split()
        for identifier in matching:
            docker('rm', '-f', identifier)
    ids = docker('ps', '-aq', '--filter', 'label=dev.axiompress.service=' + pool).split()
    for identifier in ids:
        docker('rm', '-f', identifier)
    for volume in reversed(created_volumes):
        docker('volume', 'rm', volume)
    if database_network:
        docker('network', 'rm', database_network)
    if temporary:
        temporary.cleanup()

(root / 'docs/axiompress-phase2-http-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print('PASS: ' + str(len(checks)) + ' separated HTTP checks; runtime=' + runtime)
