"""Trusted operator controller. Only fixed runsc image decoding; never loaded into native HTTP."""

import base64
import fcntl
import json
import os
from pathlib import Path
import re
import selectors
import subprocess
import struct
import tempfile
import time


def sync_directory(directory):
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def transform(docker, image, source, name, state_directory, direct=None):
    directory = Path(state_directory)
    if (directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid()
            or directory.stat().st_mode & 0o077):
        raise RuntimeError('Private operator media state directory required')
    descriptor = os.open(directory / 'owner.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return locked_transform(docker, image, source, name, directory, direct)
    finally:
        os.close(descriptor)


def locked_transform(docker, image, source, name, state_directory, direct=None):
    if not re.fullmatch(r'sha256:[a-f0-9]{64}', image) or len(source) > 2097152 or not re.fullmatch(
            r'axiompress-media-[a-f0-9]{32}', name):
        raise RuntimeError('Pinned processor image and bounded input required')
    if direct is None:
        info = json.loads(docker('info', '--format', '{{json .}}'))
        if 'runsc' not in info['Runtimes'] or str(info['CgroupVersion']) != '2':
            raise RuntimeError('Media processing requires runsc; no runtime fallback')
        state = json.loads(docker('image', 'inspect', image))[0]
        if state['Config'].get('Labels', {}).get('dev.axiompress.media.processor') != '1':
            raise RuntimeError('Operator-approved fixed media processor image required')
    else:
        _, manifest = direct.artifact(image)
        if manifest['kind'] != 'image':
            raise RuntimeError('Approved fixed image decoder required')
    created = False
    process = None
    directory = Path(state_directory)
    if (directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid()
            or directory.stat().st_mode & 0o077
            or any(path.name != 'owner.lock' for path in directory.iterdir())):
        raise RuntimeError('Empty private operator media state directory required; recover any prior intent first')
    intent = directory / (name + '.json')
    with intent.open('x', encoding='ascii') as journal:
        os.fchmod(journal.fileno(), 0o600)
        journal.write(json.dumps({'name': name, 'image': image, 'state': 'creating'}))
        journal.flush()
        os.fsync(journal.fileno())
    sync_directory(directory)
    worker = 'axiompress-worker-' + name.removeprefix('axiompress-media-')[:24]
    try:
        if direct is not None:
            direct.prepare(worker, image)
            created = True
            identifier = worker
            attachment = ['/usr/bin/python3', str(Path(__file__).resolve().parents[1] / 'runtime/direct.py'),
                          '--config', str(direct.configuration_path), 'start', worker]
        else:
            identifier = docker('create', '--name', name, '--runtime=runsc', '--network=none', '--read-only',
               '--user=10001:10001', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
               '--memory=128m', '--memory-swap=128m', '--cpus=0.5', '--pids-limit=64',
               '--ulimit=nofile=64:64', '--ulimit=nproc=16:16',
               '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=8m', '--interactive', image)
            created = True
            attachment = ['docker', 'start', '--attach', '--interactive', name]
            profile = json.loads(docker('inspect', name))[0]
            if (profile['HostConfig']['Runtime'] != 'runsc' or profile['HostConfig']['NetworkMode'] != 'none'
                or not profile['HostConfig']['ReadonlyRootfs'] or profile['Mounts']
                or profile['HostConfig']['Memory'] != 134217728 or profile['Config']['User'] != '10001:10001'):
                raise RuntimeError('Media processor fixed profile differs from contract')
        temporary = intent.with_suffix('.confirmed')
        with temporary.open('x', encoding='ascii') as journal:
            os.fchmod(journal.fileno(), 0o600)
            journal.write(json.dumps({'name': name, 'image': image, 'id': identifier, 'state': 'confirmed'}))
            journal.flush()
            os.fsync(journal.fileno())
        os.replace(temporary, intent)
        sync_directory(directory)
        with tempfile.TemporaryFile() as stream:
            stream.write(struct.pack('!I', len(source)) + source)
            stream.seek(0)
            process = subprocess.Popen(attachment,
                                       stdin=stream, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            output = bytearray()
            errors = 0
            deadline = time.monotonic() + 8
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ, 'output')
                selector.register(process.stderr, selectors.EVENT_READ, 'error')
                while selector.get_map():
                    if time.monotonic() >= deadline:
                        raise RuntimeError('Media processor deadline exceeded')
                    for key, _ in selector.select(0.1):
                        chunk = key.fileobj.read1(8192)
                        if not chunk:
                            selector.unregister(key.fileobj)
                        elif key.data == 'output':
                            output.extend(chunk)
                            if len(output) > 2800000:
                                raise RuntimeError('Media processor output limit')
                        else:
                            errors += len(chunk)
                            if errors > 2048:
                                raise RuntimeError('Media processor diagnostic limit')
            status = process.wait(timeout=2)
        if status == 2:
            return {'error': 'invalid_image'}
        if status != 0:
            raise RuntimeError('Media processor unavailable')
        result = json.loads(output)
        if not isinstance(result, dict) or set(result) != {'width', 'height', 'data'}:
            raise RuntimeError('Invalid processor response')
        if (type(result['width']) is not int or type(result['height']) is not int
                or not 1 <= result['width'] <= 1600 or not 1 <= result['height'] <= 1600
                or not isinstance(result['data'], str)):
            raise RuntimeError('Invalid processor metadata')
        png = base64.b64decode(result['data'], validate=True)
        if len(png) > 2097152 or not png.startswith(b'\x89PNG\r\n\x1a\n'):
            raise RuntimeError('Invalid processor PNG output')
        return result
    finally:
        try:
            if process is not None:
                if process.poll() is None:
                    process.kill()
                process.wait(timeout=2)
                process.stdout.close()
                process.stderr.close()
        finally:
            if direct is not None:
                direct.remove(worker)
                intent.unlink()
                sync_directory(directory)
            elif created:
                docker('rm', '-f', name)
                intent.unlink()
                sync_directory(directory)
