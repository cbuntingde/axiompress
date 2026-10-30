"""Disposable PostgreSQL 18 schema/migration proof; never targets an existing database."""

import json
import argparse
import os
from pathlib import Path
import secrets
import runpy
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[3]
IMAGE = 'postgres@sha256:979bccfde35445db618c65107d27d08c6c21339d8dc3ad4c830c6633947be0e4'
NAME = 'axiompress-schema-' + secrets.token_hex(12)
VERIFICATION_TAG = 'axiompress-database:checks-' + NAME.rsplit('-', 1)[1]
NETWORK = NAME + '-network'
containers = []
network_created = False
report = None
operator_checks = []
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--isolated-plugin', action='store_true', help='Require native gVisor for Phase 3 acceptance')
parser.add_argument('--isolated-media', action='store_true', help='Require native gVisor for media decoder acceptance')
parser.add_argument('--direct-media-runsc', type=Path,
                    help='Use this absolute runsc executable for host PHP/PostgreSQL image processing tests')
parser.add_argument('--browser', action='store_true', help='Run installed Windows Chrome against disposable HTTPS admin')
parser.add_argument('--editor-proof', action='store_true', help='Run pinned Gutenberg/native API proof in Windows Chrome')
options = parser.parse_args()
if options.direct_media_runsc is not None:
    if not options.direct_media_runsc.is_absolute():
        parser.error('--direct-media-runsc requires an absolute executable path')
    options.isolated_media = True


