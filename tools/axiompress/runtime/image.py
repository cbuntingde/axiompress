"""Bind the fixed AxiomPress decoder to an explicitly approved local PHP/GD filesystem."""

import argparse
import hashlib
import json
from pathlib import Path
import runpy
import shutil
import tempfile

runtime = runpy.run_path(str(Path(__file__).with_name('direct.py')))


def build(sdk, sdk_digest, store):
    runtime['controlled'](store, directory=True, private=True)
    files = runtime['inventory'](sdk)
    actual = hashlib.sha256(json.dumps(files, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    if actual != sdk_digest:
        raise RuntimeError('PHP/GD filesystem differs from the approved digest')
    processor = Path(__file__).resolve().parents[1] / 'media/processor.php'
    runtime['controlled'](processor)
    with tempfile.TemporaryDirectory(prefix='image-staging-', dir=store) as temporary:
        root = Path(temporary) / 'rootfs'
        shutil.copytree(sdk, root, symlinks=True)
        app = root / 'app'
        if app.is_symlink():
            raise RuntimeError('Decoder destination must not be a link')
        app.mkdir(exist_ok=True)
        destination = app / 'processor.php'
        if destination.exists() or destination.is_symlink():
            destination.unlink()
        with destination.open('xb') as stream:
            stream.write(processor.read_bytes())
        destination.chmod(0o444)
        return runtime['seal'](root, store, 'image', runtime['digest_file'](processor), sdk_digest)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sdk', required=True, type=Path)
    parser.add_argument('--sdk-digest', required=True)
    parser.add_argument('--store', required=True, type=Path)
    options = parser.parse_args()
    print(json.dumps({'artifact': build(options.sdk, options.sdk_digest, options.store)}))
