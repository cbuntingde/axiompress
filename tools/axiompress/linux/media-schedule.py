"""Install an inactive timer for an already provisioned, dedicated direct media runtime."""

import argparse
import json
import os
from pathlib import Path
import re
import runpy
import subprocess
import tempfile

CODE = Path(__file__).resolve().parents[1]
runtime = runpy.run_path(str(CODE / 'runtime/direct.py'))


def render(instance, configuration, interval=15):
    if not re.fullmatch('axiompress-[a-z0-9-]{1,48}', instance):
        raise RuntimeError('Canonical AxiomPress instance required')
    if type(interval) is not int or not 5 <= interval <= 3600:
        raise RuntimeError('Schedule interval must be between 5 and 3600 seconds')
    if not all(re.fullmatch('/[a-zA-Z0-9/_.-]+', str(path)) for path in (configuration, CODE)):
        raise RuntimeError('Canonical absolute paths required')
    service = f'''[Unit]
Description=AxiomPress image processing batch
After=network.target

[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/usr/bin/python3 {CODE}/media/run.py --config {configuration} --maximum 4
TimeoutStartSec=180
KillMode=control-group
'''
    timer = f'''[Unit]
Description=AxiomPress image processing schedule

[Timer]
OnBootSec=30
OnUnitInactiveSec={interval}
AccuracySec=1
Unit={instance}.service

[Install]
WantedBy=timers.target
'''
    return {instance + '.service': service, instance + '.timer': timer}


def install(instance, configuration, interval=15, directory=Path('/etc/systemd/system')):
    if os.getuid() != 0:
        raise RuntimeError('Linux deployment identity required')
    configuration = runtime['controlled'](configuration, private=True)
    runtime['controlled'](directory, directory=True)
    if configuration.stat().st_size > 16384:
        raise RuntimeError('Media configuration size limit')
    settings = json.loads(configuration.read_text())
    if settings.get('backend') != 'direct':
        raise RuntimeError('The schedule requires an explicitly provisioned direct runtime')
    controller = runpy.run_path(str(CODE / 'media/run.py'))['Controller']
    def forbidden(*_arguments, **_keywords):
        raise RuntimeError('Docker is unavailable to this schedule')
    controller(forbidden, settings)
    runtime['controlled'](settings['state_directory'], directory=True, private=True)
    documents = render(instance, configuration, interval)
    if any((directory / name).exists() or (directory / name).is_symlink() for name in documents):
        raise RuntimeError('Existing service or timer will not be overwritten')
    with tempfile.TemporaryDirectory(prefix='axiompress-media-units-') as temporary:
        paths = []
        for name, content in documents.items():
            path = Path(temporary) / name
            path.write_text(content)
            paths.append(str(path))
        subprocess.run(['/usr/bin/systemd-analyze', 'verify', *paths], check=True, timeout=10)
    for name, content in documents.items():
        with (directory / name).open('x') as stream:
            os.fchmod(stream.fileno(), 0o644)
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    subprocess.run(['/usr/bin/systemctl', 'daemon-reload'], check=True, timeout=15)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--instance', required=True)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--interval', type=int, default=15)
    options = parser.parse_args()
    install(options.instance, options.config, options.interval)
