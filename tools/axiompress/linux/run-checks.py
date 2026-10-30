"""Install and exercise a temporary systemd service on a dedicated Linux test host."""

import argparse
import grp
import hashlib
import json
import os
import pwd
from pathlib import Path
import runpy
import secrets
import shlex
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[3]


def command(arguments, **keywords):
    result = subprocess.run(arguments, capture_output=True, timeout=keywords.pop('timeout', 30), **keywords)
    if result.returncode:
        raise RuntimeError('Linux installation test command failed: ' + result.stderr.decode(errors='replace')[-1500:])
    return result.stdout


def wait_ready(unit, channel, previous=None):
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        invocation = command(['systemctl', 'show', unit, '--property=InvocationID', '--value']).strip()
        active = subprocess.run(['systemctl', 'is-active', '--quiet', unit], capture_output=True).returncode == 0
        if active and (channel / 'broker.sock').exists() and (previous is None or invocation != previous):
            return invocation
        time.sleep(0.05)
    raise RuntimeError('Installed service did not become ready')


CLIENT = '''import json,socket,struct,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(15);s.connect(sys.argv[1])
b=sys.argv[2].encode();s.sendall(struct.pack('!I',len(b))+b);s.shutdown(socket.SHUT_WR)
r=b''
while True:
 try:c=s.recv(4096)
 except ConnectionResetError:break
 if not c:break
 r+=c
print(r[4:].decode())
'''