def docker(*args, data=None, timeout=30):
    result = subprocess.run(['docker', *args], input=data, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        # SQL/bootstrap data and credentials are deliberately absent from errors.
        detail = result.stderr
        for label in ('root', 'deployer', 'reader-a', 'reader-b', 'auth-a', 'auth-b', 'job-a', 'job-b', 'user-password'):
            secret = directory / label
            if secret.is_file():
                detail = detail.replace(secret.read_text(), '[redacted]')
        raise RuntimeError('Database verification Docker operation failed: ' + args[0] + ': ' + detail[-2000:])
    return result.stdout.strip()


def sql(text, database='axiom_domain'):
    return docker('exec', '-i', '--user=postgres', NAME, 'psql', '-X', '-q', '-t', '-A',
                  '-v', 'ON_ERROR_STOP=1', '-U', 'postgres', '-d', database, data=text)


def openssl(directory, *args):
    subprocess.run(['openssl', *args], cwd=directory, check=True, capture_output=True, timeout=15)


if os.name != 'posix' or os.getuid() != 0:
    raise RuntimeError('Run as root on the Linux daemon host, including the dedicated AxiomPress-Linux WSL distro')
with tempfile.TemporaryDirectory(prefix='axiompress-schema-', dir='/tmp') as temporary:
    directory = Path(temporary)
    try:
        for label in ('root', 'deployer', 'reader-a', 'reader-b', 'auth-a', 'auth-b', 'job-a', 'job-b', 'user-password'):
            path = directory / label
            path.write_text(secrets.token_hex(32))
            os.chown(path, 10001, 10001)
            path.chmod(0o400)
        openssl(directory, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=AxiomPress disposable CA', '-keyout', 'ca.key', '-out', 'ca.crt')
        openssl(directory, 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=postgres',
                '-keyout', 'server.key', '-out', 'server.csr')
        (directory / 'extensions').write_text('subjectAltName=DNS:postgres,DNS:localhost\n')
        openssl(directory, 'x509', '-req', '-in', 'server.csr', '-CA', 'ca.crt', '-CAkey', 'ca.key',
                '-CAcreateserial', '-days', '1', '-extfile', 'extensions', '-out', 'server.crt')
        openssl(directory, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=Untrusted disposable CA', '-keyout', 'wrong-ca.key', '-out', 'wrong-ca.crt')
        docker('pull', IMAGE, timeout=180)
        postgres_uid = int(docker('run', '--rm', '--network=none', '--entrypoint', 'id', IMAGE, '-u', 'postgres'))
        os.chown(directory / 'server.key', postgres_uid, postgres_uid)
        (directory / 'server.key').chmod(0o600)
        docker('network', 'create', NETWORK)
        network_created = True
        mounts = [part for file in ('root', 'ca.crt', 'server.crt', 'server.key')
                  for part in ('--mount', 'type=bind,source=' + str(directory / file)
                               + ',target=/run/secrets/' + file + ',readonly')]
        containers.append(NAME)
        docker('run', '-d', '--name', NAME, '--network', NETWORK, '--network-alias=postgres',
               '--network-alias=wrong-postgres', '--memory=512m', '--memory-swap=512m', '--pids-limit=128',
               *(['--publish', '127.0.0.1::5432'] if options.direct_media_runsc is not None else []),
               '--tmpfs=/var/lib/postgresql:rw,size=256m', '--env', 'POSTGRES_PASSWORD_FILE=/run/secrets/root',
               '--env', 'POSTGRES_INITDB_ARGS=--auth-host=scram-sha-256 --auth-local=peer', *mounts,
               IMAGE, '-c', 'ssl=on', '-c', 'ssl_cert_file=/run/secrets/server.crt',
               '-c', 'ssl_key_file=/run/secrets/server.key')
        for _ in range(150):
            ready = subprocess.run(['docker', 'exec', '--user=postgres', NAME, 'pg_isready',
                                    '-h', '127.0.0.1', '-U', 'postgres'], capture_output=True, timeout=5)
            if ready.returncode == 0:
                break
            time.sleep(0.2)
        else:
            raise RuntimeError('Disposable PostgreSQL readiness failed')
        sql('CREATE DATABASE axiom_domain;', database='postgres')
        sql('CREATE DATABASE axiom_protected;', database='postgres')
        sql("CREATE TABLE public.preserved(value text); INSERT INTO public.preserved VALUES ('retained');",
            database='axiom_protected')
        rejected = False
        try:
            sql((ROOT / 'tools/axiompress/database/bootstrap.sql').read_text(), database='axiom_protected')
        except RuntimeError:
            rejected = True
        if not rejected or sql('SELECT value FROM public.preserved;', database='axiom_protected') != 'retained':
            raise RuntimeError('Nonempty bootstrap did not preserve unrelated data')
        if sql("SELECT count(*) FROM pg_roles WHERE rolname='axiom_owner';") != '0':
            raise RuntimeError('Rejected bootstrap leaked role changes')
        operator_checks.append('nonempty bootstrap preserves data and creates no roles')
        sql((ROOT / 'tools/axiompress/database/bootstrap.sql').read_text())
        sql("ALTER ROLE axiom_deployer PASSWORD '" + (directory / 'deployer').read_text() + "';")
        for name, secret in (('reader_a', 'reader-a'), ('reader_b', 'reader-b')):
            sql("CREATE ROLE " + name + " LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '"
                + (directory / secret).read_text() + "'; GRANT axiom_reader TO " + name + ' WITH INHERIT TRUE;')
        docker('build', '--target=verification', '-f', str(ROOT / 'tools/axiompress/database/Dockerfile'),
               '-t', VERIFICATION_TAG, str(ROOT), timeout=240)
        verification_image = docker('image', 'inspect', VERIFICATION_TAG, '--format', '{{.Id}}')
        client_mounts = [part for file in ('deployer', 'reader-a', 'reader-b', 'ca.crt', 'wrong-ca.crt')
                         for part in ('--mount', 'type=bind,source=' + str(directory / file)
                                      + ',target=/run/secrets/' + file + ',readonly')]
        client = ['run', '--rm', '--network', NETWORK, '--read-only',
                        '--tmpfs=/tmp:rw,size=16m', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
                        '--env', 'AXIOM_PG_HOST=postgres', '--env', 'AXIOM_PG_DATABASE=axiom_domain',
                        '--env', 'AXIOM_PG_PORT=5432', '--env', 'AXIOM_PG_PASSWORD_FILE=/run/secrets/deployer',
                        '--env', 'AXIOM_PG_CA_FILE=/run/secrets/ca.crt', *client_mounts]
        output = docker(*client, verification_image, timeout=90)
        report = json.loads(output)
        if report['outcome'] != 'passed':
            raise RuntimeError('Database checks did not pass')
        before = sql("SELECT oid FROM pg_roles WHERE rolname='reader_a';")
        sql("UPDATE axiom_content.articles SET status='published',published_revision_id=repeat('a',64),"
            "published_at=now()-interval '1 second' WHERE site_id=1 AND id=1;")
        sql("DROP ROLE reader_a; CREATE ROLE reader_a LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS"
            " PASSWORD '" + (directory / 'reader-a').read_text() + "'; GRANT axiom_reader TO reader_a WITH INHERIT TRUE;")
        after = sql("SELECT oid FROM pg_roles WHERE rolname='reader_a';")
        probe = r'''$db=PDO::connect('pgsql:host=postgres;dbname=axiom_domain;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
            'reader_a',file_get_contents('/run/secrets/reader-a'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
            echo $db->query('SELECT count(*) FROM axiom_content.articles')->fetchColumn();'''
        if before == after or docker(*client, '--entrypoint', 'php', verification_image, '-r', probe) != '0':
            raise RuntimeError('Recreated login inherited old site authority')
        operator_checks.append('recreated login name does not inherit prior OID site binding')
        sql('ALTER ROLE axiom_reader BYPASSRLS;')
        drift = r'''require '/app/Connection.php'; require '/app/MigrationRunner.php';
            try {new AxiomPress\Database\MigrationRunner(AxiomPress\Database\Connection::fromEnvironment()); exit(2);}
            catch(RuntimeException $e) {if($e->getMessage()!=='Role attributes differ from the deployment bootstrap contract.'){
                throw $e;} echo 'blocked';}'''
        if docker(*client, '--entrypoint', 'php', verification_image, '-r', drift) != 'blocked':
            raise RuntimeError('Role privilege drift was not rejected')
        sql('ALTER ROLE axiom_reader NOBYPASSRLS;')
        operator_checks.append('migration startup refuses role privilege drift')
        report['checks'] += len(operator_checks)
        report['operator_checks'] = operator_checks
        sql('CREATE DATABASE axiom_auth_test;', database='postgres')
        sql("REVOKE ALL ON DATABASE axiom_auth_test FROM PUBLIC; REVOKE ALL ON SCHEMA public FROM PUBLIC;"
            "GRANT CONNECT ON DATABASE axiom_auth_test TO axiom_deployer,axiom_reader;"
            "GRANT CREATE ON DATABASE axiom_auth_test TO axiom_owner;"
            "ALTER DEFAULT PRIVILEGES FOR ROLE axiom_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;",
            database='axiom_auth_test')
        sql((ROOT / 'tools/axiompress/database/auth-bootstrap.sql').read_text(), database='axiom_auth_test')
        sql((ROOT / 'tools/axiompress/database/platform-bootstrap.sql').read_text(), database='axiom_auth_test')
        for name, secret in (('auth_a', 'auth-a'), ('auth_b', 'auth-b')):
            sql("CREATE ROLE " + name + " LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '"
                + (directory / secret).read_text() + "'; GRANT axiom_authenticator TO " + name
                + ' WITH INHERIT TRUE;', database='axiom_auth_test')
        for name, secret in (('job_a', 'job-a'), ('job_b', 'job-b')):
            sql("CREATE ROLE " + name + " LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '"
                + (directory / secret).read_text() + "'; GRANT axiom_job_runner TO " + name
                + ' WITH INHERIT TRUE;', database='axiom_auth_test')
        auth_client = client.copy()
        auth_client[auth_client.index('AXIOM_PG_DATABASE=axiom_domain')] = 'AXIOM_PG_DATABASE=axiom_auth_test'
        auth_mounts = [part for file in ('auth-a', 'auth-b', 'job-a', 'job-b', 'user-password') for part in (
            '--mount', 'type=bind,source=' + str(directory / file) + ',target=/run/secrets/' + file + ',readonly')]
        authentication = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                          '-d', 'display_errors=stderr', '/checks/auth-test.php', timeout=120))
        if authentication['outcome'] != 'passed':
            raise RuntimeError('Authentication checks did not pass')
        report['authentication'] = authentication
        report['content'] = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                             '-d', 'display_errors=stderr', '/checks/content-test.php', timeout=120))
        if report['content']['outcome'] != 'passed':
            raise RuntimeError('Content checks did not pass')
        report['public'] = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                            '-d', 'display_errors=stderr', '/checks/published-content-test.php', timeout=90))
        if report['public']['outcome'] != 'passed':
            raise RuntimeError('Public read checks did not pass')
        report['jobs'] = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                          '-d', 'display_errors=stderr', '/checks/jobs-test.php', timeout=90))
        if report['jobs']['outcome'] != 'passed':
            raise RuntimeError('Job checks did not pass')
        report['media'] = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                           '-d', 'display_errors=stderr', '/checks/media-test.php', timeout=90))
        if report['media']['outcome'] != 'passed':
            raise RuntimeError('Media checks did not pass')
        report['platform'] = json.loads(docker(*auth_client, *auth_mounts, '--entrypoint', 'php', verification_image,
                                              '-d', 'display_errors=stderr', '/checks/platform-test.php', timeout=90))
        if report['platform']['outcome'] != 'passed':
            raise RuntimeError('Platform checks did not pass')
        plugin = None
        media = None
        try:
            if options.isolated_media:
                if options.direct_media_runsc is not None:
                    media = runpy.run_path(str(ROOT / 'tools/axiompress/media/direct-fixture.py'))['Fixture'](
                        ROOT, directory, docker, NAME, options.direct_media_runsc)
                else:
                    media = runpy.run_path(str(ROOT / 'tools/axiompress/media/proof.py'))['Fixture'](
                        ROOT, directory, NETWORK, docker, NAME)
                media.start()
            if options.isolated_plugin:
                plugin = runpy.run_path(str(ROOT / 'tools/axiompress/service/phase3-fixture.py'))['Fixture'](
                    ROOT, docker, containers, NAME)
                plugin.start()
            report['https'] = runpy.run_path(str(ROOT / 'tools/axiompress/database/auth-http.py'))['verify'](
                ROOT, directory, NETWORK, docker, containers, NAME,
                lambda statement: sql(statement, database='axiom_auth_test'), plugin, media, options.browser,
                options.editor_proof)
        finally:
            if media is not None:
                media.close()
            if plugin is not None:
                plugin.close()
        report['postgres_image'] = IMAGE
        report['verification_image'] = verification_image
    finally:
        for name in reversed(containers):
            for identifier in docker('ps', '-aq', '--filter', 'name=^/' + name + '$').split():
                docker('rm', '-f', identifier)
        if network_created:
            docker('network', 'rm', NETWORK)

