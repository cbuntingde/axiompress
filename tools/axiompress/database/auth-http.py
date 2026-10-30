"""Actual HTTPS/frontend-to-core proof; backend is loopback-only in this disposable fixture."""

import http.client
import base64
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import secrets
import ssl
import socket
import subprocess
import shutil
import runpy
import threading
import time
import urllib.error
import urllib.request


def verify(root, directory, network, docker, containers, name, sql, plugin=None, media=None, browser=False,
           editor_proof=False):
    backend_port = None
    editor_assets = (runpy.run_path(str(root / 'tools/axiompress/experiments/editor-proof.py'))['Assets'](root)
                     if editor_proof else None)

    class Proxy(BaseHTTPRequestHandler):
        def log_message(self, _format, *_arguments):
            # Test access logs must not retain request URIs, cookies or credentials.
            return

        def forward(self):
            asset = editor_assets.read(self.path) if editor_assets is not None else None
            if asset is not None:
                if self.command not in ('GET', 'HEAD'):
                    self.send_error(405)
                    return
                data, mime = asset
                self.send_response(200)
                self.send_header('Content-Type', mime)
                self.send_header('Content-Length', str(len(data)))
                self.send_header('Cache-Control', 'no-store')
                self.send_header('X-Content-Type-Options', 'nosniff')
                self.send_header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                                 "connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'")
                self.end_headers()
                if self.command != 'HEAD':
                    self.wfile.write(data)
                return
            length = int(self.headers.get('Content-Length', '0'))
            if length > 3145728:
                self.send_error(413)
                return
            body = self.rfile.read(length)
            headers = {key: self.headers[key] for key in ('Cookie', 'Origin', 'Content-Type', 'X-CSRF-Token', 'Idempotency-Key')
                       if key in self.headers}
            connection = http.client.HTTPConnection('127.0.0.1', backend_port, timeout=10)
            try:
                connection.request(self.command, self.path, body=body, headers=headers)
                result = connection.getresponse()
                data = result.read(4194305)
                if len(data) > 4194304:
                    raise RuntimeError('Disposable HTTP proxy response limit')
                self.send_response(result.status)
                for key, value in result.getheaders():
                    if key.lower() not in ('connection', 'transfer-encoding', 'content-length', 'server', 'date'):
                        self.send_header(key, value)
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            finally:
                connection.close()

        do_GET = forward
        do_HEAD = forward
        do_POST = forward
        do_DELETE = forward
        do_PUT = forward

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(directory / 'server.crt', directory / 'server.key')
    server = ThreadingHTTPServer(('127.0.0.1', 0), Proxy)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    origin = 'https://localhost:' + str(server.server_port)
    thread = None
    checks = 0
    core_containers = []

    def check(value, label):
        nonlocal checks
        if not value:
            raise RuntimeError(label)
        checks += 1

    try:
        tag = 'axiompress-native:auth-' + name.rsplit('-', 1)[1]
        docker('build', '-f', str(root / 'tools/axiompress/native/Dockerfile'), '-t', tag, str(root), timeout=240)
        image = docker('image', 'inspect', tag, '--format', '{{.Id}}')
        container = name + '-auth-core'
        containers.append(container)
        core_containers.append(container)
        mounts = [part for source, target in (('auth-a', 'auth'), ('reader-a', 'public'), ('ca.crt', 'ca.crt')) for part in (
            '--mount', 'type=bind,source=' + str(directory / source) + ',target=/run/secrets/' + target + ',readonly')]
        if plugin is not None:
            mounts += ['--mount', 'type=volume,source=' + plugin.volumes['channel'] + ',target=/channel,readonly',
                       '--env', 'AXIOMPRESS_BROKER_SOCKET=/channel/broker.sock',
                       '--env', 'AXIOMPRESS_READING_INSTALLATION=service-reading']
        runtime = ['run', '-d', '--name', container, '--network', network, '--read-only', '--cap-drop=ALL',
               '--security-opt=no-new-privileges:true', '--memory=128m', '--pids-limit=32',
               '--tmpfs=/tmp:rw,size=16m', '--publish', '127.0.0.1::8080', '--env', 'PHP_CLI_SERVER_WORKERS=4',
               '--env', 'AXIOM_AUTH_PG_HOST=postgres', '--env', 'AXIOM_AUTH_PG_PORT=5432',
               '--env', 'AXIOM_AUTH_PG_DATABASE=axiom_auth_test', '--env', 'AXIOM_AUTH_PG_USER=auth_a',
               '--env', 'AXIOM_AUTH_PG_PASSWORD_FILE=/run/secrets/auth', '--env', 'AXIOM_AUTH_SITE_ID=1',
               '--env', 'AXIOM_AUTH_PG_CA_FILE=/run/secrets/ca.crt', '--env', 'AXIOM_AUTH_ORIGIN=' + origin,
               '--env', 'AXIOM_AUTH_TLS_TERMINATED=1', '--env', 'AXIOM_PUBLIC_BACKEND=postgres',
               '--env', 'AXIOM_PUBLIC_PG_HOST=postgres', '--env', 'AXIOM_PUBLIC_PG_PORT=5432',
               '--env', 'AXIOM_PUBLIC_PG_DATABASE=axiom_auth_test', '--env', 'AXIOM_PUBLIC_PG_USER=reader_a',
               '--env', 'AXIOM_PUBLIC_PG_PASSWORD_FILE=/run/secrets/public', '--env', 'AXIOM_PUBLIC_SITE_ID=1',
               '--env', 'AXIOM_PUBLIC_PG_CA_FILE=/run/secrets/ca.crt', *mounts, image]
        docker(*runtime)

        def port_for(container):
            port = int(docker('port', container, '8080/tcp').rsplit(':', 1)[1])
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=1):
                        return port
                except OSError:
                    time.sleep(0.02)
            raise RuntimeError('Disposable native HTTP adapter did not become ready')

        backend_port = port_for(container)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        client_tls = ssl.create_default_context(cafile=directory / 'ca.crt')

        def request(method, path, value=None, cookie='', csrf='', request_origin=origin, key=''):
            data = None if value is None else json.dumps(value).encode()
            headers = {'Origin': request_origin, 'Content-Type': 'application/json'}
            if cookie:
                headers['Cookie'] = cookie
            if csrf:
                headers['X-CSRF-Token'] = csrf
            if key:
                headers['Idempotency-Key'] = key
            req = urllib.request.Request(origin + path, data=data, headers=headers, method=method)
            try:
                response = urllib.request.urlopen(req, context=client_tls, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                data = response.read(3145729)
                content_type = response.headers.get_content_type()
                payload = (data if content_type == 'image/png' else json.loads(data) if content_type == 'application/json'
                           and data else data.decode('utf-8') if data else None)
                return response.status, dict(response.headers), payload

        status, headers, body = request('POST', '/v1/session/login',
            {'username': 'alice', 'password': (directory / 'user-password').read_text()})
        check(status == 200 and body['user_id'] == '1' and body['site_id'] == '1', 'Actual HTTPS login')
        cookie = headers['Set-Cookie'].split(';')[0]
        check(all(flag in headers['Set-Cookie'] for flag in ('Secure', 'HttpOnly', 'SameSite=Strict', 'Path=/')),
              'Actual HTTP cookie attributes')
        check(headers['Cache-Control'] == 'no-store' and headers['X-Content-Type-Options'] == 'nosniff',
              'Sensitive responses cannot be cached/sniffed')
        check(request('GET', '/v1/session', cookie=cookie)[0] == 200, 'Actual HTTPS session resolves')
        check(request('DELETE', '/v1/session', cookie=cookie)[0] == 403, 'Actual HTTP CSRF denial')
        check(request('DELETE', '/v1/session', cookie=cookie, csrf=body['csrf_token'],
                      request_origin='https://evil.invalid')[0] == 403, 'Actual HTTP origin denial')
        csrf = body['csrf_token']
        value = {'title': 'HTTPS-created article', 'text': 'Private draft'}
        key = secrets.token_hex(32)
        check(request('POST', '/v1/articles', value, cookie=cookie, key=key)[0] == 403,
              'Actual content CSRF denial')
        status, _, article = request('POST', '/v1/articles', value, cookie=cookie, csrf=csrf, key=key)
        check(status == 201, 'Actual HTTPS article creation')
        path = '/v1/articles/' + article['id']
        public_path = '/v1/sites/1/content/' + article['id']
        check(request('GET', public_path)[0] == 404, 'Actual HTTP draft is not public')
        check(request('GET', public_path, cookie=cookie)[0] == 404, 'Authenticated cookie cannot elevate public reads')
        check(request('POST', '/v1/articles', value, cookie=cookie, csrf=csrf, key=key)[2] == article,
              'Actual HTTP idempotent create replay')
        check(request('GET', path)[0] == 401, 'Actual HTTP anonymous private read denied')
        check(request('GET', path, cookie=cookie)[2]['text'] == value['text'], 'Actual HTTPS private read')
        expected = {'expected_revision': article['revision'], 'expected_version': article['version']}
        status, _, updated = request('PUT', path, dict(expected, title='HTTPS edit', text='Edited'),
                                    cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
        check(status == 200, 'Actual HTTPS edit')
        check(request('PUT', path, dict(expected, title='Stale', text='Stale'),
                      cookie=cookie, csrf=csrf, key=secrets.token_hex(32))[0] == 409, 'Actual HTTP stale edit conflict')
        race_expected = {'expected_revision': updated['revision'], 'expected_version': updated['version']}
        with ThreadPoolExecutor(max_workers=2) as executor:
            competing = [executor.submit(request, 'PUT', path, dict(race_expected, title='HTTPS edit', text='Edited'),
                                         cookie=cookie, csrf=csrf, key=secrets.token_hex(32)) for _ in range(2)]
            outcomes = [future.result(timeout=18) for future in competing]
        check(sorted(result[0] for result in outcomes) == [200, 409],
              'Independent concurrent HTTPS edits have exactly one committed winner')
        updated = next(result[2] for result in outcomes if result[0] == 200)
        expected = {'expected_revision': updated['revision'], 'expected_version': updated['version']}
        sql("UPDATE axiom_identity.memberships SET role='author' WHERE site_id=1 AND user_id=1")
        downgraded = request('POST', path + '/publish', expected, cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
        check(downgraded[0] == 403, 'Actual HTTP role downgrade forbids publication: status=' + str(downgraded[0]))
        sql("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1")
        retry_key = secrets.token_hex(32)
        sql("CREATE FUNCTION axiom_content.fail_http_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ "
            "BEGIN RAISE EXCEPTION 'Injected HTTP failure'; END; $$; "
            "CREATE TRIGGER fail_http_receipt BEFORE INSERT ON axiom_content.command_receipts "
            "FOR EACH ROW EXECUTE FUNCTION axiom_content.fail_http_receipt()")
        try:
            check(request('POST', path + '/publish', expected, cookie=cookie, csrf=csrf, key=retry_key)[0] == 503,
                  'Actual HTTP transaction failure is non-success')
            check(request('GET', public_path)[0] == 404
                  and request('GET', path, cookie=cookie)[2]['version'] == updated['version'],
                  'Actual HTTP transaction failure leaves publication and version unchanged')
        finally:
            sql('DROP TRIGGER fail_http_receipt ON axiom_content.command_receipts; '
                'DROP FUNCTION axiom_content.fail_http_receipt()')
        status, _, published = request('POST', path + '/publish', expected,
                                      cookie=cookie, csrf=csrf, key=retry_key)
        check(status == 200 and published['status'] == 'published', 'Actual HTTPS publication')
        status, public_headers, public = request('GET', public_path)
        check(status == 200 and public['content']['text'] == 'Edited'
              and public['content']['content_id'] == article['id'], 'Actual anonymous PostgreSQL public read')
        check(public_headers['Cache-Control'] == 'no-store' and 'Set-Cookie' not in public_headers,
              'Public read does not cache or create authentication state')
        check(request('HEAD', public_path)[0] == 200 and request('HEAD', public_path)[2] is None,
              'Actual HEAD has publication semantics and no body')
        check(request('GET', public_path + '?actor=owner')[0] == 404, 'Actual public query claims rejected')
        check(request('GET', '/v1/sites/2/content/' + article['id'])[0] == 404, 'Actual public cross-site denial')
        expected = {'expected_revision': published['revision'], 'expected_version': published['version']}
        status, _, draft = request('PUT', path, dict(expected, title='Private next title', text='Private next body'),
                                  cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
        check(status == 200 and request('GET', public_path)[2]['content']['text'] == 'Edited',
              'Actual public route never follows a new private draft')
        expected = {'expected_revision': draft['revision'], 'expected_version': draft['version']}
        if plugin is not None:
            check(public.get('reading_summary', '').startswith('<span class="axiompress-reading-summary">1 words'),
                  'Real gVisor plugin decorates the newly published PostgreSQL article')
            check('3 words' not in request('GET', public_path)[2].get('reading_summary', ''),
                  'Plugin never renders the newer three-word private draft')
            plugin.disable()
            degraded = request('GET', public_path)
            check(degraded[0] == 200 and 'extension_error' in degraded[2]
                  and 'reading_summary' not in degraded[2], 'Revoked plugin grant fails closed without hiding public content')
            plugin.select(plugin.approved)
            check('reading_summary' in request('GET', public_path)[2], 'Grant restoration advances generation and recovers')
            plugin.select(plugin.hostile)
            with ThreadPoolExecutor(max_workers=1) as executor:
                pending = executor.submit(request, 'GET', public_path)
                plugin.worker()
                status, _, changed = request('POST', path + '/publish', expected,
                                             cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
                check(status == 200, 'Authenticated republish commits while old revision is in flight')
                check(pending.result(timeout=18)[0] == 409, 'In-flight plugin cannot return superseded revision')
            expected = {'expected_revision': changed['revision'], 'expected_version': changed['version']}
            with ThreadPoolExecutor(max_workers=1) as executor:
                pending = executor.submit(request, 'GET', public_path)
                worker = plugin.worker()
                check(worker['HostConfig']['Runtime'] == 'runsc'
                      and worker['HostConfig']['NetworkMode'] == 'none' and not worker['Mounts'],
                      'Observed actual gVisor worker has no network or credential mounts')
                status, _, withdrawn = request('POST', path + '/unpublish', expected,
                                               cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
                check(status == 200, 'Authenticated withdrawal commits during an isolated invocation')
                check(pending.result(timeout=18)[0] == 404, 'Failed in-flight plugin cannot return withdrawn snapshot')
            plugin.select(plugin.approved)
            expected = {'expected_revision': withdrawn['revision'], 'expected_version': withdrawn['version']}
            status, _, republished = request('POST', path + '/publish', expected,
                                             cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
            check(status == 200, 'Native authenticated publication recovers after plugin failure')
            docker('stop', '--time=20', plugin.name)
            degraded = request('GET', public_path)
            check(degraded[0] == 200 and 'extension_error' in degraded[2], 'Broker outage preserves public availability')
            check(request('GET', path, cookie=cookie)[0] == 200, 'Broker outage does not break core private reads')
            docker('start', plugin.name)
            for _ in range(30):
                recovered = request('GET', public_path)
                if recovered[0] == 200 and 'reading_summary' in recovered[2]:
                    break
                time.sleep(0.1)
            check('reading_summary' in recovered[2], 'Broker restart restores isolated rendering')
            expected = {'expected_revision': republished['revision'], 'expected_version': republished['version']}
        check(request('POST', path + '/unpublish', expected,
                      cookie=cookie, csrf=csrf, key=secrets.token_hex(32))[0] == 200, 'Actual HTTPS unpublish')
        check(request('GET', public_path)[0] == 404 and request('HEAD', public_path)[0] == 404,
              'Actual withdrawal denies GET and HEAD on fresh reads')
        if media is not None:
            for mime in ('image/png', 'image/jpeg', 'image/webp'):
                source = media.source(mime)
                status, _, upload = request('POST', '/v1/media', {'name': 'browser-image', 'mime': mime, 'data': source},
                                            cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
                check(status == 202, 'Actual HTTP image enters quarantine')
                if mime == 'image/png' and hasattr(media, 'recover_after_claim'):
                    media.recover_after_claim(sql, upload['id'])
                image_path = '/v1/media/' + upload['id']
                public_image = '/v1/sites/1/media/' + upload['id']
                check(request('GET', public_image)[0] == 404 and request('GET', image_path + '/file', cookie=cookie)[0] == 404,
                      'Original upload is inaccessible before isolated regeneration')
                check(media.process()['ready'] == 1, 'Actual runsc GD transformation commits')
                status, image_headers, png = request('GET', image_path + '/file', cookie=cookie)
                check(status == 200 and png.startswith(b'\x89PNG\r\n\x1a\n')
                      and image_headers['X-Content-Type-Options'] == 'nosniff', 'Only regenerated private PNG is served')
                info = request('GET', image_path, cookie=cookie)[2]
                check(info['width'] == 4 and info['height'] == 4 and not info['public'], 'Validated dimensions remain private')
                status, _, visible = request('POST', image_path + '/publish', {'expected_version': info['version']},
                                             cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
                check(status == 200 and request('GET', public_image)[0] == 200, 'Explicit media publication works')
                check(request('GET', '/v1/sites/2/media/' + upload['id'])[0] == 404, 'Public media cross-site denial')
                check(request('POST', image_path + '/withdraw', {'expected_version': visible['version']},
                              cookie=cookie, csrf=csrf, key=secrets.token_hex(32))[0] == 200
                      and request('GET', public_image)[0] == 404, 'Media withdrawal revokes next read')
            malformed = base64.b64encode(b'\x89PNG\r\n\x1a\ninvalid compressed image').decode()
            status, _, upload = request('POST', '/v1/media', {'name': 'malformed', 'mime': 'image/png', 'data': malformed},
                                        cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
            check(status == 202 and media.process()['invalid'] == 1, 'Decoder rejects malformed image inside sandbox')
            check(request('GET', '/v1/media/' + upload['id'], cookie=cookie)[2]['status'] == 'failed',
                  'Invalid image failure is durable and never public')
        browser_report = None
        editor_report = None
        if editor_proof:
            node = shutil.which('node.exe')
            script = subprocess.run(['wslpath', '-w', str(root / 'tests/axiompress/gutenberg-browser-test.cjs')],
                                    capture_output=True, text=True, check=True, timeout=10).stdout.strip()
            result = subprocess.run([node, script], input=json.dumps({'origin': origin, 'username': 'alice',
                'password': (directory / 'user-password').read_text()}), capture_output=True, text=True, timeout=180)
            if result.returncode:
                raise RuntimeError('Gutenberg acceptance failed: ' + result.stderr[-3000:])
            editor_report = json.loads(result.stdout)
            check(editor_report['outcome'] == 'passed', 'Actual Gutenberg/native API acceptance')
            editor_report['assets'] = editor_assets.manifest
        if browser:
            if media is not None:
                for title, width, height in (('Landscape acceptance', 1200, 800), ('Portrait acceptance', 600, 1200)):
                    status, _, upload = request('POST', '/v1/media', {'name': title, 'mime': 'image/png',
                        'data': media.source('image/png', width, height)}, cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
                    check(status == 202, 'Browser media fixture upload: HTTP ' + str(status))
                    processed = media.process()
                    check(processed['ready'] == 1, 'Actual prepared browser media fixture: ' + json.dumps(processed))
            node = shutil.which('node.exe')
            if node is None:
                raise RuntimeError('Windows Node.js and installed Chrome required for --browser')
            script = subprocess.run(['wslpath', '-w', str(root / 'tests/axiompress/admin-browser-test.cjs')],
                                    capture_output=True, text=True, check=True, timeout=10).stdout.strip()
            sql("UPDATE axiom_identity.memberships SET role='owner' WHERE site_id=1 AND user_id=1")
            try:
                result = subprocess.run([node, script], input=json.dumps({'origin': origin, 'username': 'alice',
                    'password': (directory / 'user-password').read_text(), 'populated_media': media is not None}),
                    capture_output=True, text=True, timeout=180)
                if result.returncode:
                    raise RuntimeError('Browser acceptance failed: ' + result.stderr[-2000:])
                browser_report = json.loads(result.stdout)
                check(browser_report['outcome'] == 'passed', 'Actual Chrome workspace acceptance')
            finally:
                sql("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1")
        check(request('DELETE', '/v1/session', cookie=cookie, csrf=body['csrf_token'])[0] == 200, 'Actual HTTPS logout')
        check(request('GET', '/v1/session', cookie=cookie)[0] == 401, 'Actual HTTP replay denied')
        state = json.loads(docker('inspect', container))[0]
        check(state['HostConfig']['ReadonlyRootfs'] and len(state['Mounts']) == (4 if plugin is not None else 3)
              and all(not mount['RW'] for mount in state['Mounts']), 'Only read-only runtime secrets/CA mounts')
        for index, (before, after) in enumerate((
                ('AXIOM_PUBLIC_BACKEND=postgres', 'AXIOM_PUBLIC_BACKEND=invalid'),
                ('AXIOM_PUBLIC_PG_PASSWORD_FILE=/run/secrets/public', 'AXIOM_PUBLIC_PG_PASSWORD_FILE=/run/secrets/auth'))):
            broken = container + '-bad-' + str(index)
            containers.append(broken)
            core_containers.append(broken)
            arguments = runtime.copy()
            arguments[arguments.index(container)] = broken
            arguments[arguments.index(before)] = after
            docker(*arguments)
            backend_port = port_for(broken)
            status, _, failure = request('GET', public_path)
            check(status == 503 and failure['error'] == 'unavailable' and len(failure['diagnostic_id']) == 24,
                  'Invalid public backend/credential fails closed without fallback')
        return {'outcome': 'passed', 'checks': checks, 'core_image': image,
                'isolated_plugin': plugin is not None, 'worker_runtime': 'runsc' if plugin is not None else None,
                'supervisor_image': plugin.image if plugin is not None else None,
                'approved_plugin_image': plugin.approved if plugin is not None else None,
                'isolated_media': media is not None,
                'direct_media': media.report() if media is not None and hasattr(media, 'report') else None,
                'media_processor_image': media.processor_image if media is not None else None,
                'browser': browser_report,
                'editor_proof': editor_report,
                'production_acceptance': False}
    finally:
        if thread is not None:
            server.shutdown()
            thread.join(5)
        server.server_close()
        for container in reversed(core_containers):
            if docker('ps', '-aq', '--filter', 'name=^/' + container + '$'):
                docker('rm', '-f', container)
