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
TimeoutStopSec=30
KillMode=control-group
Delegate=cpu memory pids
MemoryMax=512M
TasksMax=128
LimitNOFILE=4096
RestrictRealtime=yes
LockPersonality=yes
# Workers run in the dedicated direct-runtime cgroup from runtime_config,
# outside this service cgroup. Stopping this batch sends SIGTERM to the
# controller; an interrupted batch retains its intent file and the worker
# runtime must be reconciled before the next claim. See the Linux guide.
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
    if not re.fullmatch('axiompress-[a-z0-9-]{1,48}', instance):
        raise RuntimeError('Canonical AxiomPress instance required')
    if type(interval) is not int or not 5 <= interval <= 3600:
        raise RuntimeError('Schedule interval must be between 5 and 3600 seconds')
    configuration = runtime['controlled'](configuration, private=True)
    runtime['controlled'](directory, directory=True)
    if configuration.stat().st_size > 16384:
        raise RuntimeError('Media configuration size limit')
    settings = json.loads(configuration.read_text())
    if settings.get('backend') != 'direct':
        raise RuntimeError('The schedule requires an explicitly provisioned direct runtime')
    required = {'backend', 'runtime_config', 'processor_image', 'php', 'core_bootstrap',
        'state_directory', 'host', 'port', 'database', 'user', 'site', 'secret_file', 'ca_file'}
    if not required <= set(settings):
        raise RuntimeError('Direct media configuration is missing required fields')
    if {'controller_image', 'network'} & set(settings):
        raise RuntimeError('Direct media schedule must not configure Docker images or networks')
    batch_state = runtime['controlled'](settings['state_directory'], directory=True, private=True)
    runtime_config_path = runtime['controlled'](settings['runtime_config'], private=True)
    if runtime_config_path.stat().st_size > 16384:
        raise RuntimeError('Media runtime configuration size limit')
    runtime_settings = json.loads(runtime_config_path.read_text())
    if set(runtime_settings) != {'runsc', 'runsc_sha256', 'store', 'state', 'cgroup_parent'}:
        raise RuntimeError('Unexpected media runtime configuration fields')
    store = runtime['controlled'](runtime_settings['store'], directory=True, private=True)
    runtime_state = runtime['controlled'](runtime_settings['state'], directory=True, private=True)
    cgroup_parent = runtime['controlled'](runtime_settings['cgroup_parent'], directory=True)
    for first, second in ((batch_state, store), (batch_state, runtime_state), (store, runtime_state)):
        if first == second or first.is_relative_to(second) or second.is_relative_to(first):
            raise RuntimeError('Media batch, runtime state and store must be separate directories')
    controllers_path = cgroup_parent / 'cgroup.controllers'
    if not controllers_path.is_file():
        raise RuntimeError('CPU, memory and task delegation required for media workers')
    controllers = controllers_path.read_text().split()
    if not {'cpu', 'memory', 'pids'} <= set(controllers):
        raise RuntimeError('CPU, memory and task delegation required for media workers')
    for label in ('secret_file', 'ca_file', 'core_bootstrap', 'php', 'runtime_config'):
        candidate = Path(settings[label] if label != 'runtime_config' else settings['runtime_config'])
        if label == 'secret_file':
            runtime['controlled'](candidate, private=True)
        else:
            runtime['controlled'](candidate, directory=False)
        for guarded in (batch_state, store, runtime_state):
            if candidate == guarded or candidate.is_relative_to(guarded):
                raise RuntimeError('Media credentials and code must live outside worker state directories')
    if not os.access(settings['php'], os.X_OK):
        raise RuntimeError('Executable host PHP runtime required')
    controller = runpy.run_path(str(CODE / 'media/run.py'))['Controller']
    def forbidden(*_arguments, **_keywords):
        raise RuntimeError('Docker is unavailable to this schedule')
    controller(forbidden, settings)
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
