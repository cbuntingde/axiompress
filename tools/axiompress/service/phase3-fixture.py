"""Disposable gVisor service for the authenticated PostgreSQL vertical-slice proof."""

import json
import secrets
import time


class Fixture:
    def __init__(self, root, docker, containers, name):
        self.root = root
        self.docker = docker
        self.containers = containers
        self.name = name + '-plugin'
        self.pool = secrets.token_hex(16)
        self.volumes = {}
        self.generation = 2
        self.image = None
        self.approved = None
        self.hostile = None

    def start(self):
        info = json.loads(self.docker('info', '--format', '{{json .}}'))
        if 'runsc' not in info['Runtimes'] or str(info['CgroupVersion']) != '2':
            raise RuntimeError('Phase 3 acceptance requires native runsc and cgroups v2; no fallback')
        for target in ('worker-sdk', 'supervisor'):
            self.docker('build', '--target', target, '-f', str(self.root / 'tools/axiompress/supervisor/Dockerfile'),
                        '-t', self.name + '-' + target, str(self.root / 'tools/axiompress'), timeout=240)
        self.docker('tag', self.name + '-worker-sdk', 'axiompress-worker-sdk:prototype')
        sdk = self.docker('image', 'inspect', self.name + '-worker-sdk', '--format', '{{.Id}}')
        self.image = self.docker('image', 'inspect', self.name + '-supervisor', '--format', '{{.Id}}')
        for label in ('policy', 'state', 'channel'):
            volume = self.name + '-' + label
            self.docker('volume', 'create', volume)
            self.volumes[label] = volume
        mounts = [part for label, volume in self.volumes.items() for part in (
            '--mount', 'type=volume,source=' + volume + ',target=/' + label)]
        prepare = ('import os,pathlib; '
                   '[pathlib.Path(p).mkdir(exist_ok=True) for p in ("/state/admission","/state/workers")]; '
                   '[os.chmod(p,0o700) for p in ("/state","/state/admission","/state/workers","/policy")]; '
                   'os.chown("/channel",0,10001);os.chmod("/channel",0o750); '
                   'pathlib.Path("/state/pool").write_text(os.environ["AXIOM_SERVICE_POOL"])')
        self.docker('run', '--rm', '--network=none', *mounts, '--env', 'AXIOM_SERVICE_POOL=' + self.pool,
                    '--entrypoint', 'python3', self.image, '-c', prepare)
        self.approved = self.docker('run', '--rm', '--network=none', '--read-only', '--tmpfs=/tmp:rw,size=64m',
            '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock', *mounts,
            '--env', 'AXIOM_SDK_IMAGE=' + sdk, '--env', 'AXIOM_SERVICE_TEST=1',
            '--entrypoint', 'php', self.image, '/app/service/test-setup.php', timeout=180)
        self.hostile = self.policy('describe', 'service-hostile', 'site:1')['image']
        self.containers.append(self.name)
        self.docker('run', '-d', '--name', self.name, '--network=none', '--read-only', '--user=0:10001',
            '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=256m', '--pids-limit=128',
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m',
            '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
            '--mount', 'type=volume,source=' + self.volumes['policy'] + ',target=/policy,readonly',
            '--mount', 'type=volume,source=' + self.volumes['state'] + ',target=/state',
            '--mount', 'type=volume,source=' + self.volumes['channel'] + ',target=/channel',
            '--env', 'AXIOM_SERVICE_POOL=' + self.pool, '--env', 'AXIOM_SERVICE_RUNTIME=runsc',
            '--entrypoint', 'python3', self.image, '/app/service/server.py')
        self.ready()

    def ready(self):
        for _ in range(100):
            # A stopped/restarted service must actually accept work; HTTP checks prove recovery below.
            if 'ready runtime=runsc' in self.docker('logs', self.name):
                return
            time.sleep(0.05)
        raise RuntimeError('Disposable gVisor broker did not become ready')

    def policy(self, *arguments):
        return json.loads(self.docker('run', '--rm', '--network=none', '--read-only',
            '--mount', 'type=volume,source=' + self.volumes['policy'] + ',target=/policy',
            '--entrypoint', 'php', self.image, '/app/policy/manage.php', *arguments))

    def select(self, image):
        record = self.policy('approve-read', 'service-reading', 'site:1', str(self.generation), image)
        self.generation = record['generation']

    def disable(self):
        self.generation = self.policy('disable', 'service-reading', 'site:1', str(self.generation))['generation']

    def worker(self):
        for _ in range(100):
            ids = self.docker('ps', '-q', '--filter', 'label=dev.axiompress.service=' + self.pool).split()
            if ids:
                return json.loads(self.docker('inspect', ids[0]))[0]
            time.sleep(0.02)
        raise RuntimeError('No actual isolated worker observed during the HTTP request')

    def close(self):
        if self.image is not None:
            if self.docker('ps', '-aq', '--filter', 'name=^/' + self.name + '$'):
                self.docker('stop', '--time=25', self.name, timeout=35)
                state = json.loads(self.docker('inspect', self.name))[0]['State']
                if state['ExitCode'] != 0:
                    raise RuntimeError('Broker recovery failed; retain operator incident volumes: '
                                       + ','.join(self.volumes.values()))
                self.docker('rm', self.name)
            if self.docker('ps', '-aq', '--filter', 'label=dev.axiompress.service=' + self.pool):
                raise RuntimeError('Unexpected worker inventory; retain operator incident volumes: '
                                   + ','.join(self.volumes.values()))
        for volume in reversed(list(self.volumes.values())):
            self.docker('volume', 'rm', volume)
