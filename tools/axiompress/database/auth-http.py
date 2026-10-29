"""Actual HTTPS/frontend-to-core proof; backend is loopback-only in this disposable fixture."""

import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import secrets
import ssl
import socket
import threading
import time
import urllib.error
import urllib.request


def verify(root, directory, network, docker, containers, name):
    backend_port = None

    class Proxy(BaseHTTPRequestHandler):
        def log_message(self, _format, *_arguments):
            # Test access logs must not retain request URIs, cookies or credentials.
            return

        def forward(self):
            length = int(self.headers.get('Content-Length', '0'))
            if length > 819200:
                self.send_error(413)
                return
            body = self.rfile.read(length)
            headers = {key: self.headers[key] for key in ('Cookie', 'Origin', 'Content-Type', 'X-CSRF-Token', 'Idempotency-Key')
                       if key in self.headers}
            connection = http.client.HTTPConnection('127.0.0.1', backend_port, timeout=10)
            try:
                connection.request(self.command, self.path, body=body, headers=headers)
                result = connection.getresponse()
                data = result.read(20000)
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
        mounts = [part for source, target in (('auth-a', 'auth'), ('reader-a', 'public'), ('ca.crt', 'ca.crt')) for part in (
            '--mount', 'type=bind,source=' + str(directory / source) + ',target=/run/secrets/' + target + ',readonly')]
        runtime = ['run', '-d', '--name', container, '--network', network, '--read-only', '--cap-drop=ALL',
               '--security-opt=no-new-privileges:true', '--memory=128m', '--pids-limit=32',
               '--tmpfs=/tmp:rw,size=16m', '--publish', '127.0.0.1::8080',
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
                data = response.read(900000)
                return response.status, dict(response.headers), json.loads(data) if data else None

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
        expected = {'expected_revision': updated['revision'], 'expected_version': updated['version']}
        status, _, published = request('POST', path + '/publish', expected,
                                      cookie=cookie, csrf=csrf, key=secrets.token_hex(32))
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
        check(request('POST', path + '/unpublish', expected,
                      cookie=cookie, csrf=csrf, key=secrets.token_hex(32))[0] == 200, 'Actual HTTPS unpublish')
        check(request('GET', public_path)[0] == 404 and request('HEAD', public_path)[0] == 404,
              'Actual withdrawal denies GET and HEAD on fresh reads')
        check(request('DELETE', '/v1/session', cookie=cookie, csrf=body['csrf_token'])[0] == 200, 'Actual HTTPS logout')
        check(request('GET', '/v1/session', cookie=cookie)[0] == 401, 'Actual HTTP replay denied')
        state = json.loads(docker('inspect', container))[0]
        check(state['HostConfig']['ReadonlyRootfs'] and len(state['Mounts']) == 3
              and all(not mount['RW'] for mount in state['Mounts']), 'Only read-only runtime secrets/CA mounts')
        for index, (before, after) in enumerate((
                ('AXIOM_PUBLIC_BACKEND=postgres', 'AXIOM_PUBLIC_BACKEND=invalid'),
                ('AXIOM_PUBLIC_PG_PASSWORD_FILE=/run/secrets/public', 'AXIOM_PUBLIC_PG_PASSWORD_FILE=/run/secrets/auth'))):
            broken = container + '-bad-' + str(index)
            containers.append(broken)
            arguments = runtime.copy()
            arguments[arguments.index(container)] = broken
            arguments[arguments.index(before)] = after
            docker(*arguments)
            backend_port = port_for(broken)
            status, _, failure = request('GET', public_path)
            check(status == 503 and failure['error'] == 'unavailable' and len(failure['diagnostic_id']) == 24,
                  'Invalid public backend/credential fails closed without fallback')
        return {'outcome': 'passed', 'checks': checks, 'core_image': image}
    finally:
        if thread is not None:
            server.shutdown()
            thread.join(5)
        server.server_close()
