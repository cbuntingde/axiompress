"""Disposable operator test harness; never part of the public core or worker image."""

import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import time
import sqlite3
from concurrent.futures import ThreadPoolExecutor

APP = Path(__file__).resolve().parent
RUNTIME = os.environ.get('AXIOM_TEST_RUNTIME', 'runc')
checks = []
server = None


def check(name, condition):
    if not condition:
        raise AssertionError(name)
    checks.append(name)


def request(body, uid=10001):
    code = '''import socket,struct,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(16)
out=bytearray()
try:
 s.connect('/channel/broker.sock')
 b=sys.stdin.buffer.read();s.sendall(struct.pack('!I',len(b))+b);s.shutdown(socket.SHUT_WR)
 while True:
  b=s.recv(4096)
  if not b:break
  out.extend(b)
except (ConnectionError,BlockingIOError,TimeoutError):pass
sys.stdout.buffer.write(out)
'''
    return subprocess.run(['python3', '-c', code], input=body, capture_output=True,
                          user=uid, group=10001, extra_groups=(), timeout=18, check=True).stdout


def payload(**changes):
    result = {'api': 'axiompress.core-broker/0.1', 'installation': 'service-reading',
              'site': 'site:1', 'content': {'content_id': 'post:1', 'revision': 'a' * 64,
                                         'title': 'Public fixture', 'text': 'One two three four five.'}}
    result.update(changes)
    return result


def invoke(value):
    response = request(json.dumps(value).encode())
    if len(response) < 4:
        return None
    size = struct.unpack('!I', response[:4])[0]
    check('exact response frame', size == len(response) - 4)
    return json.loads(response[4:])


def start(**changes):
    environment = dict(os.environ, AXIOM_SERVICE_RUNTIME=RUNTIME, AXIOM_ALLOW_DEV_RUNC='1', **changes)
    process = subprocess.Popen(['python3', str(APP / 'server.py')], env=environment,
                               stdout=subprocess.PIPE, stderr=None, text=True, group=10001, extra_groups=())
    # Readiness is emitted only after runtime preflight, exclusive recovery and socket permissions.
    import selectors
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    try:
        if not selector.select(25) or 'ready runtime=' + RUNTIME not in process.stdout.readline():
            process.terminate()
            process.wait(timeout=25)
            raise AssertionError('service readiness')
    finally:
        selector.close()
    return process


def core_response_case(output, command, mutation=None):
    path = Path('/channel/case.sock')
    listener = socket.socket(socket.AF_UNIX)
    listener.bind(str(path))
    os.chown(path, 0, 10001)
    os.chmod(path, 0o660)
    listener.listen(1)
    listener.settimeout(5)
    try:
        with ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(subprocess.run, command, user=10001, group=10001, extra_groups=(),
                                     capture_output=True, timeout=18)
            connection, _ = listener.accept()
            with connection:
                connection.settimeout(3)
                while connection.recv(8192):
                    pass
                if mutation:
                    mutation()
                connection.sendall(output)
            return future.result()
    finally:
        listener.close()
        path.unlink()