def client(channel, installation='service-check', site='site:1', core=True, core_uid=10001):
    request = {'api': 'axiompress.core-broker/0.1', 'installation': installation, 'site': site,
        'content': {'content_id': 'post:4', 'revision': 'a' * 64, 'title': 'Installed service', 'text': 'one two three'}}
    # Drop identity in the child script, avoiding preexec_fn in any threaded test runner.
    identity = ('import os;os.setgroups([]);os.setgid(' + str(core_uid)
                + ');os.setuid(' + str(core_uid) + ');') if core else ''
    return ['/usr/bin/python3', '-c', identity + CLIENT, str(channel / 'broker.sock'), json.dumps(request)]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runsc', required=True, type=Path)
    options = parser.parse_args()
    if os.getuid() != 0 or Path('/proc/1/comm').read_text().strip() != 'systemd':
        raise RuntimeError('Dedicated Linux root test host with systemd required')
    helpers = runpy.run_path(str(ROOT / 'tools/axiompress/runtime/run-checks.py'))
    runtime = runpy.run_path(str(ROOT / 'tools/axiompress/runtime/direct.py'))
    package = runpy.run_path(str(Path(__file__).with_name('package.py')))
    instance = 'axiompress-install-test-' + secrets.token_hex(6)
    account = 'axptest-' + secrets.token_hex(6)
    for core_uid in range(61000, 65000):
        try:
            pwd.getpwuid(core_uid)
        except KeyError:
            try:
                grp.getgrgid(core_uid)
            except KeyError:
                break
    else:
        raise RuntimeError('No unused temporary UID/GID pair available')
    unit = instance + '.service'
    work = Path('/opt') / instance
    work.mkdir(mode=0o711)
    state = Path('/var/lib/axiompress') / instance
    channel = Path('/run/axiompress') / instance
    unit_path = Path('/run/systemd/system') / unit
    dropin = Path('/run/systemd/system') / (unit + '.d')
    installed = False
    account_created = False
    group_created = False
    passed = False
    checks = 0
    try:
        command(['groupadd', '--gid', str(core_uid), account])
        group_created = True
        command(['useradd', '--uid', str(core_uid), '--gid', str(core_uid), '--no-create-home',
                 '--no-log-init', '--shell', '/usr/sbin/nologin', account])
        account_created = True
        sdk = helpers['export_test_files'](work, 'worker-sdk')
        host = helpers['export_test_files'](work, 'supervisor')
        extension = next((host / 'usr/local/lib/php/extensions').iterdir()) / 'pcntl.so'
        php = [str(host / 'lib/ld-musl-x86_64.so.1'), '--library-path',
            ':'.join(str(host / path) for path in ('usr/local/lib', 'usr/lib', 'lib')),
            str(host / 'usr/local/bin/php'), '-n', '-d', 'extension=' + str(extension)]
        executable = work / 'php'
        executable.write_text('#!/bin/sh\nexec ' + shlex.join(php) + ' "$@"\n')
        executable.chmod(0o700)
        release = work / 'release'
        digest = package['build'](release)
        package['verify'](release, digest)
        checks += 1
        if any((release / name).exists() for name in ('docs', '.env', 'src/wp-admin',
            'tools/axiompress/runtime/run-checks.py')):
            raise RuntimeError('Local/private or development files entered the service release')
        checks += 1
        if package['build'](work / 'repeat-release') != digest:
            raise RuntimeError('Repeated packaging changed the release digest')
        checks += 1
        try:
            package['verify'](release, '0' * 64)
        except RuntimeError:
            checks += 1
        else:
            raise RuntimeError('Invalid release digest was accepted')
        changed = release / 'FORK_PROVENANCE.md'
        original = changed.read_bytes()
        try:
            changed.write_bytes(original + b'\n')
            try:
                package['verify'](release, digest)
            except RuntimeError:
                checks += 1
            else:
                raise RuntimeError('Changed release content was accepted')
        finally:
            changed.write_bytes(original)
        code = release / 'tools/axiompress'
        approved = work / 'approved-package'
        shutil.copytree(ROOT / 'tools/axiompress/packages/reading-summary', approved)
        helpers['protected_tree'](approved)
        store = work / 'store'
        store.mkdir(mode=0o700)
        sdk_digest = hashlib.sha256(json.dumps(runtime['inventory'](sdk), sort_keys=True,
            separators=(',', ':')).encode()).hexdigest()
        artifact = runpy.run_path(str(code / 'runtime/package.py'))['build'](sdk, sdk_digest, approved, store, php)['artifact']
        policy = work / 'policy.sqlite'
        command([*php, '-r', 'require $argv[1];new AxiomPress\\Policy\\PolicyStore($argv[2]);',
                 str(code / 'policy/bootstrap.php'), str(policy)])
        configuration = work / 'service.json'
        settings = {'format': 'axiompress.extensions-service/1', 'instance': instance,
            'runsc': str(options.runsc), 'runsc_sha256': runtime['digest_file'](options.runsc),
            'php': str(executable), 'store': str(store), 'policy': str(policy), 'core_uid': core_uid}
        runtime['durable_json'](configuration, settings)
        installer = runpy.run_path(str(code / 'linux/install.py'))['install']
        for replacement in ({'core_uid': 0}, {'runsc_sha256': '0' * 64}):
            rejected = work / ('invalid-' + secrets.token_hex(8) + '.json')
            runtime['durable_json'](rejected, {**settings, **replacement})
            try:
                installer(release, digest, rejected, unit_path.parent)
            except RuntimeError:
                if unit_path.exists():
                    raise RuntimeError('Rejected installation created a unit') from None
                checks += 1
            else:
                raise RuntimeError('Invalid service prerequisites were accepted')
        installer(release, digest, configuration, unit_path.parent)
        installed = True
        if subprocess.run(['systemctl', 'is-enabled', unit], capture_output=True).stdout.strip() != b'disabled':
            raise RuntimeError('Installation enabled the service without operator action')
        checks += 1
        try:
            installer(release, digest, configuration, unit_path.parent)
        except RuntimeError:
            checks += 1
        else:
            raise RuntimeError('Installer overwrote an existing unit')
        command(['systemctl', 'start', unit])
        invocation = wait_ready(unit, channel)
        checks += 1
        if command(client(channel, core=False, core_uid=core_uid)).strip():
            raise RuntimeError('Installed service accepted the wrong Unix peer')
        checks += 1
        command([*php, str(code / 'runtime/register.php'), str(state / 'runtime.json'), artifact,
                 str(approved), str(policy), 'service-check', 'site:1', 'create'])
        if json.loads(command(client(channel, core_uid=core_uid))) != {'error': 'extension_unavailable'}:
            raise RuntimeError('Installed service ran an unapproved installation')
        checks += 1
        environment = {**os.environ, 'AXIOM_POLICY_FILE': str(policy)}
        command([*php, str(code / 'policy/manage.php'), 'approve-read', 'service-check', 'site:1', '1', artifact], env=environment)
        response = json.loads(command(client(channel, core_uid=core_uid)))
        if response.get('component', {}).get('word_count') != 3:
            diagnostic_message = command(['journalctl', '-u', unit, '-n', '16', '--no-pager']).decode(errors='replace')
            raise RuntimeError('Installed service failed useful plugin execution: ' + diagnostic_message)
        checks += 1
        pid = int(command(['systemctl', 'show', unit, '--property=MainPID', '--value']).strip())
        probe = "import errno,pathlib,sys; p=pathlib.Path(sys.argv[1]);\ntry:p.write_text('blocked')\nexcept OSError as e:sys.exit(0 if e.errno==errno.EROFS else 2)\np.unlink();sys.exit(3)"
        for target in (release / 'write-probe', work / 'policy-write-probe'):
            command(['nsenter', '--target', str(pid), '--mount', '--', '/usr/bin/python3', '-c', probe, str(target)])
            checks += 1
        pool = (state / 'pool').read_text()
        command(['systemctl', 'restart', unit])
        invocation = wait_ready(unit, channel, invocation)
        if (state / 'pool').read_text() != pool or json.loads(command(client(channel, core_uid=core_uid)))['component']['word_count'] != 3:
            raise RuntimeError('Installed service lost its persistent identity on restart')
        checks += 1
        builder = runpy.run_path(str(code / 'runtime/package.py'))['build']
        for purpose, site in (('probes', 'site:3'), ('hang', 'site:4')):
            hostile = work / purpose
            shutil.copytree(approved, hostile)
            manifest = json.loads((hostile / 'manifest.json').read_text())
            manifest['plugin_id'] = 'axiompress/installed-' + purpose
            (hostile / 'manifest.json').write_text(json.dumps(manifest))
            worker = hostile / 'worker.php'
            if purpose == 'probes':
                worker.write_text((code / 'supervisor/worker.php').read_text().replace(
                    "$mode = getenv('AXIOM_WORKER_MODE');", "$mode = 'probes';"))
            else:
                worker.write_text(worker.read_text().replace('declare(strict_types=1);',
                    'declare(strict_types=1);\nsleep(30);'))
            helpers['protected_tree'](hostile)
            binding = builder(sdk, sdk_digest, hostile, store, php)['artifact']
            installation = 'installed-' + purpose
            command([*php, str(code / 'runtime/register.php'), str(state / 'runtime.json'), binding,
                     str(hostile), str(policy), installation, site, 'create'])
            command([*php, str(code / 'policy/manage.php'), 'approve-read', installation, site, '1', binding], env=environment)
        if json.loads(command(client(channel, 'installed-probes', 'site:3', core_uid=core_uid)))['component']['word_count'] != 14:
            raise RuntimeError('Installed guest privilege, network or filesystem restrictions failed')
        checks += 1
        pending = subprocess.Popen(client(channel, 'installed-hang', 'site:4', core_uid=core_uid),
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            control = runtime['Runtime'](state / 'runtime.json')
            deadline = time.monotonic() + 4
            running = False
            while not running:
                if pending.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError('Installed hostile worker did not start')
                for worker in (state / 'runtime/runs').iterdir():
                    status = subprocess.run(control.command('state', worker.name), capture_output=True, timeout=1)
                    if status.returncode == 0 and json.loads(status.stdout).get('status') == 'running':
                        running = True
                time.sleep(0.01)
            command(['systemctl', 'kill', '--kill-whom=all', '--signal=SIGKILL', unit])
            invocation = wait_ready(unit, channel, invocation)
            if any((state / 'runtime/runs').iterdir()) or list((state / 'admission').glob('slot-*')):
                raise RuntimeError('Forced restart did not reconcile persistent worker/admission state')
            if json.loads(command(client(channel, core_uid=core_uid)))['component']['word_count'] != 3:
                raise RuntimeError('Forced restart did not restore useful execution')
            checks += 1
        finally:
            if pending.poll() is None:
                pending.kill()
            pending.communicate(timeout=3)
        # Remove delegated placement only for this temporary service; startup must refuse it.
        command(['systemctl', 'stop', unit])
        dropin.mkdir()
        (dropin / 'reject-missing-delegation.conf').write_text('[Service]\nDelegateSubgroup=\nRestart=no\n')
        command(['systemctl', 'daemon-reload'])
        subprocess.run(['systemctl', 'start', unit], capture_output=True, timeout=10)
        deadline = time.monotonic() + 8
        while time.monotonic() < deadline:
            status = command(['systemctl', 'show', unit, '--property=ActiveState', '--value']).strip()
            if status == b'failed':
                break
            time.sleep(0.05)
        else:
            raise RuntimeError('Service did not refuse missing delegated controller placement')
        if (channel / 'broker.sock').exists():
            raise RuntimeError('Failed isolation prerequisites left a live service socket')
        checks += 1
        passed = True
    finally:
        if installed:
            command(['systemctl', 'stop', unit])
            if unit_path.is_symlink() or unit_path.parent != Path('/run/systemd/system'):
                raise RuntimeError('Unexpected test unit target')
            unit_path.unlink()
            if dropin.exists():
                (dropin / 'reject-missing-delegation.conf').unlink()
                dropin.rmdir()
            command(['systemctl', 'daemon-reload'])
            subprocess.run(['systemctl', 'reset-failed', unit], capture_output=True, timeout=5)
        mounts = Path('/proc/self/mountinfo').read_text()
        for target, parent in ((work, Path('/opt')), (state, Path('/var/lib/axiompress'))):
            if str(target) in mounts or target.is_symlink() or target.parent != parent or target.resolve() != target:
                raise RuntimeError('Uncertain test installation state retained for operator recovery')
        if Path('/sys/fs/cgroup/system.slice/' + unit).exists():
            raise RuntimeError('Service cgroup still exists; retained test installation')
        if account_created:
            command(['userdel', account])
        if group_created:
            try:
                remaining = grp.getgrnam(account)
            except KeyError:
                remaining = None  # userdel can also remove its now-unused matching group.
            if remaining is not None:
                if remaining.gr_gid != core_uid:
                    raise RuntimeError('Temporary group identity changed')
                command(['groupdel', account])
        if passed:
            for target in (state, work):
                if target.exists():
                    shutil.rmtree(target)
    report = {'outcome': 'passed', 'checks': checks, 'systemd_service': True,
              'docker_required_for_service_execution': False, 'production_acceptance': False}
    (ROOT / 'docs/axiompress-linux-install-last.json').write_text(json.dumps(report, indent=2))
    print('PASS: ' + str(checks) + ' Linux service installation checks')


if __name__ == '__main__':
    main()
