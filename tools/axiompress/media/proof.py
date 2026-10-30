"""Operator-only real GD/gVisor fixture, sharing the disposable PostgreSQL proof database."""

import json
from pathlib import Path
import runpy
import tempfile


class Fixture:
    def __init__(self, root, directory, network, docker, name):
        self.root = root
        self.directory = directory
        self.network = network
        self.docker = docker
        self.name = name
        self.state = None
        self.controller = None
        self.processor_image = None

    def start(self):
        for label, file in (('processor', 'media'), ('controller', 'jobs')):
            tag = self.name + '-' + label
            self.docker('build', '-f', str(self.root / ('tools/axiompress/' + file + '/Dockerfile')),
                        '-t', tag, str(self.root), timeout=240)
            image = self.docker('image', 'inspect', tag, '--format', '{{.Id}}')
            if label == 'processor':
                self.processor_image = image
            else:
                controller_image = image
        self.state = Path(tempfile.mkdtemp(prefix='axiompress-media-proof-', dir='/var/tmp'))
        configuration = {'processor_image': self.processor_image, 'controller_image': controller_image,
            'network': self.network, 'secret_file': self.directory / 'job-a', 'ca_file': self.directory / 'ca.crt',
            'host': 'postgres', 'port': 5432, 'database': 'axiom_auth_test', 'user': 'job_a', 'site': 1,
            'state_directory': self.state}
        self.controller = runpy.run_path(str(self.root / 'tools/axiompress/media/run.py'))['Controller'](
            self.docker, configuration)

    def source(self, mime, width=4, height=4):
        if type(width) is not int or type(height) is not int or not 1 <= width <= 1600 or not 1 <= height <= 1600:
            raise ValueError('Bounded fixture dimensions required')
        encode = {'image/png': 'imagepng($image);', 'image/jpeg': 'imagejpeg($image);', 'image/webp': 'imagewebp($image);'}
        script = ('$image=imagecreatetruecolor(' + str(width) + ',' + str(height) + ');'
                  'imagefilledrectangle($image,0,0,' + str(width-1) + ',' + str(height-1) + ',0x4488cc);'
                  'ob_start();' + encode[mime] + 'echo base64_encode(ob_get_clean());')
        return self.docker('run', '--rm', '--network=none', '--entrypoint', 'php', self.processor_image,
                           '-n', '-d', 'extension=gd', '-r', script)

    def process(self):
        return self.controller.process(4)

    def close(self):
        if self.state is not None:
            if any(path.name != 'owner.lock' for path in self.state.iterdir()):
                raise RuntimeError('Media incident state retained for operator recovery: ' + str(self.state))
            (self.state / 'owner.lock').unlink(missing_ok=True)
            self.state.rmdir()