try:
    for path in ('/state', '/state/admission', '/state/workers', '/channel', '/policy'):
        Path(path).mkdir(parents=True, exist_ok=True)
        os.chmod(path, 0o750 if path == '/channel' else 0o700)
    os.chown('/channel', 0, 10001)
    os.environ['AXIOM_SERVICE_POOL'] = os.urandom(16).hex()
    os.environ['AXIOM_SERVICE_TEST'] = '1'
    Path('/state/pool').write_text(os.environ['AXIOM_SERVICE_POOL'])
    subprocess.run(['php', '-d', 'display_errors=stderr', str(APP / 'test-setup.php')],
                   check=True, stdout=subprocess.DEVNULL, timeout=60)
    server = start()
    first_started = time.monotonic()
    result = invoke(payload())
    first_ms = (time.monotonic() - first_started) * 1000
    check('useful plugin through real service', result['component']['word_count'] == 5)
    for text, count in (('', 0), ('κόσμε café 你好', 3), ('x' * 131072, 1)):
        value = payload()
        value['content']['text'] = text
        check('bounded empty/Unicode/maximum snapshot', invoke(value)['component']['word_count'] == count)
    for changes in ({'actor': 'admin'}, {'site': 'site:2'}, {'installation': 'unknown'},
                    {'installation': 'service-other'}, {'runtime': 'runc'}, {'api': 'unknown'}):
        check('forged core scope rejected', invoke(payload(**changes)) == {'error': 'extension_unavailable'})
    for field, invalid in (('text', 'x' * 131073), ('revision', 'v1'), ('content_id', 'post:01'),
                           ('title', 'x' * 4097)):
        value = payload()
        value['content'][field] = invalid
        check('invalid snapshot rejected', invoke(value) == {'error': 'extension_unavailable'})
    check('root peer cannot assert core identity', request(json.dumps(payload()).encode(), uid=0) == b'')
    duplicate = json.dumps(payload()).replace('"site": "site:1"', '"site":"site:2","site":"site:1"')
    check('duplicate authority fields rejected', request(duplicate.encode()) == b'')
    client = subprocess.run(['php', str(APP / 'test-client.php')], user=10001, group=10001, extra_groups=(),
                            capture_output=True, timeout=18)
    if client.returncode:
        raise AssertionError(client.stdout.decode() + client.stderr.decode())
    rendered = client.stdout
    check('real core client validates and renders', b'5 words' in rendered and b'<span' in rendered)
    component = {'component': {'type': 'reading_summary', 'word_count': 3, 'reading_seconds': 1}}
    encoded = json.dumps(component).encode()
    good_frame = struct.pack('!I', len(encoded)) + encoded
    for output in (b'\x00\x00', struct.pack('!I', 1025), good_frame + b'extra',
                   b'\x00\x00\x00\x01{',
                   struct.pack('!I', 2) + b'{}'):
        rejected = core_response_case(output, ['php', str(APP / 'test-client.php'), 'text', '/channel/case.sock'])
        check('core rejects malformed service response', rejected.returncode != 0)
    for status, sql in ((404, "UPDATE axiompress_public_test SET post_status='private'"),
                        (409, "UPDATE axiompress_public_test SET post_content='Changed'")):
        database = '/tmp/core-' + os.urandom(8).hex() + '.sqlite'

        def mutate():
            with sqlite3.connect('file:' + database + '?mode=rw', uri=True) as db:
                db.execute(sql)

        changed = core_response_case(good_frame, ['php', str(APP / 'test-kernel.php'), database], mutate)
        check('core reauthorizes after extension: ' + str(status),
              changed.returncode == 0 and json.loads(changed.stdout)['status'] == status)
        Path(database).unlink()
    database = '/tmp/core-' + os.urandom(8).hex() + '.sqlite'
    error = b'{"error":"extension_unavailable"}'
    failed_extension = core_response_case(struct.pack('!I', len(error)) + error,
        ['php', str(APP / 'test-kernel.php'), database])
    failed_data = json.loads(failed_extension.stdout)
    check('optional failure preserves public core response', failed_extension.returncode == 0
          and failed_data['status'] == 200 and 'reading_summary' not in failed_data['data']
          and failed_data['data']['extension_error']['code'] == 'extension_unavailable')
    Path(database).unlink()
    for mode in ('crash', 'malformed', 'forged', 'cross-resource', 'quota', 'hang'):
        value = payload(installation='service-hostile')
        value['content']['text'] = mode
        before = time.monotonic()
        check('hostile package contained: ' + mode, invoke(value) == {'error': 'extension_unavailable'})
        check('hostile deadline bounded: ' + mode, time.monotonic() - before < 12)
        check('useful service recovers after: ' + mode, invoke(payload())['component']['word_count'] == 5)
    foreign = payload(installation='service-foreign')
    foreign['content']['text'] = 'forged'
    check('separately identified hostile plugin cannot forge authority',
          invoke(foreign) == {'error': 'extension_unavailable'})
    value = payload(installation='service-hostile')
    value['content']['text'] = 'hang'
    with ThreadPoolExecutor(max_workers=1) as executor:
        pending = executor.submit(invoke, value)
        until = time.monotonic() + 3
        while time.monotonic() < until:
            running = subprocess.check_output(['docker', 'ps', '-q', '--filter',
                'label=dev.axiompress.service=' + os.environ['AXIOM_SERVICE_POOL']], text=True, timeout=3)
            if running.strip():
                break
            time.sleep(0.02)
        else:
            raise AssertionError('revocation worker not observed')
        before = time.monotonic()
        disabled = subprocess.check_output(['php', '/app/policy/manage.php', 'disable',
            'service-hostile', 'site:1', '2'], timeout=5)
        check('live installation revocation stops service worker', pending.result(timeout=5)
              == {'error': 'extension_unavailable'} and time.monotonic() - before < 3)
        record = json.loads(disabled)
        subprocess.run(['php', '/app/policy/manage.php', 'approve-read', 'service-hostile', 'site:1',
                         '3', record['image']], check=True, stdout=subprocess.DEVNULL, timeout=5)
    check('new grant generation admits useful work', invoke(payload())['component']['word_count'] == 5)
    # Occupy all handler slots with incomplete authenticated frames, then recover after their deadline.
    slow_code = '''import socket,time
sockets=[]
for _ in range(4):
 s=socket.socket(socket.AF_UNIX);s.connect('/channel/broker.sock');s.sendall(b'\\x00');sockets.append(s)
print('connected',flush=True);time.sleep(2.5)
'''
    slow = subprocess.Popen(['python3', '-c', slow_code], user=10001, group=10001, extra_groups=(),
                            stdout=subprocess.PIPE, text=True)
    check('slow peers connected', slow.stdout.readline().strip() == 'connected')
    before = time.monotonic()
    check('overload rejected without queue', request(json.dumps(payload()).encode()) == b'')
    check('overload rejection under one second', time.monotonic() - before < 1)
    check('slow handlers expire', slow.wait(timeout=5) == 0)
    check('service recovers after slow peers', invoke(payload())['component']['word_count'] == 5)
    timings = []
    for _ in range(20):
        before = time.monotonic()
        check('cold service invocation', invoke(payload())['component']['word_count'] == 5)
        timings.append((time.monotonic() - before) * 1000)
    # A local prototype budget, not a production SLO. No warm pool is implemented or implied.
    p95 = sorted(timings)[18]
    check('cold service local p95 under 1500 ms', p95 < 1500)
    scaling = []
    for count in (1, 4, 16, 32):
        before = time.monotonic()
        peak = 0
        with ThreadPoolExecutor(max_workers=8) as executor:
            futures = [executor.submit(invoke, payload(installation='scale-' + str(index),
                       site='site:' + str(100 + index))) for index in range(count)]
            while not all(future.done() for future in futures):
                running = subprocess.check_output(['docker', 'ps', '-q', '--filter',
                    'label=dev.axiompress.service=' + os.environ['AXIOM_SERVICE_POOL']], text=True, timeout=3)
                peak = max(peak, len(running.split()))
                check('aggregate worker ceiling', peak <= 4)
                time.sleep(0.02)
            responses = [future.result() for future in futures]
        successful = sum(bool(response and 'component' in response) for response in responses)
        check('increasing installation workload makes progress', successful > 0)
        check('parallelism observed', peak >= (1 if count == 1 else 2))
        scaling.append({'installations': count, 'successful': successful,
                        'rejected': count - successful, 'peak_workers': peak,
                        'elapsed_ms': (time.monotonic() - before) * 1000})
    # Owner lock prevents a second supervisor from deleting live workers/socket.
    duplicate_server = subprocess.run(['python3', str(APP / 'server.py')],
        env=dict(os.environ, AXIOM_SERVICE_RUNTIME=RUNTIME, AXIOM_ALLOW_DEV_RUNC='1'),
        capture_output=True, timeout=25, group=10001)
    check('second owner denied', duplicate_server.returncode != 0)
    server.terminate()
    check('graceful shutdown', server.wait(timeout=25) == 0)
    server = None
    # Simulate interrupted confirmed creation with a real stopped service-owned container.
    image = os.environ['AXIOM_TEST_WORKER']
    name = 'axiompress-worker-' + os.urandom(12).hex()
    subprocess.run(['docker', 'create', '--name', name, '--label',
                    'dev.axiompress.service=' + os.environ['AXIOM_SERVICE_POOL'],
                    '--network=none', image], check=True, stdout=subprocess.DEVNULL)
    Path('/state/workers', name).write_text('created')
    Path('/state/admission/slot-0').write_text('{"installation":"service-reading","site":"site:1"}')
    server = start()
    check('restart recovers actual orphan and capacity', invoke(payload())['component']['word_count'] == 5)
    server.terminate()
    check('recovered shutdown', server.wait(timeout=25) == 0)
    server = None
    Path('/state/workers', name).write_text('creating')
    failed = subprocess.run(['python3', str(APP / 'server.py')],
        env=dict(os.environ, AXIOM_SERVICE_RUNTIME=RUNTIME, AXIOM_ALLOW_DEV_RUNC='1'),
        capture_output=True, timeout=25, group=10001)
    check('ambiguous create fails closed', failed.returncode != 0 and Path('/state/workers', name).exists())
    Path('/state/workers', name).unlink()  # Synthetic intent; no corresponding create was submitted.
    info = json.loads(subprocess.check_output(['docker', 'info', '--format', '{{json .Runtimes}}']))
    if 'runsc' not in info:
        missing = subprocess.run(['python3', str(APP / 'server.py')],
            env=dict(os.environ, AXIOM_SERVICE_RUNTIME='runsc'), capture_output=True, timeout=25, group=10001)
        check('missing runsc never downgrades', missing.returncode != 0)
    report = {'outcome': 'passed', 'checks': checks, 'runtime': RUNTIME,
                      'gvisor_service_integration': RUNTIME == 'runsc',
                      'gvisor_resource_acceptance': False, 'cold_p95_ms': p95,
                      'first_invocation_ms': first_ms,
                      'warm_worker_latency_ms': None, 'worker_reuse': 'disabled; every invocation starts a fresh worker',
                      'cold_samples': len(timings), 'scaling': scaling}
finally:
    if server is not None and server.poll() is None:
        server.terminate()
        server.wait(timeout=25)
    # Only this disposable test's random pool is owned by this harness.
    pool = os.environ.get('AXIOM_SERVICE_POOL')
    if pool:
        ids = subprocess.check_output(['docker', 'ps', '-aq', '--filter',
                                       'label=dev.axiompress.service=' + pool], text=True, timeout=5).split()
        for identifier in ids:
            subprocess.run(['docker', 'rm', '-f', identifier], check=True, timeout=5,
                           stdout=subprocess.DEVNULL)

print(json.dumps(report))
