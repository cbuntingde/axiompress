"""Operator-only gVisor control. No Docker client, daemon socket or publisher-selected runtime flags."""

import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import time

MEMORY = 134217728
MODES = ('reading', 'probes', 'hang', 'bad-frame', 'partial', 'stderr', 'memory', 'crash', 'fork', 'extra', 'forged', 'stdout')
NAME = re.compile(r'axiompress-worker-[a-f0-9]{24}')


def digest_file(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def controlled(path, directory=False, private=False):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts or path.is_symlink():
        raise RuntimeError('Absolute operator-controlled path required')
    metadata = path.lstat()
    if metadata.st_uid != 0 or metadata.st_mode & (0o077 if private else 0o022):
        raise RuntimeError('Operator ownership/permissions required')
    if not (stat.S_ISDIR(metadata.st_mode) if directory else stat.S_ISREG(metadata.st_mode)):
        raise RuntimeError('Unexpected operator path type')
    for parent in path.parents:
        record = parent.lstat()
        if not stat.S_ISDIR(record.st_mode) or record.st_uid != 0 or (record.st_mode & 0o022 and not record.st_mode & stat.S_ISVTX):
            raise RuntimeError('Operator-controlled parent directory required')
    return path


def durable_json(path, value):
    data = json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
    with path.open('xb') as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    return data


def inventory(root):
    """Never follow symlinks in an approved runtime filesystem. Hash link targets as data."""
    controlled(root, directory=True)
    root_stat = root.stat()
    if root_stat.st_mode & 0o005 != 0o005:
        raise RuntimeError('Runtime root must be readable/traversable by the unprivileged guest')
    result = {'.': {'type': 'directory', 'mode': stat.S_IMODE(root_stat.st_mode),
                    'uid': root_stat.st_uid, 'gid': root_stat.st_gid}}
    total = 0
    for directory, names, files in os.walk(root, followlinks=False):
        for name in sorted(names + files):
            path = Path(directory) / name
            metadata = path.lstat()
            if metadata.st_uid != 0 or (not stat.S_ISLNK(metadata.st_mode) and metadata.st_mode & 0o022):
                raise RuntimeError('Runtime files must be operator-owned and protected')
            relative = path.relative_to(root).as_posix()
            record = {'mode': stat.S_IMODE(metadata.st_mode), 'uid': metadata.st_uid, 'gid': metadata.st_gid}
            if stat.S_ISLNK(metadata.st_mode):
                record.update(type='link', target=os.readlink(path))
            elif stat.S_ISDIR(metadata.st_mode):
                record['type'] = 'directory'
            elif stat.S_ISREG(metadata.st_mode):
                total += metadata.st_size
                record.update(type='file', bytes=metadata.st_size, sha256=digest_file(path))
            else:
                raise RuntimeError('Runtime filesystem contains a special file')
            result[relative] = record
            if len(result) > 20000 or total > 536870912:
                raise RuntimeError('Runtime filesystem size/count limit')
    return result


def seal(root, destination, kind, package_digest, sdk_digest, package=None):
    """Only the deployment identity calls this on a prepared, approved local filesystem."""
    if kind not in ('plugin', 'image') or not re.fullmatch('[a-f0-9]{64}', package_digest) or not re.fullmatch('[a-f0-9]{64}', sdk_digest):
        raise RuntimeError('Explicit approved package and SDK digests required')
    controlled(destination, directory=True, private=True)
    document = {'format': 'axiompress.runtime/1', 'kind': kind, 'package_digest': package_digest,
                'sdk_digest': sdk_digest, 'package': package, 'files': inventory(root)}
    data = json.dumps(document, sort_keys=True, separators=(',', ':')).encode()
    identifier = hashlib.sha256(data).hexdigest()
    target = destination / identifier
    if target.exists():
        controlled(target, directory=True, private=True)
        if (target / 'manifest.json').read_bytes() != data or inventory(target / 'rootfs') != document['files']:
            raise RuntimeError('Existing runtime differs from its immutable identity')
        return 'sha256:' + identifier
    target.mkdir(mode=0o700)
    # Incomplete artifacts remain private and lack a valid manifest; they cannot launch.
    shutil.copytree(root, target / 'rootfs', symlinks=True)
    if inventory(target / 'rootfs') != document['files']:
        raise RuntimeError('Runtime filesystem changed during approval')
    durable_json(target / 'manifest.json', document)
    return 'sha256:' + identifier


class Runtime:
    def __init__(self, config):
        if sys.platform != 'linux' or os.getuid() != 0:
            raise RuntimeError('Dedicated Linux operator service required')
        path = controlled(config, private=True)
        self.configuration_path = path
        if path.stat().st_size > 16384:
            raise RuntimeError('Runtime configuration size limit')
        self.config = json.loads(path.read_text())
        if set(self.config) != {'runsc', 'runsc_sha256', 'store', 'state', 'cgroup_parent'}:
            raise RuntimeError('Unexpected runtime configuration fields')
        self.binary = controlled(self.config['runsc'])
        if not os.access(self.binary, os.X_OK) or digest_file(self.binary) != self.config['runsc_sha256']:
            raise RuntimeError('Pinned gVisor executable required')
        # Release sidecars are executable host code too; never allow a writable companion.
        inventory(self.binary.parent / 'gvisor-bin')
        self.store = controlled(self.config['store'], directory=True, private=True)
        self.state = controlled(self.config['state'], directory=True, private=True)
        self.cgroups = controlled(self.config['cgroup_parent'], directory=True)
        if not str(self.cgroups).startswith('/sys/fs/cgroup/') or self.cgroups == Path('/sys/fs/cgroup'):
            raise RuntimeError('Dedicated delegated cgroups v2 parent required')
        if not (self.cgroups / 'cgroup.controllers').is_file():
            raise RuntimeError('cgroups v2 required; no fallback')
        for directory in ('runs', 'runsc'):
            (self.state / directory).mkdir(mode=0o700, exist_ok=True)
            controlled(self.state / directory, directory=True, private=True)

    def command(self, *arguments):
        return [str(self.binary), '--root=' + str(self.state / 'runsc'), '--platform=systrap',
                '--network=none', '--file-access=exclusive', '--oci-seccomp=true', *arguments]

    @contextmanager
    def transaction(self):
        # A supervisor may die while a short-lived control helper is still running. Recovery
        # must not erase intent and reopen admission until that helper has stopped mutating it.
        descriptor = os.open(self.state / 'control.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            deadline = time.monotonic() + 2
            while True:
                try:
                    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError('Runtime control is busy; recovery cannot be confirmed') from None
                    time.sleep(0.01)
            yield
        finally:
            os.close(descriptor)

    def artifact(self, identifier):
        if not re.fullmatch('sha256:[a-f0-9]{64}', identifier):
            raise RuntimeError('Approved runtime digest required')
        bundle = controlled(self.store / identifier[7:], directory=True, private=True)
        manifest = controlled(bundle / 'manifest.json', private=True)
        if manifest.stat().st_size > 8388608 or digest_file(manifest) != identifier[7:]:
            raise RuntimeError('Runtime manifest digest mismatch')
        document = json.loads(manifest.read_text())
        if document.get('format') != 'axiompress.runtime/1' or document.get('kind') not in ('plugin', 'image'):
            raise RuntimeError('Unrecognized approved runtime')
        root = controlled(bundle / 'rootfs', directory=True)
        if inventory(root) != document['files']:
            raise RuntimeError('Approved runtime files changed')
        return root, document

    def paths(self, name):
        if not NAME.fullmatch(name):
            raise RuntimeError('Invalid worker identity')
        return self.state / 'runs' / name, self.cgroups / name

    @staticmethod
    def limits(group):
        required = {'memory.max': str(MEMORY), 'memory.swap.max': '0', 'cpu.max': '50000 100000', 'pids.max': '64'}
        for file, value in required.items():
            (group / file).write_text(value)
            if (group / file).read_text().strip() != value:
                raise RuntimeError('Worker resource limit differs from policy')

    def prepare(self, name, identifier, mode='reading'):
        with self.transaction():
            return self._prepare(name, identifier, mode)

    def _prepare(self, name, identifier, mode):
        if mode not in MODES:
            raise RuntimeError('Unsupported trusted worker mode')
        root, document = self.artifact(identifier)
        run, group = self.paths(name)
        run.mkdir(mode=0o700)
        durable_json(run / 'intent.json', {'name': name, 'artifact': identifier})
        group.mkdir(mode=0o700)
        self.limits(group)
        if not (group / 'cgroup.kill').exists():
            raise RuntimeError('Whole-worker cgroup termination required')
        args = ['/usr/local/bin/php', '-n', '-d', 'memory_limit=96M']
        args += ['-d', 'extension=gd', '/app/processor.php'] if document['kind'] == 'image' else ['/app/worker.php']
        denied = ['socket', 'socketpair', 'connect', 'bind', 'listen', 'accept', 'accept4', 'ptrace', 'mount',
                  'umount2', 'pivot_root', 'setns', 'unshare', 'clone3', 'bpf', 'perf_event_open', 'keyctl',
                  'add_key', 'request_key', 'userfaultfd', 'io_uring_setup', 'io_uring_enter', 'io_uring_register']
        specification = {'ociVersion': '1.0.2', 'root': {'path': str(root), 'readonly': True},
            'hostname': 'axiompress-worker', 'process': {'terminal': False, 'user': {'uid': 10001, 'gid': 10001},
                'args': args, 'env': ['PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/tmp', 'LANG=C', 'AXIOM_WORKER_MODE=' + mode],
                'cwd': '/app', 'noNewPrivileges': True,
                'capabilities': {key: [] for key in ('bounding', 'effective', 'inheritable', 'permitted', 'ambient')},
                'rlimits': [{'type': 'RLIMIT_NOFILE', 'hard': 64, 'soft': 64},
                            {'type': 'RLIMIT_NPROC', 'hard': 16, 'soft': 16}]},
            'mounts': [{'destination': '/proc', 'type': 'proc', 'source': 'proc', 'options': ['nosuid', 'nodev', 'noexec']},
                {'destination': '/tmp', 'type': 'tmpfs', 'source': 'tmpfs',
                        'options': ['nosuid', 'nodev', 'noexec',
                                    'size=8m' if document['kind'] == 'image' else 'size=64m', 'mode=1777']},
                       {'destination': '/dev', 'type': 'tmpfs', 'source': 'tmpfs', 'options': ['nosuid', 'noexec', 'mode=755']}],
            'linux': {'namespaces': [{'type': value} for value in ('pid', 'network', 'ipc', 'uts', 'mount')],
                'cgroupsPath': '/' + str(group.relative_to('/sys/fs/cgroup')),
                'resources': {'memory': {'limit': MEMORY, 'swap': MEMORY}, 'cpu': {'quota': 50000, 'period': 100000},
                              'pids': {'limit': 64}},
                'seccomp': {'defaultAction': 'SCMP_ACT_ALLOW', 'syscalls': [{'names': denied, 'action': 'SCMP_ACT_ERRNO', 'errnoRet': 1}]}}}
        durable_json(run / 'config.json', specification)
        return {'backend': 'direct-runsc', 'artifact': identifier, 'memory': MEMORY, 'swap': 0, 'cpus': 0.5,
                'host_tasks': 64, 'guest_tasks': 16, 'uid': 10001, 'readonly': True, 'network': 'none'}

    def start(self, name):
        with self.transaction():
            self._start(name)

    def _start(self, name):
        run, group = self.paths(name)
        controlled(run, directory=True, private=True)
        controlled(run / 'config.json', private=True)
        # Every runtime descendant starts inside the pre-limited cgroup. No daemon can complete
        # a delayed creation after the complete group has been terminated and confirmed empty.
        (group / 'cgroup.procs').write_text(str(os.getpid()))
        self.limits(group)
        os.execve(self.binary, self.command('run', '--bundle=' + str(run), name),
                  {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent', 'LANG': 'C'})

    def remove(self, name):
        with self.transaction():
            return self._remove(name)

    def recover(self):
        with self.transaction():
            result = [self._remove(path.name) for path in (self.state / 'runs').iterdir()]
            if any(path.is_dir() for path in self.cgroups.iterdir()):
                raise RuntimeError('Unrecognized worker cgroup retained; recovery cannot be confirmed')
            listing = subprocess.run(self.command('list', '--format=json'), capture_output=True, check=True, timeout=3)
            if json.loads(listing.stdout) not in (None, []):
                raise RuntimeError('Unrecognized runtime state retained; recovery cannot be confirmed')
            return result

    def _remove(self, name):
        run, group = self.paths(name)
        memory_events = {}
        if group.exists():
            memory_events = {key: int(value) for key, value in
                             (line.split() for line in (group / 'memory.events').read_text().splitlines())}
            (group / 'cgroup.kill').write_text('1')
            deadline = time.monotonic() + 3
            while 'populated 1' in (group / 'cgroup.events').read_text():
                if time.monotonic() >= deadline:
                    raise RuntimeError('Worker termination not confirmed')
                time.sleep(0.01)
        result = subprocess.run(self.command('delete', '--force', name), capture_output=True, timeout=3)
        # An absent state record is acceptable only after the entire worker cgroup is empty.
        listing = subprocess.run(self.command('list', '--format=json'), capture_output=True, check=True, timeout=3)
        records = json.loads(listing.stdout)
        if records is None:
            records = []  # runsc emits JSON null for an empty runtime store.
        if not isinstance(records, list) or any(not isinstance(item, dict) or 'id' not in item for item in records):
            raise RuntimeError('Unrecognized gVisor state inventory')
        if any(item['id'] == name for item in records):
            raise RuntimeError('gVisor cleanup not confirmed')
        if group.exists():
            group.rmdir()
        if run.exists():
            controlled(run, directory=True, private=True)
            if set(path.name for path in run.iterdir()) - {'intent.json', 'config.json'}:
                raise RuntimeError('Unexpected worker state retained for recovery')
            for path in run.iterdir():
                if path.is_symlink() or not path.is_file():
                    raise RuntimeError('Unexpected worker state type')
                path.unlink()
            run.rmdir()
        return {'cleanup_confirmed': True, 'runtime_delete_status': result.returncode,
                'OOMKilled': memory_events.get('oom_kill', 0) > 0, 'memory_events': memory_events}

    def close_test_state(self):
        """Only for an exclusive disposable test store after all worker groups were removed."""
        if any(path.is_dir() for path in self.cgroups.iterdir()) or any((self.state / 'runs').iterdir()):
            raise RuntimeError('Worker state must be empty before test shutdown')
        listing = subprocess.run(self.command('list', '--format=json'), capture_output=True, check=True, timeout=3)
        if json.loads(listing.stdout) not in (None, []):
            raise RuntimeError('Runtime is not idle')
        mount = self.state / 'runsc/null-netns'
        mounted = any(re.sub(r'\\([0-7]{3})', lambda item: chr(int(item[1], 8)), line.split()[4]) == str(mount)
                      for line in Path('/proc/self/mountinfo').read_text().splitlines())
        if mounted:
            # runsc retains its private empty network namespace independently of containers.
            # Never remove a mounted namespace file or recursively erase an uncertain store.
            subprocess.run(['/usr/bin/umount', str(mount)], capture_output=True, check=True, timeout=3)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('operation', choices=('check', 'describe', 'prepare', 'start', 'remove', 'recover'))
    parser.add_argument('name', nargs='?')
    parser.add_argument('artifact', nargs='?')
    parser.add_argument('mode', nargs='?', default='reading')
    options = parser.parse_args()
    runtime = Runtime(options.config)
    if options.operation == 'check':
        result = {'backend': 'direct-runsc', 'docker_required': False}
    elif options.operation == 'describe':
        _, document = runtime.artifact(options.name)
        result = {key: value for key, value in document.items() if key != 'files'}
    elif options.operation == 'prepare':
        result = runtime.prepare(options.name, options.artifact, options.mode)
    elif options.operation == 'start':
        runtime.start(options.name)
        raise RuntimeError('Runtime execution unexpectedly returned')
    elif options.operation == 'recover':
        result = runtime.recover()
    else:
        result = runtime.remove(options.name)
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        raise SystemExit('Direct worker operation failed; operator inspection required') from None
