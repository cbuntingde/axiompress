"""Approve a local plugin against a prepared operator-owned PHP runtime; never execute package code."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import runpy
import shutil
import subprocess
import tempfile

runtime = runpy.run_path(str(Path(__file__).with_name('direct.py')))


def build(sdk, sdk_digest, package, store, php_command):
    if os.getuid() != 0:
        raise RuntimeError('Deployment identity required')
    runtime['controlled'](store, directory=True, private=True)
    sdk_files = runtime['inventory'](sdk)
    actual_sdk = hashlib.sha256(json.dumps(sdk_files, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    if sdk_digest != actual_sdk:
        raise RuntimeError('Prepared PHP runtime differs from the approved SDK digest')
    capture = Path(__file__).with_name('capture.php')
    result = subprocess.run([*php_command, str(capture), str(package)], capture_output=True, check=True, timeout=5)
    if len(result.stdout) > 1048576:
        raise RuntimeError('Package snapshot size limit')
    source = json.loads(result.stdout)
    # Staging lives inside the private deployment store. No publisher supplies an archive,
    # Dockerfile, script, destination path, executable hook or runtime configuration.
    with tempfile.TemporaryDirectory(prefix='staging-', dir=store) as temporary:
        root = Path(temporary) / 'rootfs'
        shutil.copytree(sdk, root, symlinks=True)
        app = root / 'app'
        if app.is_symlink():
            raise RuntimeError('Runtime application directory must not be a link')
        app.mkdir(exist_ok=True)
        for file, key in (('worker.php', 'worker'), ('manifest.json', 'manifest')):
            path = app / file
            if path.exists() or path.is_symlink():
                if path.is_dir() and not path.is_symlink():
                    raise RuntimeError('Unexpected SDK package destination')
                path.unlink()
            with path.open('xb') as stream:
                stream.write(source[key].encode())
                os.fchmod(stream.fileno(), 0o444)
        identity = runtime['seal'](root, store, 'plugin', source['digest'], sdk_digest,
                                   {'plugin_id': source['plugin_id'], 'version': source['version'],
                                    'manifest_digest': source['manifest_digest']})
    return {'artifact': identity, 'package_digest': source['digest'], 'sdk_digest': sdk_digest}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sdk', type=Path, required=True)
    parser.add_argument('--sdk-digest', required=True)
    parser.add_argument('--package', type=Path, required=True)
    parser.add_argument('--store', type=Path, required=True)
    parser.add_argument('--php', default='/usr/bin/php8.5')
    options = parser.parse_args()
    print(json.dumps(build(options.sdk, options.sdk_digest, options.package, options.store, [options.php])))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        raise SystemExit('Local package approval failed') from None
