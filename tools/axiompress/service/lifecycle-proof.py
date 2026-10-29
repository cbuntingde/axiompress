"""Destructive-to-test-engine fault injection, restricted to the dedicated empty WSL test host."""

import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import struct
import subprocess
import time

if os.environ.get('WSL_DISTRO_NAME') != 'AxiomPress-Linux' or os.getuid() != 0:
    raise RuntimeError('Only root on the dedicated AxiomPress-Linux WSL test host may run this proof')
root = Path(__file__).resolve().parents[3]
pool = secrets.token_hex(16)
name = 'axiompress-lifecycle-' + pool
paths = {key: Path('/run/axiompress-' + pool + '-' + key) for key in ('state', 'policy', 'channel')}
checks = []
paused = False
needs_fence = False
created = False
daemon_pid = None


def docker(*args, timeout=15):
    result = subprocess.run(['docker', *args], capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError(result.stderr[-1000:])
    return result.stdout.strip()


def check(label, condition):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


def request():
    code = '''import socket,struct,json,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(16);s.connect(sys.argv[1])
b=json.dumps({'api':'axiompress.core-broker/0.1','installation':'service-reading','site':'site:1',
 'content':{'content_id':'post:1','revision':'a'*64,'title':'Public','text':'One two three'}}).encode()
s.sendall(struct.pack('!I',len(b))+b);s.shutdown(socket.SHUT_WR)
out=bytearray()
while True:
 b=s.recv(4096)
 if not b:break
 out.extend(b)
sys.stdout.buffer.write(out)
'''
    result = subprocess.run(['python3', '-c', code, str(paths['channel'] / 'broker.sock')],
                            user=10001, group=10001, extra_groups=(), capture_output=True, timeout=18)
    if result.returncode != 0 or len(result.stdout) < 4:
        return None
    length = struct.unpack('!I', result.stdout[:4])[0]
    check('exact response frame', length == len(result.stdout) - 4)
    return json.loads(result.stdout[4:])


def fence():
    global needs_fence
    subprocess.run(['systemctl', 'restart', 'docker'], check=True, timeout=45)
    docker('info', '--format', '{{.ID}}')
    needs_fence = False


try:
    check('dedicated engine has no existing containers', docker('ps', '-aq') == '')
    daemon_pid = int(subprocess.check_output(['systemctl', 'show', 'docker', '--property=MainPID', '--value']))
    check('systemd owns the selected daemon', Path('/proc', str(daemon_pid), 'comm').read_text().strip() == 'dockerd')
    for path in paths.values():
        path.mkdir(mode=0o700)
    os.chown(paths['channel'], 0, 10001)
    paths['channel'].chmod(0o750)
    for directory in ('workers', 'admission'):
        (paths['state'] / directory).mkdir(mode=0o700)
    (paths['state'] / 'pool').write_text(pool)
    image = docker('image', 'inspect', 'axiompress-supervisor:phase2', '--format', '{{.Id}}')
    sdk = docker('image', 'inspect', 'axiompress-worker-sdk:phase2', '--format', '{{.Id}}')
    mounts = [part for key, path in paths.items()
              for part in ('--mount', 'type=bind,source=' + str(path) + ',target=/' + key)]
    daemon = 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'
    docker('run', '--rm', '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=64m',
           '--mount', daemon, *mounts, '--env', 'AXIOM_SDK_IMAGE=' + sdk, '--env', 'AXIOM_SERVICE_TEST=1',
           '--entrypoint', 'php', image, '/app/service/test-setup.php', timeout=90)
    readonly_mounts = [part for key, path in paths.items()
                      for part in ('--mount', 'type=bind,source=' + str(path) + ',target=/' + key
                                   + (',readonly' if key == 'policy' else ''))]
    created = True
    docker('run', '-d', '--name', name, '--restart=unless-stopped', '--network=none', '--read-only',
           '--user=0:10001', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
           '--memory=256m', '--memory-swap=256m', '--cpus=2', '--pids-limit=128',
           '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m', '--mount', daemon, *readonly_mounts,
           '--env', 'AXIOM_SERVICE_POOL=' + pool, '--entrypoint', 'python3', image, '/app/service/server.py')
    for _ in range(60):
        if (paths['channel'] / 'broker.sock').exists():
            break
        time.sleep(0.1)
    else:
        raise AssertionError('Service readiness')
    check('useful invocation before outage', request()['component']['word_count'] == 3)
    # Observe an idle parent after handler reaping/recovery, not a guessed daemon delay.
    service_pid = json.loads(docker('inspect', name))[0]['State']['Pid']
    child_list = Path('/proc', str(service_pid), 'task', str(service_pid), 'children')
    stable = None
    until = time.monotonic() + 5
    while time.monotonic() < until:
        if child_list.read_text().strip():
            stable = None
        else:
            stable = stable or time.monotonic()
            if time.monotonic() - stable > 0.5:
                break
        time.sleep(0.02)
    else:
        raise AssertionError('Service parent did not become idle')
    check('daemon identity remains unchanged', int(subprocess.check_output(
        ['systemctl', 'show', 'docker', '--property=MainPID', '--value'])) == daemon_pid
        and Path('/proc', str(daemon_pid), 'comm').read_text().strip() == 'dockerd')
    os.kill(daemon_pid, signal.SIGSTOP)
    paused = True
    needs_fence = True
    result = request()
    intents = list((paths['state'] / 'workers').glob('axiompress-worker-*'))
    check('daemon outage cannot produce a component', result is None or result == {'error': 'extension_unavailable'})
    check('unknown create intent survives failed client', len(intents) == 1 and intents[0].read_text() == 'creating')
    check('admission capacity remains reserved', len(list((paths['state'] / 'admission').glob('slot-*'))) == 1)
    incident = {'intent': intents[0].name, 'stage': intents[0].read_text(),
                'reservation': json.loads((paths['state'] / 'admission/slot-0').read_text())}
    os.kill(daemon_pid, signal.SIGCONT)
    paused = False
    # The queued create may now complete. Restart attempts must still fail closed on its intent.
    docker('start', name)
    time.sleep(1)
    check('restarted service cannot bypass uncertain create', request() is None
          and intents[0].read_text() == 'creating')
    docker('stop', '--time=25', name, timeout=30)
    fence()
    for identifier in docker('ps', '-aq', '--filter', 'label=dev.axiompress.service=' + pool).split():
        docker('rm', '-f', identifier)
    # Explicit operator resolution only after a real engine fence and pool inventory cleanup.
    intents[0].unlink()
    docker('start', name)
    for _ in range(60):
        if (paths['channel'] / 'broker.sock').exists():
            break
        time.sleep(0.1)
    check('operator-fenced recovery restores useful work', request()['component']['word_count'] == 3)
    report = {'outcome': 'passed', 'checks': checks, 'runtime': 'runsc', 'supervisor_image': image,
              'resolved_incident': incident,
              'scope': 'Dedicated WSL engine only; actual daemon freeze, retained reservation and engine-fenced recovery.'}
finally:
    if paused:
        os.kill(daemon_pid, signal.SIGCONT)
    if needs_fence:
        fence()
    if created:
        docker('rm', '-f', name)
    for identifier in docker('ps', '-aq', '--filter', 'label=dev.axiompress.service=' + pool).split():
        docker('rm', '-f', identifier)
    for path in paths.values():
        if path.exists():
            shutil.rmtree(path)

(root / 'docs/axiompress-phase2-lifecycle-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print('PASS: ' + str(len(checks)) + ' dedicated-engine lifecycle checks')
