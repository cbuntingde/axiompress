"""Build and verify a local, operator-reviewed AxiomPress Linux service directory."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import runpy
import shutil

ROOT = Path(__file__).resolve().parents[3]
runtime = runpy.run_path(str(Path(__file__).resolve().parents[1] / 'runtime/direct.py'))


def build(destination):
    if os.getuid() != 0 or not destination.is_absolute():
        raise RuntimeError('Linux deployment identity and absolute new destination required')
    runtime['controlled'](destination.parent, directory=True)
    destination.mkdir(mode=0o755)
    sources = json.loads(Path(__file__).with_name('package-files.json').read_text())
    if not isinstance(sources, list) or len(sources) != len(set(sources)):
        raise RuntimeError('Explicit unique release file list required')
    for name in sources:
        relative = Path(name)
        if relative.is_absolute() or '..' in relative.parts or not relative.parts:
            raise RuntimeError('Repository-relative release source required')
        path = ROOT / relative
        if any(part.is_symlink() for part in (path, *path.parents)) or not path.is_file():
            raise RuntimeError('Release sources must be regular files without symbolic links')
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
        shutil.copyfile(path, target)
        target.chmod(0o444)
    shutil.copyfile(ROOT / 'src/license.txt', destination / 'license.txt')
    (destination / 'license.txt').chmod(0o444)
    for name in ('FORK_PROVENANCE.md', 'AXIOMPRESS_RELEASE_POLICY.md'):
        shutil.copyfile(ROOT / name, destination / name)
        (destination / name).chmod(0o444)
    document = {'format': 'axiompress.linux-service/1', 'files': runtime['inventory'](destination)}
    manifest = destination / 'release.json'
    runtime['durable_json'](manifest, document)
    manifest.chmod(0o444)
    return hashlib.sha256(manifest.read_bytes()).hexdigest()


def verify(destination, digest):
    manifest = runtime['controlled'](destination / 'release.json')
    if manifest.stat().st_size > 8388608 or runtime['digest_file'](manifest) != digest:
        raise RuntimeError('Release manifest digest mismatch')
    document = json.loads(manifest.read_text())
    files = runtime['inventory'](destination)
    del files['release.json']
    if set(document) != {'format', 'files'} or document['format'] != 'axiompress.linux-service/1' or files != document['files']:
        raise RuntimeError('Release files differ from the approved manifest')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    options = parser.parse_args()
    print(json.dumps({'release': str(options.output), 'sha256': build(options.output)}))
