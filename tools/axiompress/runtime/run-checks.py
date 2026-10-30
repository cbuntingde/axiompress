"""Direct gVisor/PHP acceptance on Linux. Docker is optional for preparing local test files only."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import posixpath
import runpy
import secrets
import shlex
import shutil
import socket
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import time

ROOT = Path(__file__).resolve().parents[3]


def command(arguments, **kwargs):
    result = subprocess.run(arguments, capture_output=True, timeout=kwargs.pop('timeout', 30), **kwargs)
    if result.returncode:
        detail = result.stderr if isinstance(result.stderr, str) else result.stderr.decode(errors='replace')
        output = result.stdout if isinstance(result.stdout, str) else result.stdout.decode(errors='replace')
        raise RuntimeError('Direct runtime test command failed: ' + (detail + output)[-3000:])
    return result


def protected_tree(root):
    for directory, names, files in os.walk(root, followlinks=False):
        for file in [Path(directory), *(Path(directory) / name for name in names + files)]:
            if not file.is_symlink():
                os.chmod(file, stat.S_IMODE(file.stat().st_mode) & ~0o022)


def service_checks(work, code, php, configuration, artifact, policy):
    """Exercise the real Unix peer boundary and restart recovery without a Docker command."""
    channel = work / 'channel'
    channel.mkdir(mode=0o750)
    os.chown(channel, 0, 10001)
    os.chmod(work, 0o711)
    state = work / 'service-state'
    state.mkdir(mode=0o700)
    for name in ('admission', 'workers'):
        (state / name).mkdir(mode=0o700)
    pool = secrets.token_hex(16)
    (state / 'pool').write_text(pool)
    executable = work / 'php'
    executable.write_text('#!/bin/sh\nexec ' + shlex.join(php) + ' "$@"\n')
    executable.chmod(0o700)
    environment = {**os.environ, 'PATH': '/nonexistent', 'AXIOM_WORKER_BACKEND': 'direct',
                   'AXIOM_SERVICE_RUNTIME': 'runsc', 'AXIOM_DIRECT_CONFIG': str(configuration),
                   'AXIOM_STATE_DIRECTORY': str(state), 'AXIOM_CHANNEL_DIRECTORY': str(channel),
                   'AXIOM_POLICY_FILE': str(policy), 'AXIOM_PHP_BINARY': str(executable),
                   'AXIOM_SERVICE_POOL': pool, 'AXIOM_CORE_UID': '10001'}
    request = {'api': 'axiompress.core-broker/0.1', 'installation': 'direct-check', 'site': 'site:1',
               'content': {'content_id': 'post:4', 'revision': 'a' * 64,
                           'title': 'Direct service', 'text': 'one two three'}}
    # This small client is the only process given the core UID. No operator files are readable.
    client = '''import json,socket,struct,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(10);s.connect(sys.argv[1])
b=sys.argv[2].encode();s.sendall(struct.pack('!I',len(b))+b);s.shutdown(socket.SHUT_WR)
r=b''
while True:
 try:c=s.recv(4096)
 except ConnectionResetError:break
 if not c:break
 r+=c
print(r[4:].decode())
'''
    def exchange(core=True, value=None):
        def identity():
            os.setgroups([])
            os.setgid(10001)
            os.setuid(10001)
        result = command(['/usr/bin/python3', '-c', client, str(channel / 'broker.sock'),
                          json.dumps(request if value is None else value)], text=True, env=environment,
                         preexec_fn=identity if core else None, timeout=15)
        return json.loads(result.stdout) if result.stdout.strip() else None

    for attempt in range(2):
        process = subprocess.Popen(['/usr/bin/python3', str(code / 'service/server.py')], env=environment,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, group=10001)
        try:
            deadline = time.monotonic() + 8
            while not (channel / 'broker.sock').exists():
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError('Direct service startup failed: ' + process.communicate()[1].decode()[-1000:])
                time.sleep(0.02)
            if exchange(core=False) is not None:
                raise RuntimeError('Service accepted the wrong Unix peer')
            if attempt == 0:
                if exchange() != {'error': 'extension_unavailable'}:
                    raise RuntimeError('Service accepted a revoked installation')
                command([*php, str(code / 'policy/manage.php'), 'approve-read', 'direct-check', 'site:1',
                         '3', artifact], env=environment)
            response = exchange()
            if response.get('component', {}).get('word_count') != 3:
                raise RuntimeError('Direct service failed approved broker invocation')
            forged = {**request, 'actor': 'administrator'}
            if exchange(value=forged) != {'error': 'extension_unavailable'}:
                raise RuntimeError('Direct service accepted forged authority')
        finally:
            process.terminate()
            try:
                _, error = process.communicate(timeout=15)
            except subprocess.TimeoutExpired:
                process.kill()
                process.communicate()
                raise RuntimeError('Direct service did not stop within its cleanup deadline') from None
            if process.returncode != 0:
                raise RuntimeError('Direct service stopped unsuccessfully: ' + error.decode()[-1000:])
    return 7


def export_test_files(work, target):
    tag = 'axiompress-direct-checks-' + secrets.token_hex(8) + '-' + target
    build = ['docker', 'build']
    if target in ('media', 'jobs'):
        build += ['-f', str(ROOT / ('tools/axiompress/' + target + '/Dockerfile')), '-t', tag, str(ROOT)]
    else:
        build += ['--target', target, '-f', str(ROOT / 'tools/axiompress/supervisor/Dockerfile'),
                  '-t', tag, str(ROOT / 'tools/axiompress')]
    command(build, timeout=240)
    identifier = command(['docker', 'create', tag], text=True).stdout.strip()
    archive = work / (target + '.tar')
    try:
        command(['docker', 'export', '--output', str(archive), identifier], timeout=60)
    finally:
        command(['docker', 'rm', identifier])
        command(['docker', 'image', 'rm', tag])
    destination = work / target
    destination.mkdir(mode=0o755)
    with tarfile.open(archive) as source:
        members = source.getmembers()
        if len(members) > 20000 or sum(member.size for member in members) > 536870912:
            raise RuntimeError('Local SDK export bounds exceeded')
        for member in members:
            if member.issym() and member.linkname.startswith('/'):
                # Keep root-relative guest links inside the private extraction directory.
                member.linkname = posixpath.relpath(member.linkname.lstrip('/'), posixpath.dirname(member.name) or '.')
        source.extractall(destination, members=members, filter='data')
    archive.unlink()
    protected_tree(destination)
    return destination


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runsc', type=Path, required=True)
    parser.add_argument('--sdk-root', type=Path)
    parser.add_argument('--media-sdk-root', type=Path)
    parser.add_argument('--php', default='/usr/bin/php8.5')
    parser.add_argument('--prepare-with-docker', action='store_true')
    options = parser.parse_args()
    if os.getuid() != 0 or not Path('/sys/fs/cgroup/cgroup.controllers').exists():
        raise RuntimeError('Linux operator with delegated cgroups v2 required')
    report = None
    work = Path(tempfile.mkdtemp(prefix='axiompress-direct-', dir='/var/tmp'))
    try:
        group = Path('/sys/fs/cgroup') / ('axiompress-direct-' + secrets.token_hex(8))
        group.mkdir(mode=0o700)
        (group / 'cgroup.subtree_control').write_text('+cpu +memory +pids')
        runtime = runpy.run_path(str(Path(__file__).with_name('direct.py')))
        control = None
        try:
            if options.prepare_with_docker:
                sdk = export_test_files(work, 'worker-sdk')
                host = export_test_files(work, 'supervisor')
                media_sdk = export_test_files(work, 'media')
                extensions = next((host / 'usr/local/lib/php/extensions').iterdir())
                php = [str(host / 'lib/ld-musl-x86_64.so.1'), '--library-path',
                    ':'.join(str(host / path) for path in ('usr/local/lib', 'usr/lib', 'lib')),
                    str(host / 'usr/local/bin/php'), '-n', '-d', 'extension=' + str(extensions / 'pcntl.so')]
            else:
                if options.sdk_root is None:
                    raise RuntimeError('An approved SDK root is required without Docker test preparation')
                sdk = options.sdk_root
                media_sdk = options.media_sdk_root
                if media_sdk is None:
                    raise RuntimeError('An approved PHP/GD filesystem is required for image processing checks')
                php = [options.php]
            code = work / 'code'
            shutil.copytree(ROOT / 'tools/axiompress', code, ignore=shutil.ignore_patterns('__pycache__'))
            protected_tree(code)
            package = work / 'package'
            shutil.copytree(ROOT / 'tools/axiompress/packages/reading-summary', package)
            protected_tree(package)
            store, state, admission = (work / name for name in ('store', 'state', 'admission'))
            for directory in (store, state, admission):
                directory.mkdir(mode=0o700)
            sdk_digest = hashlib.sha256(json.dumps(runtime['inventory'](sdk), sort_keys=True,
                separators=(',', ':')).encode()).hexdigest()
            builder = runpy.run_path(str(code / 'runtime/package.py'))['build']
            built = builder(sdk, sdk_digest, package, store, php)
            hostile_package = work / 'hostile-package'
            hostile_package.mkdir(mode=0o700)
            manifest = json.loads((package / 'manifest.json').read_text())
            manifest['plugin_id'] = 'axiompress/runtime-check'
            (hostile_package / 'manifest.json').write_text(json.dumps(manifest))
            shutil.copyfile(code / 'supervisor/worker.php', hostile_package / 'worker.php')
            protected_tree(hostile_package)
            hostile = builder(sdk, sdk_digest, hostile_package, store, php)
            config = work / 'runtime.json'
            runtime['durable_json'](config, {'runsc': str(options.runsc), 'runsc_sha256': runtime['digest_file'](options.runsc),
                'store': str(store), 'state': str(state), 'cgroup_parent': str(group)})
            control = runtime['Runtime'](config)
            test_config = work / 'test.json'
            runtime['durable_json'](test_config, {'config': str(config), 'package': str(package),
                'artifact': built['artifact'], 'hostile_artifact': hostile['artifact'],
                'policy': str(work / 'policy.sqlite'), 'admission': str(admission)})
            # PHP/control subprocesses have no Docker command on PATH. runsc is invoked by its
            # absolute approved path; every worker uses the direct runtime, not a Docker daemon.
            result = command([*php, str(ROOT / 'tests/axiompress/direct-runtime-test.php'), str(code), str(test_config)],
                             env={**os.environ, 'PATH': '/nonexistent'}, text=True, timeout=100)
            report = json.loads(result.stdout)
            approved_worker = store / built['artifact'][7:] / 'rootfs/app/worker.php'
            approved_bytes = approved_worker.read_bytes()
            try:
                approved_worker.write_bytes(approved_bytes + b'\n')
                try:
                    control.artifact(built['artifact'])
                except RuntimeError:
                    pass
                else:
                    raise RuntimeError('Modified approved worker filesystem was accepted')
            finally:
                approved_worker.write_bytes(approved_bytes)
            report['checks'] += 1
            lock_ready = work / 'control-ready'
            locked = subprocess.Popen(['/usr/bin/python3', '-c',
                'import fcntl,pathlib,sys,time; f=open(sys.argv[1],"a");fcntl.flock(f,fcntl.LOCK_EX);'
                'pathlib.Path(sys.argv[2]).touch();time.sleep(10)', str(state / 'control.lock'), str(lock_ready)])
            try:
                deadline = time.monotonic() + 3
                while not lock_ready.exists():
                    if locked.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Runtime control ownership check did not start')
                    time.sleep(0.01)
                try:
                    control.recover()
                except RuntimeError as error:
                    if 'control is busy' not in str(error):
                        raise
                else:
                    raise RuntimeError('Recovery bypassed a live runtime control helper')
            finally:
                locked.kill()
                locked.wait(timeout=3)
            control.recover()
            report['checks'] += 2
            unknown = group / 'unrecognized-worker'
            unknown.mkdir()
            try:
                try:
                    control.recover()
                except RuntimeError as error:
                    if 'Unrecognized worker cgroup' not in str(error):
                        raise
                else:
                    raise RuntimeError('Recovery ignored unrecognized host worker state')
            finally:
                unknown.rmdir()
            report['checks'] += 1
            report['service_checks'] = service_checks(work, code, php, config, built['artifact'], work / 'policy.sqlite')
            report['checks'] += report['service_checks']
            # Recover a real running worker after losing its attachment process.
            orphan = 'axiompress-worker-' + secrets.token_hex(12)
            control.prepare(orphan, hostile['artifact'], 'hang')
            attached = subprocess.Popen(['/usr/bin/python3', str(code / 'runtime/direct.py'),
                                         '--config', str(config), 'start', orphan],
                                        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            try:
                deadline = time.monotonic() + 4
                while True:
                    if attached.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Recovery check worker did not start')
                    status = subprocess.run(control.command('state', orphan), capture_output=True, timeout=2)
                    if status.returncode == 0 and json.loads(status.stdout).get('status') == 'running':
                        break
                    time.sleep(0.02)
                required = {'memory.max': '134217728', 'memory.swap.max': '0',
                            'cpu.max': '50000 100000', 'pids.max': '64'}
                for file, value in required.items():
                    if (group / orphan / file).read_text().strip() != value:
                        raise RuntimeError('Running gVisor changed the approved host resource limits')
                attached.kill()
                attached.wait(timeout=3)
                control.remove(orphan)
                if (group / orphan).exists() or (state / 'runs' / orphan).exists():
                    raise RuntimeError('Interrupted worker recovery was not confirmed')
            finally:
                attached.kill() if attached.poll() is None else None
                attached.communicate(timeout=3)
            report['checks'] += 2
            media_digest = hashlib.sha256(json.dumps(runtime['inventory'](media_sdk), sort_keys=True,
                separators=(',', ':')).encode()).hexdigest()
            image_builder = runpy.run_path(str(code / 'runtime/image.py'))['build']
            image = image_builder(media_sdk, media_digest, store)
            media_state = work / 'media-state'
            media_state.mkdir(mode=0o700)
            transform = runpy.run_path(str(code / 'media/runtime.py'))['transform']
            extension = next((media_sdk / 'usr/local/lib/php/extensions').iterdir()) / 'gd.so'
            media_php = [str(media_sdk / 'lib/ld-musl-x86_64.so.1'), '--library-path',
                         ':'.join(str(media_sdk / path) for path in ('usr/local/lib', 'usr/lib', 'lib')),
                         str(media_sdk / 'usr/local/bin/php'), '-n', '-d', 'extension=' + str(extension)]
            def no_docker(*_arguments, **_keywords):
                raise RuntimeError('Image processing attempted to use Docker')
            media_cases = {}
            for encoding in ('png', 'jpeg', 'webp', 'invalid'):
                if encoding == 'invalid':
                    source = b'not an image'
                else:
                    source = command([*media_php, '-r', '$i=imagecreatetruecolor(8,6);image' + encoding + '($i);']).stdout
                result = transform(no_docker, image, source, 'axiompress-media-' + secrets.token_hex(16),
                                   media_state, control)
                if encoding == 'invalid':
                    if result != {'error': 'invalid_image'}:
                        raise RuntimeError('Invalid image was accepted')
                elif result['width'] != 8 or result['height'] != 6:
                    raise RuntimeError('Direct image processing changed dimensions unexpectedly')
                media_cases[encoding] = 'passed'
            report['media_checks'] = media_cases
            report['checks'] += len(media_cases)
            report.update(docker_required_for_execution=False, docker_used_for_test_preparation=options.prepare_with_docker,
                          runtime='runsc', artifact=built['artifact'], production_acceptance=False)
        finally:
            if control is not None:
                for path in (control.state / 'runs').iterdir():
                    control.remove(path.name)
                control.close_test_state()
            group.rmdir()
        shutil.rmtree(work)
    except BaseException:
        # Preserve complete files if cleanup could not be confirmed. They are operator-owned,
        # not deleted by TemporaryDirectory while namespace mounts or worker state remain.
        raise RuntimeError('Direct check failed; retained operator state at ' + str(work)) from sys.exception()
    (ROOT / 'docs').mkdir(exist_ok=True)
    (ROOT / 'docs/axiompress-direct-runtime-last.json').write_text(json.dumps(report, indent=2))
    print('PASS: ' + str(report['checks']) + ' direct gVisor/PHP checks')


if __name__ == '__main__':
    main()
