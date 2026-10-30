"""Disposable host-PHP/PostgreSQL image processing tests. Docker prepares files and database only."""

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import selectors
import shlex
import shutil
import signal
import subprocess
import tempfile


class Fixture:
    def __init__(self, root, directory, docker, name, runsc):
        self.root, self.directory, self.docker = root, directory, docker
        self.name, self.runsc = name, runsc
        self.work = None
        self.runtime = None
        self.group = None
        self.checks = 0

    @staticmethod
    def no_docker(*_arguments, **_keywords):
        raise RuntimeError('Direct media controller attempted to use Docker')

    def start(self):
        self.work = Path(tempfile.mkdtemp(prefix='axiompress-direct-media-', dir='/var/tmp'))
        helpers = runpy.run_path(str(self.root / 'tools/axiompress/runtime/run-checks.py'))
        jobs = helpers['export_test_files'](self.work, 'jobs')
        sdk = helpers['export_test_files'](self.work, 'media')
        code = self.work / 'code'
        shutil.copytree(self.root / 'tools/axiompress', code, ignore=shutil.ignore_patterns('__pycache__'))
        core = self.work / 'core'
        shutil.copytree(self.root / 'src/axiompress', core)
        helpers['protected_tree'](code)
        helpers['protected_tree'](core)
        definitions = runpy.run_path(str(code / 'runtime/direct.py'))
        store, runtime_state, self.state = (self.work / name for name in ('store', 'runtime', 'batch'))
        for directory in (store, runtime_state, self.state):
            directory.mkdir(mode=0o700)
        self.group = Path('/sys/fs/cgroup') / ('axiompress-direct-media-' + secrets.token_hex(8))
        self.group.mkdir(mode=0o700)
        (self.group / 'cgroup.subtree_control').write_text('+cpu +memory +pids')
        configuration = self.work / 'runtime.json'
        definitions['durable_json'](configuration, {'runsc': str(self.runsc),
            'runsc_sha256': definitions['digest_file'](self.runsc), 'store': str(store),
            'state': str(runtime_state), 'cgroup_parent': str(self.group)})
        self.runtime = definitions['Runtime'](configuration)
        sdk_digest = hashlib.sha256(json.dumps(definitions['inventory'](sdk), sort_keys=True,
            separators=(',', ':')).encode()).hexdigest()
        self.processor_image = runpy.run_path(str(code / 'runtime/image.py'))['build'](sdk, sdk_digest, store)
        self.media_php = self.php_command(sdk, 'gd')
        executable = self.work / 'php'
        executable.write_text('#!/bin/sh\nexec ' + shlex.join(self.php_command(jobs, 'pdo_pgsql')) + ' "$@"\n')
        executable.chmod(0o700)
        port = int(self.docker('port', self.name, '5432/tcp').rsplit(':', 1)[1])
        settings = {'backend': 'direct', 'runtime_config': str(configuration),
            'processor_image': self.processor_image, 'php': str(executable),
            'core_bootstrap': str(core / 'bootstrap.php'), 'state_directory': str(self.state),
            'host': 'localhost', 'port': port, 'database': 'axiom_auth_test', 'user': 'job_a', 'site': 1,
            'secret_file': str(self.directory / 'job-a'), 'ca_file': str(self.directory / 'ca.crt')}
        self.controller_script = code / 'media/run.py'
        self.controller_type = runpy.run_path(str(self.controller_script))['Controller']
        self.controller = self.controller_type(self.no_docker, settings)
        for replacement in ({'site': 2}, {'ca_file': str(self.directory / 'wrong-ca.crt')},
                            {'secret_file': str(self.directory / 'job-b')},
                            {'user': 'auth_a', 'secret_file': str(self.directory / 'auth-a')}):
            try:
                self.controller_type(self.no_docker, {**settings, **replacement}).control('claim')
            except RuntimeError:
                self.checks += 1
            else:
                raise RuntimeError('Direct database controller accepted unauthorized configuration')
        if self.controller.control('claim') != {}:
            raise RuntimeError('Unexpected pending image before HTTP uploads')
        self.checks += 1

    @staticmethod
    def php_command(root, extension):
        library = next((root / 'usr/local/lib/php/extensions').iterdir()) / (extension + '.so')
        return [str(root / 'lib/ld-musl-x86_64.so.1'), '--library-path',
                ':'.join(str(root / path) for path in ('usr/local/lib', 'usr/lib', 'lib')),
                str(root / 'usr/local/bin/php'), '-n', '-d', 'extension=' + str(library)]

    def source(self, mime, width=4, height=4):
        if type(width) is not int or type(height) is not int or not 1 <= width <= 1600 or not 1 <= height <= 1600:
            raise RuntimeError('Bounded test image required')
        encoder = {'image/png': 'imagepng', 'image/jpeg': 'imagejpeg', 'image/webp': 'imagewebp'}[mime]
        script = '$i=imagecreatetruecolor(' + str(width) + ',' + str(height) + ');' + encoder + '($i);'
        result = subprocess.run([*self.media_php, '-r', script], capture_output=True, check=True, timeout=5,
                                env={'PATH': '/nonexistent'})
        return base64.b64encode(result.stdout).decode()

    def process(self):
        result = self.controller.process(4)
        self.checks += 1
        return result

    def recover_after_claim(self, sql, identifier):
        if not re.fullmatch('[a-f0-9]{32}', identifier):
            raise RuntimeError('Canonical disposable media identity required')
        child = '''import json,runpy,signal,sys
def forbidden(*arguments,**keywords):
 raise RuntimeError('Docker is unavailable to the direct controller')
controller=runpy.run_path(sys.argv[1])['Controller'](forbidden,json.load(sys.stdin))
task=controller.control('claim')
print(json.dumps({'id':task['id'],'lease':task['lease']}),flush=True)
signal.pause()
'''
        process = subprocess.Popen(['/usr/bin/python3', '-c', child, str(self.controller_script)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env={'PATH': '/nonexistent'}, start_new_session=True)
        try:
            process.stdin.write(json.dumps(self.controller.config).encode())
            process.stdin.close()
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ)
                if not selector.select(8):
                    raise RuntimeError('Direct controller did not persist an image claim')
                task = json.loads(os.read(process.stdout.fileno(), 1024))
            if task['id'] != identifier:
                raise RuntimeError('Direct controller claimed an unexpected image')
        finally:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                # The entire private process group already exited.
                if process.poll() is None:
                    raise
            process.wait(timeout=3)
            process.stdin.close()
            process.stdout.close()
            process.stderr.close()
        self.checks += 1
        if self.controller.process(1) != {'ready': 0, 'invalid': 0, 'retry': 0}:
            raise RuntimeError('Replacement controller bypassed a live lease')
        self.checks += 1
        sql("UPDATE axiom_media.assets SET lease_until=clock_timestamp()-interval '1 second' WHERE id='"
            + identifier + "';")
        stale = self.controller.control('finish', {'id': identifier, 'lease': task['lease'],
            'outcome': 'invalid', 'data': '', 'width': 0, 'height': 0})
        if stale != {'error': 'conflict'} or self.controller.control('claim') != {}:
            raise RuntimeError('Expired controller lease retained authority')
        self.checks += 1
        sql("UPDATE axiom_media.assets SET available_at=clock_timestamp()-interval '1 second' WHERE id='"
            + identifier + "' AND status='pending';")

    def report(self):
        return {'outcome': 'passed', 'checks': self.checks, 'backend': 'direct-runsc',
                'docker_required_for_controller_or_decoder': False, 'production_acceptance': False}

    def close(self):
        if self.work is None:
            return
        if self.runtime is not None:
            self.runtime.recover()
            self.runtime.close_test_state()
        if hasattr(self, 'state') and any(path.name != 'owner.lock' for path in self.state.iterdir()):
            raise RuntimeError('Direct media batch intent retained for operator recovery at ' + str(self.work))
        if self.group is not None and self.group.exists():
            self.group.rmdir()
        # Only erase the exact disposable root after worker termination and namespace unmount.
        if self.work.parent != Path('/var/tmp') or self.work.is_symlink() or self.work.resolve() != self.work:
            raise RuntimeError('Unexpected disposable media directory')
        shutil.rmtree(self.work)
