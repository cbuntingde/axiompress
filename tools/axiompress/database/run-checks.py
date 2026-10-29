"""Disposable PostgreSQL 18 schema/migration proof; never targets an existing database."""

import json
import os
from pathlib import Path
import secrets
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


def docker(*args, data=None, timeout=30):
    result = subprocess.run(['docker', *args], input=data, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        # SQL/bootstrap data and credentials are deliberately absent from errors.
        detail = result.stderr
        for label in ('root', 'deployer', 'reader-a', 'reader-b'):
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
        for label in ('root', 'deployer', 'reader-a', 'reader-b'):
            path = directory / label
            path.write_text(secrets.token_hex(32))
            os.chown(path, 10001, 10001)
            path.chmod(0o400)
        openssl(directory, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-subj', '/CN=AxiomPress disposable CA', '-keyout', 'ca.key', '-out', 'ca.crt')
        openssl(directory, 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=postgres',
                '-keyout', 'server.key', '-out', 'server.csr')
        (directory / 'extensions').write_text('subjectAltName=DNS:postgres\n')
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
print('PASS: ' + str(report['checks']) + ' PostgreSQL migration/schema checks')
