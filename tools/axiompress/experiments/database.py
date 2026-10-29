"""Disposable PostgreSQL architecture experiment; never connects to the development CMS database."""
import concurrent.futures
import json
from pathlib import Path
import secrets
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[3]
IMAGE = "postgres@sha256:979bccfde35445db618c65107d27d08c6c21339d8dc3ad4c830c6633947be0e4"
NAME = "axiompress-engine-" + secrets.token_hex(6)
PASSWORD = secrets.token_hex(32)
READER_PASSWORD = secrets.token_hex(32)
results = []


def docker(*args, data=None, allow_failure=False, timeout=15):
    result = subprocess.run(["docker", *args], input=data, capture_output=True, timeout=timeout)
    if result.returncode and not allow_failure:
        raise RuntimeError(result.stderr.decode(errors="replace").replace(PASSWORD, "[redacted]")
                           .replace(READER_PASSWORD, "[redacted]"))
    return result


def sql(statement, user="postgres", fail=False, database="axiom_eval"):
    execution = ["exec", "-i", "--user", "postgres"]
    authentication = []
    if user != "postgres":
        execution += ["--env", "PGPASSWORD=" + READER_PASSWORD]
        authentication = ["-h", "127.0.0.1"]
    result = docker(*execution, NAME, "psql", *authentication, "-X", "-q", "-t", "-A",
                    "-v", "ON_ERROR_STOP=1", "-U", user, "-d", database,
                    data=statement.encode(), allow_failure=fail)
    if fail:
        assert result.returncode != 0, "Expected database denial"
        return result.stderr.decode()
    return result.stdout.decode().strip()


def check(name, condition):
    if not condition:
        raise AssertionError(name)
    results.append({"check": name, "outcome": "passed"})


