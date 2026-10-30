"""Install a verified extension-service unit without enabling or starting it."""

import argparse
import os
from pathlib import Path
import runpy
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
package = runpy.run_path(str(HERE / 'package.py'))
service = runpy.run_path(str(HERE / 'service.py'))


def install(release, digest, configuration, units=Path('/etc/systemd/system')):
    if os.getuid() != 0 or HERE != release / 'tools/axiompress/linux':
        raise RuntimeError('Run the installer from the approved root-owned release directory')
    package['verify'](release, digest)
    value = service['load'](configuration)
    major = int(subprocess.check_output(['/usr/bin/systemctl', '--version'], text=True, timeout=5).split()[1])
    if major < 254:
        raise RuntimeError('systemd 254 or newer required for delegated controller placement')
    package['runtime']['controlled'](units, directory=True)
    target = units / (value['instance'] + '.service')
    if target.exists() or target.is_symlink():
        raise RuntimeError('Service unit already exists; installation never overwrites it')
    content = service['unit'](configuration, value)
    with tempfile.TemporaryDirectory(prefix='axiompress-unit-') as temporary:
        staged = Path(temporary) / target.name
        staged.write_text(content)
        subprocess.run(['/usr/bin/systemd-analyze', 'verify', str(staged)], check=True, timeout=10)
    with target.open('x') as stream:
        os.fchmod(stream.fileno(), 0o644)
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())
    descriptor = os.open(units, os.O_DIRECTORY | os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    subprocess.run(['/usr/bin/systemctl', 'daemon-reload'], check=True, timeout=15)
    return target


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release', required=True, type=Path)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--config', required=True, type=Path)
    options = parser.parse_args()
    print(install(options.release, options.sha256, options.config))
