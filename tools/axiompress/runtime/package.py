"""Approve a local plugin against a prepared operator-owned PHP runtime; never execute package code."""

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
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
    return {'artifact': identity, 'package_digest': source['digest'], 'sdk_digest': sdk_digest,
            'plugin_id': source['plugin_id'], 'version': source['version'],
            'manifest_digest': source['manifest_digest']}


def sign_envelope(*, plugin_id, version, package_digest, manifest_digest, artifact, sdk_image,
                  key_path, public_key):
    """Sign the canonical binding bytes with an operator-held RSA-2048 key."""
    for field, pattern in ((artifact, r'sha256:[a-f0-9]{64}'), (sdk_image, r'sha256:[a-f0-9]{64}'),
                           (package_digest, r'[a-f0-9]{64}'), (manifest_digest, r'[a-f0-9]{64}')):
        if not re.fullmatch(pattern, field or ''):
            raise RuntimeError('Publisher signing requires canonical artifact digests')
    try:
        message = b'\0'.join(part.encode('ascii') for part in (
            plugin_id, version, package_digest, manifest_digest, artifact, sdk_image))
    except (UnicodeEncodeError, AttributeError) as error:
        raise RuntimeError('Publisher signing requires ASCII binding fields') from error
    if not public_key.startswith('-----BEGIN PUBLIC KEY-----') or len(public_key) > 2048:
        raise RuntimeError('Publisher public key must be PEM-encoded RSA-2048')
    key = runtime['controlled'](Path(key_path))
    process = subprocess.run(['openssl', 'dgst', '-sha256', '-sign', str(key)],
                             input=message, capture_output=True)
    if process.returncode != 0 or not process.stdout:
        raise RuntimeError('Publisher signing failed')
    return {'algorithm': 'rsa2048-sha256', 'public_key': public_key,
            'signature': base64.b64encode(process.stdout).decode('ascii')}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sdk', type=Path, required=True)
    parser.add_argument('--sdk-digest', required=True)
    parser.add_argument('--package', type=Path, required=True)
    parser.add_argument('--store', type=Path, required=True)
    parser.add_argument('--php', default='/usr/bin/php8.5')
    parser.add_argument('--sign', type=Path, default=None)
    parser.add_argument('--pubkey', type=Path, default=None)
    options = parser.parse_args()
    if (options.sign is None) != (options.pubkey is None):
        raise RuntimeError('Publisher signing requires both --sign and --pubkey')
    built = build(options.sdk, options.sdk_digest, options.package, options.store, [options.php])
    if options.sign is not None:
        pubkey = runtime['controlled'](options.pubkey).read_text()
        built['signature'] = sign_envelope(plugin_id=built['plugin_id'], version=built['version'],
            package_digest=built['package_digest'], manifest_digest=built['manifest_digest'],
            artifact=built['artifact'], sdk_image='sha256:' + built['sdk_digest'],
            key_path=options.sign, public_key=pubkey)
    print(json.dumps(built))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        raise SystemExit('Local package approval failed') from None
