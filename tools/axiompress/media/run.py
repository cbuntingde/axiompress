"""Root/operator-only bounded media batches; configuration is never accepted from HTTP/plugins."""

import argparse
import base64
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import subprocess

transform = runpy.run_path(str(Path(__file__).with_name('runtime.py')))['transform']


class Controller:
    def __init__(self, docker, configuration):
        self.docker = docker
        self.config = configuration
        backend = configuration.get('backend', 'docker')
        if backend not in ('docker', 'direct'):
            raise RuntimeError('Unknown media runtime backend')
        self.direct = None
        if backend == 'direct':
            runtime = runpy.run_path(str(Path(__file__).resolve().parents[1] / 'runtime/direct.py'))
            self.direct = runtime['Runtime'](configuration['runtime_config'])
            runtime['controlled'](configuration['php'])
            runtime['controlled'](configuration['core_bootstrap'])
            _, artifact = self.direct.artifact(configuration['processor_image'])
            if artifact['kind'] != 'image':
                raise RuntimeError('Fixed approved image decoder required')
            return
        for label in ('controller_image', 'processor_image'):
            if not re.fullmatch(r'sha256:[a-f0-9]{64}', configuration[label]):
                raise RuntimeError('Operator-pinned media images required')
        state = json.loads(docker('image', 'inspect', configuration['controller_image']))[0]
        if state['Config'].get('Labels', {}).get('dev.axiompress.jobs.controller') != '1':
            raise RuntimeError('Fixed database control image required')

    def control(self, operation, value=None):
        settings = self.config
        if self.direct is not None:
            environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C',
                'AXIOM_MEDIA_CORE_BOOTSTRAP': str(settings['core_bootstrap']),
                'AXIOM_JOBS_SITE_ID': str(settings['site']),
                'AXIOM_JOBS_PG_PASSWORD_FILE': str(settings['secret_file']),
                'AXIOM_JOBS_PG_CA_FILE': str(settings['ca_file'])}
            environment.update({'AXIOM_JOBS_PG_' + label: str(settings[label.lower()])
                                for label in ('HOST', 'PORT', 'DATABASE', 'USER')})
            result = subprocess.run([settings['php'], str(Path(__file__).with_name('control.php')), operation],
                input=None if value is None else json.dumps(value), capture_output=True, text=True,
                env=environment, timeout=15)
            if result.returncode or len(result.stdout) > 3145728:
                raise RuntimeError('Database media operation failed')
            return json.loads(result.stdout)
        mounts = [part for label, target in (('secret_file', 'jobs'), ('ca_file', 'ca.crt')) for part in (
            '--mount', 'type=bind,source=' + str(settings[label]) + ',target=/run/secrets/' + target + ',readonly')]
        environment = [part for label in ('HOST', 'PORT', 'DATABASE', 'USER') for part in (
            '--env', 'AXIOM_JOBS_PG_' + label + '=' + str(settings[label.lower()]))]
        data = self.docker('run', '--rm', '--interactive', '--read-only', '--network', settings['network'],
            '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--memory=64m', '--pids-limit=32',
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16m', *mounts, *environment,
            '--env', 'AXIOM_JOBS_SITE_ID=' + str(settings['site']),
            '--env', 'AXIOM_JOBS_PG_PASSWORD_FILE=/run/secrets/jobs',
            '--env', 'AXIOM_JOBS_PG_CA_FILE=/run/secrets/ca.crt', '--entrypoint', 'php',
            settings['controller_image'], '/runner/media-control.php', operation,
            data=None if value is None else json.dumps(value), timeout=15)
        if len(data) > 3145728:
            raise RuntimeError('Database media response limit')
        return json.loads(data)

    def process(self, maximum=32):
        if type(maximum) is not int or not 1 <= maximum <= 32:
            raise RuntimeError('Bounded media batch required')
        summary = {'ready': 0, 'invalid': 0, 'retry': 0}
        for _ in range(maximum):
            task = self.control('claim')
            if not task:
                break
            name = 'axiompress-media-' + secrets.token_hex(16)
            try:
                source = base64.b64decode(task['data'].replace('\n', ''), validate=True)
                result = transform(self.docker, self.config['processor_image'], source, name,
                                   self.config['state_directory'], self.direct)
                outcome = 'invalid' if 'error' in result else 'ready'
            except (RuntimeError, ValueError, subprocess.SubprocessError):
                if any(path.name != 'owner.lock' for path in Path(self.config['state_directory']).iterdir()):
                    raise RuntimeError('Media creation/cleanup uncertain; operator recovery required at '
                                       + str(self.config['state_directory'])) from None
                result = {}
                outcome = 'retry'
            completed = self.control('finish', {'id': task['id'], 'lease': task['lease'], 'outcome': outcome,
                'width': result.get('width', 0), 'height': result.get('height', 0), 'data': result.get('data', '')})
            if completed.get('outcome') != outcome:
                raise RuntimeError('Media lease changed before commit')
            summary[outcome] += 1
        return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--maximum', type=int, default=32)
    options = parser.parse_args()
    if os.name != 'posix' or os.getuid() != 0:
        raise RuntimeError('Run on the Linux operator daemon host')
    path = Path(options.config)
    if (path.is_symlink() or not path.is_file() or path.stat().st_uid != 0
            or path.stat().st_mode & 0o077 or path.stat().st_size > 16384):
        raise RuntimeError('Private root-owned media configuration required')
    configuration = json.loads(path.read_text())

    def docker(*arguments, data=None, timeout=30):
        result = subprocess.run(['docker', *arguments], input=data, capture_output=True, text=True, timeout=timeout)
        if result.returncode:
            raise RuntimeError('Media Docker operation failed')
        return result.stdout.strip()

    print(json.dumps(Controller(docker, configuration).process(options.maximum)))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError, subprocess.SubprocessError):
        raise SystemExit('Media executor unavailable; diagnostic=' + secrets.token_hex(12)) from None