(ROOT / 'docs').mkdir(exist_ok=True)
(ROOT / 'docs/axiompress-phase3-database-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
if options.editor_proof:
    if (report['https']['editor_proof'] or {}).get('outcome') != 'passed':
        raise RuntimeError('Gutenberg component acceptance did not pass')
    (ROOT / 'docs/axiompress-gutenberg-native-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
if options.isolated_plugin:
    if not report['https']['isolated_plugin'] or report['https']['worker_runtime'] != 'runsc':
        raise RuntimeError('Phase 3 acceptance did not exercise the required isolated plugin')
    (ROOT / 'docs/axiompress-phase3-acceptance-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print('PASS: ' + str(report['checks']) + ' PostgreSQL migration/schema checks')
print('PASS: ' + str(report['authentication']['checks']) + ' authentication checks; '
      + str(report['https']['checks']) + ' HTTPS integration checks')
print('PASS: ' + str(report['content']['checks']) + ' content command checks')
print('PASS: ' + str(report['public']['checks']) + ' PostgreSQL public read checks')
print('PASS: ' + str(report['jobs']['checks']) + ' durable job/scheduler checks')
print('PASS: ' + str(report['media']['checks']) + ' media quarantine/authorization checks')
print('PASS: ' + str(report['platform']['checks']) + ' native platform checks')
if options.editor_proof:
    print('PASS: ' + str(report['https']['editor_proof']['checks']) + ' actual Gutenberg/native API browser checks')
if options.direct_media_runsc is not None:
    print('PASS: ' + str(report['https']['direct_media']['checks']) + ' direct PostgreSQL/image processing checks')
