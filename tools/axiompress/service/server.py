"""Linux-only trusted broker entry point. No HTTP listener or application runtime authority."""

import fcntl
import json
import os
import re
from pathlib import Path
import signal
import socket
import stat
import struct
import subprocess
import time

MAX_REQUEST = 200000
MAX_HANDLERS = 4
SOCKET = Path('/channel/broker.sock')
STATE = Path('/state')
APP = Path(__file__).resolve().parent


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate field')
        result[key] = value
    return result


def exact(connection, size, deadline):
    result = bytearray()
    while len(result) < size:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError('frame deadline')
        connection.settimeout(remaining)
        chunk = connection.recv(min(8192, size - len(result)))
        if not chunk:
            raise ValueError('incomplete frame')
        result.extend(chunk)
    return bytes(result)


def handle(connection, core_uid):
    # SO_PEERCRED is read from the accepted Linux socket, never from request JSON.
    _, uid, _ = struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
    if uid != core_uid:
        return
    deadline = time.monotonic() + 2
    length = struct.unpack('!I', exact(connection, 4, deadline))[0]
    if not 1 <= length <= MAX_REQUEST:
        return
    body = exact(connection, length, deadline)
    json.loads(body, object_pairs_hook=unique_object)
    # Require a half-close: one invocation, no pipelining, no trailing claims.
    connection.settimeout(max(0.001, deadline - time.monotonic()))
    if connection.recv(1):
        return
    process = subprocess.Popen(['php', str(APP / 'invoke.php')], stdin=subprocess.PIPE,
                               stdout=subprocess.PIPE, stderr=None, start_new_session=True)
    try:
        output, _ = process.communicate(body, timeout=12)
        if process.returncode != 0 or len(output) > 1024:
            output = b'{"error":"extension_unavailable"}'
        else:
            # Trusted executor emits a single small object; reject unexpected output.
            decoded = json.loads(output)
            if not isinstance(decoded, dict) or set(decoded) != {'component'}:
                raise ValueError('invalid executor output')
        connection.settimeout(1)
        connection.sendall(struct.pack('!I', len(output)) + output)
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait()


def recover():
    subprocess.run(['php', str(APP / 'recover.php')], check=True, timeout=20)


def main():
    if os.name != 'posix' or not hasattr(socket, 'SO_PEERCRED'):
        raise RuntimeError('Linux peer credentials required')
    core_uid = int(os.environ.get('AXIOM_CORE_UID', '10001'))
    if core_uid < 1 or core_uid == os.getuid():
        raise RuntimeError('Dedicated non-root core UID required')
    if os.getuid() != 0 or os.getgid() != core_uid:
        raise RuntimeError('Initial service identity must be UID 0 with the dedicated core GID')
    runtime = os.environ.get('AXIOM_SERVICE_RUNTIME', 'runsc')
    if runtime not in ('runsc', 'runc') or (
        runtime == 'runc' and os.environ.get('AXIOM_ALLOW_DEV_RUNC') != '1'
    ):
        raise RuntimeError('gVisor required; runc is an explicit development test only')
    info = json.loads(subprocess.check_output(['docker', 'info', '--format', '{{json .}}'], timeout=5))
    if runtime not in info['Runtimes'] or info.get('CgroupVersion') != '2':
        raise RuntimeError('Selected runtime and cgroups v2 must be available; no fallback')
    for directory in (STATE, STATE / 'admission', STATE / 'workers', SOCKET.parent):
        if directory.is_symlink() or not directory.is_dir():
            raise RuntimeError('Operator-provisioned private directories required')
        metadata = directory.stat()
        if metadata.st_uid != os.getuid() or metadata.st_mode & 0o022:
            raise RuntimeError('Service directories must be owner-controlled')
    pool = os.environ.get('AXIOM_SERVICE_POOL', '')
    pool_file = STATE / 'pool'
    if not re.fullmatch('[a-f0-9]{32}', pool) or pool_file.is_symlink() or pool_file.read_text() != pool:
        raise RuntimeError('Persistent pool identity must match its operator-provisioned state')
    # Children retain this flock until invocation exit. A replacement cannot reconcile live work.
    with open(STATE / 'owner.lock', 'a', encoding='ascii') as owner:
        fcntl.flock(owner, fcntl.LOCK_EX | fcntl.LOCK_NB)
        recover()
        if SOCKET.exists() or SOCKET.is_symlink():
            if not stat.S_ISSOCK(SOCKET.lstat().st_mode):
                raise RuntimeError('Refusing to replace non-socket')
            SOCKET.unlink()
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        children = set()
        stopping = False
        needs_recovery = False

        def stop(_number, _frame):
            nonlocal stopping
            stopping = True

        signal.signal(signal.SIGTERM, stop)
        signal.signal(signal.SIGINT, stop)
        try:
            listener.bind(str(SOCKET))
            if SOCKET.stat().st_gid != core_uid:
                raise RuntimeError('Socket must inherit the dedicated core GID')
            os.chmod(SOCKET, 0o660)
            listener.listen(4)
            listener.settimeout(0.25)
            print('axiompress.service ready runtime=' + runtime, flush=True)
            while not stopping:
                for pid in tuple(children):
                    if os.waitpid(pid, os.WNOHANG)[0]:
                        children.remove(pid)
                        needs_recovery = True
                if not children and needs_recovery:
                    # Any cleanup failure prevents new admissions, including daemon outages.
                    recover()
                    needs_recovery = False
                try:
                    connection, _ = listener.accept()
                except TimeoutError:
                    continue
                if len(children) >= MAX_HANDLERS:
                    connection.close()
                    continue
                pid = os.fork()
                if pid == 0:
                    listener.close()
                    signal.signal(signal.SIGTERM, signal.SIG_DFL)
                    signal.signal(signal.SIGINT, signal.SIG_DFL)
                    try:
                        with connection:
                            handle(connection, core_uid)
                    except (OSError, ValueError, RecursionError, TimeoutError, subprocess.SubprocessError):
                        # Bounded diagnostic, with no content or peer-supplied data.
                        print('axiompress.service request_failed', flush=True)
                    finally:
                        os._exit(0)
                children.add(pid)
                connection.close()
        finally:
            listener.close()
            if SOCKET.exists():
                SOCKET.unlink()
            # Handlers have bounded deadlines; retain the owner lock until all have exited.
            for pid in children:
                os.waitpid(pid, 0)
            recover()


if __name__ == '__main__':
    main()