try:
    docker("run", "-d", "--name", NAME, "--label", "dev.axiompress.prototype=engine-evaluation-v1",
           "--network=none", "--memory=512m", "--memory-swap=512m", "--cpus=1", "--pids-limit=128",
           "--tmpfs=/var/lib/postgresql:rw,size=256m", "--env", "POSTGRES_PASSWORD=" + PASSWORD,
           "--env", "POSTGRES_INITDB_ARGS=--auth-host=scram-sha-256 --auth-local=peer", IMAGE)
    deadline = time.monotonic() + 90
    while True:
        probe = docker("exec", "--user", "postgres", NAME, "pg_isready", "-h", "127.0.0.1", "-U", "postgres", allow_failure=True)
        if probe.returncode == 0:
            break
        if time.monotonic() > deadline:
            raise RuntimeError("PostgreSQL experiment startup deadline")
        time.sleep(0.2)
    sql("CREATE DATABASE axiom_eval;", database="postgres")
    version = sql("SELECT version();")
    sql("CREATE ROLE axiom_reader LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD '"
        + READER_PASSWORD + "';")
    sql("""
        REVOKE CREATE ON SCHEMA public FROM PUBLIC;
        CREATE TABLE content (
          site_id bigint NOT NULL, id bigint NOT NULL, title text NOT NULL,
          document jsonb NOT NULL CHECK (jsonb_typeof(document) = 'object'),
          version bigint NOT NULL CHECK(version > 0), visible boolean NOT NULL,
          PRIMARY KEY(site_id,id));
        CREATE TABLE revisions (
          site_id bigint NOT NULL, content_id bigint NOT NULL, version bigint NOT NULL,
          PRIMARY KEY(site_id,content_id,version), FOREIGN KEY(site_id,content_id) REFERENCES content(site_id,id));
        CREATE TABLE outbox (site_id bigint NOT NULL, event_id text NOT NULL, payload jsonb NOT NULL,
          PRIMARY KEY(site_id,event_id));
        CREATE TABLE idempotency (site_id bigint NOT NULL, installation text NOT NULL, actor text NOT NULL,
          operation text NOT NULL, key text NOT NULL, digest text NOT NULL, outcome jsonb NOT NULL,
          PRIMARY KEY(site_id,installation,actor,operation,key));
        INSERT INTO content VALUES (1,1,'Public','{"type":"article"}',1,true),
          (1,2,'Private','{}',1,false),(2,3,'Other tenant','{}',1,true);
        ALTER TABLE content ENABLE ROW LEVEL SECURITY;
        ALTER TABLE content FORCE ROW LEVEL SECURITY;
        CREATE POLICY public_reader ON content FOR SELECT TO axiom_reader USING(site_id=1 AND visible);
        GRANT USAGE ON SCHEMA public TO axiom_reader;
        GRANT SELECT ON content TO axiom_reader;
    """)
    bad_authentication = docker("exec", "--user", "postgres", "--env", "PGPASSWORD=wrong-fixture-password",
                                NAME, "psql", "-X", "-h", "127.0.0.1", "-U", "axiom_reader", "-d", "axiom_eval",
                                "-c", "SELECT 1", allow_failure=True)
    check("reader TCP connection requires its password", bad_authentication.returncode != 0
          and b"password authentication failed" in bad_authentication.stderr)
    check("distinct non-bypass reader identity", sql("SELECT current_user;", "axiom_reader") == "axiom_reader")
    check("database RLS hides private and other-site rows", sql("SELECT count(*) FROM content;", "axiom_reader") == "1")
    check("RLS is independent of caller site setting", sql("SET axiom.site='2'; SELECT count(*) FROM content;", "axiom_reader") == "1")
    check("reader cannot disable row security", "row-level security" in sql("SET row_security=off; SELECT * FROM content;", "axiom_reader", True))
    for label, query in (
        ("reader cannot mutate", "UPDATE content SET title='attack';"),
        ("reader cannot create tables", "CREATE TABLE forbidden(id int);"),
        ("reader cannot bypass into outbox", "SELECT * FROM outbox;"),
    ):
        check(label, "permission denied" in sql(query, "axiom_reader", True))
    check("composite foreign key binds tenant and content", "foreign key" in sql("INSERT INTO revisions VALUES(2,1,1);", fail=True))
    check("JSON document shape is constrained", "check constraint" in sql("INSERT INTO content VALUES(1,4,'Bad','[]',1,true);", fail=True))
    sql("BEGIN; INSERT INTO content VALUES(1,5,'Rollback','{}',1,true); INSERT INTO outbox VALUES(1,'rollback','{}'); ROLLBACK;")
    check("explicit rollback covers content and outbox", sql("SELECT (SELECT count(*) FROM content WHERE id=5)+(SELECT count(*) FROM outbox WHERE event_id='rollback');") == "0")
    sql("BEGIN; INSERT INTO content VALUES(1,6,'Atomic','{}',1,true); INSERT INTO outbox VALUES(1,'atomic','{}'); COMMIT;")
    check("content and outbox commit together", sql("SELECT (SELECT count(*) FROM content WHERE id=6)+(SELECT count(*) FROM outbox WHERE event_id='atomic');") == "2")
    sql("BEGIN; INSERT INTO outbox VALUES(1,'failed','{}'); INSERT INTO content VALUES(1,7,NULL,'{}',1,true); COMMIT;", fail=True)
    check("failed transaction leaves no outbox side effect", sql("SELECT count(*) FROM outbox WHERE event_id='failed';") == "0")
    sql("INSERT INTO idempotency VALUES(1,'plugin','editor','propose','k','digest','{}');")
    check("idempotency scope is unique", "duplicate key" in sql("INSERT INTO idempotency VALUES(1,'plugin','editor','propose','k','changed','{}');", fail=True))
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        futures = [executor.submit(sql, "UPDATE content SET version=version+1 WHERE site_id=1 AND id=1 AND version=1 RETURNING version;") for _ in range(2)]
        updates = [future.result() for future in futures]
    check("concurrent optimistic revisions have one winner", sorted(updates) == ["", "2"])
    sql("UPDATE content SET visible=false WHERE site_id=1 AND id=1;")
    check("fresh RLS read observes visibility revocation", sql("SELECT count(*) FROM content WHERE id=1;", "axiom_reader") == "0")
    dump = docker("exec", "--user", "postgres", NAME, "pg_dump", "-Fc", "-d", "axiom_eval").stdout
    sql("CREATE DATABASE axiom_restore;", database="postgres")
    docker("exec", "-i", "--user", "postgres", NAME, "pg_restore", "--exit-on-error", "-d", "axiom_restore", data=dump)
    check("logical backup restores content and policy", sql("SELECT count(*) FROM content;", "axiom_reader", database="axiom_restore") == "1")
    check("restore retains committed outbox", sql("SELECT count(*) FROM outbox WHERE event_id='atomic';", database="axiom_restore") == "1")
    report = {"outcome": "passed", "image": IMAGE, "version": version, "checks": results,
              "limits": "Disposable local invariant experiment; not migration, load, crash-recovery or production readiness proof."}
    output = ROOT / "docs/axiompress-postgres-invariants-last.json"
    output.parent.mkdir(exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    sys.stdout.write(f"PASS: {len(results)} PostgreSQL invariant/role/backup checks\n")
finally:
    docker("rm", "-f", NAME)
