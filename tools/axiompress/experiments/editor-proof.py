"""Fixed-source asset adapter for the disposable HTTPS fixture only, never the native router."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess


class Assets:
    def __init__(self, root):
        self.root = root
        node = shutil.which('node.exe')
        if node is None:
            raise RuntimeError('Windows Node and Chrome required for editor proof')
        script = subprocess.run(['wslpath', '-w', str(root / 'tools/axiompress/experiments/gutenberg-native.cjs')],
                                capture_output=True, text=True, check=True, timeout=10).stdout.strip()
        result = subprocess.run([node, script], capture_output=True, text=True, check=True, timeout=30)
        self.manifest = json.loads(result.stdout)
        self.files = {}
        scripts = []
        styles = []
        for asset in self.manifest['scripts']:
            url = '/__editor-assets/' + asset['handle'] + '.js'
            self.files[url] = (asset['file'], 'text/javascript; charset=utf-8', asset['sha256'])
            scripts.append('<script src="' + url + '" defer></script>')
        for index, file in enumerate(self.manifest['styles'] + ['tools/axiompress/experiments/gutenberg-proof.css']):
            url = '/__editor-assets/style-' + str(index) + '.css'
            self.files[url] = (file, 'text/css; charset=utf-8', hashlib.sha256((root / file).read_bytes()).hexdigest())
            styles.append('<link rel="stylesheet" href="' + url + '">')
        file = 'tools/axiompress/experiments/gutenberg-proof.js'
        self.files['/__editor-assets/proof.js'] = (file, 'text/javascript; charset=utf-8',
                                                  hashlib.sha256((root / file).read_bytes()).hexdigest())
        scripts.append('<script src="/__editor-assets/proof.js" defer></script>')
        self.html = (root / 'tools/axiompress/experiments/gutenberg-proof.html').read_text().replace(
            '<!-- scripts -->', '\n'.join(scripts)).replace('<!-- styles -->', '\n'.join(styles)).encode()

    def read(self, url):
        if url.split('?', 1)[0] == '/__editor-proof':
            return self.html, 'text/html; charset=utf-8'
        if url not in self.files:
            return None
        file, mime, digest = self.files[url]
        source = self.root / file
        if source.is_symlink() or not source.is_file():
            raise RuntimeError('Pinned editor source missing')
        data = source.read_bytes()
        if hashlib.sha256(data).hexdigest() != digest:
            raise RuntimeError('Editor source changed during acceptance')
        return data, mime
