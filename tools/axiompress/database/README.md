# PostgreSQL domain foundation

The [Phase 3 acceptance contract](PHASE3_ACCEPTANCE.md) maps the completed native login/article/public-read/isolated-plugin workflow to its tests. Add `--isolated-plugin` on the native Linux gVisor host to run that full gate. Plain CI checks do not require or claim runsc acceptance. The flagged proof preserves its separate result under ignored docs.

This contains the PostgreSQL 18 domain schema, deployment-only migration runner and native authentication migration. Migration 0001 establishes the foundation; 0002 adds function-only login/session operations. Migration 0003 enables fixed core-only article commands with session/resource checks, receipts and a publication outbox. Plugin database access is not enabled. The existing native MySQL read projection and WordPress comparison site remain available. The native anonymous endpoint can now select the restricted PostgreSQL reader; see [public read configuration](../../../src/axiompress/PublicReads.md). No existing site data is migrated or discarded.

Before migrating a new or existing database through 0002, its administrator must execute `auth-bootstrap.sql` once to create the reserved cluster-wide `axiom_authenticator` group and grant database CONNECT. Provision site logins and OID bindings separately as described in [the authentication contract](../../../src/axiompress/Auth/README.md). The immutable 0001 migration and original bootstrap remain unchanged. Existing applied checksums must never be rewritten.

## Ownership and provisioning

Use a new, dedicated database and the operator-reviewed `bootstrap.sql`, executed once by its PostgreSQL administrator. Bootstrap is transactional and refuses nonempty application schemas/public objects and pre-existing role names. The fixed role names are cluster-wide: use a dedicated cluster or reserve these names for one AxiomPress deployment. The bootstrap installs no passwords.

-   `axiom_owner`: NOLOGIN, non-superuser, no database/role creation, replication or RLS bypass. Owns schema objects and has explicit deployment-only row policies.
-   `axiom_deployer`: separate LOGIN with the same restricted attributes and NOINHERIT. Its owner membership permits explicit SET ROLE, not implicit owner privileges or membership administration. This is the only accepted migration login.
-   `axiom_reader`: NOLOGIN runtime group, without DDL, temporary-table, sequence, private-table or mutation privileges in the target database. Provision separate nonprivileged application logins and grant this group with INHERIT TRUE. Do not grant the deployment identity to runtime logins.

The administrator must provision the deployer password and trusted server TLS certificate separately. Core/application and plugin containers must never receive that deployment secret or migration image. The public native image does not load these tools. Application-owned credentials and deployment-owned credentials remain separate even when local containers use the same numeric Unix UID.

## Initial schema

`0001_identity_content.sql` creates private identity, access-policy and content schemas. Sites, users, credentials and memberships have explicit keys and constraints. Credentials require the shape of an Argon2id hash; SQL format checks do not validate its cryptographic correctness. Native authentication now verifies a fixed Argon2id profile and implements bounded rate/session state in 0002. Credential provisioning, enrollment and recovery remain operator/future service work.

Articles and revisions use composite site/resource keys. Current and published revision references are deferrable foreign keys to the same article and site, allowing creation in one transaction without dangling committed references. Authors and revision creators must have a membership in that site. Titles are bounded to 4096 bytes and bodies to 131072 bytes, including multibyte UTF-8. Revision identifiers are opaque 64-character lowercase hexadecimal tokens; this schema does not establish a content-hash algorithm. Optimistic `lock_version` is available for the forthcoming typed command service; the implemented core command service enforces authorization and compare-and-swap within its transaction. No direct runtime table-write role is enabled; migration 0003 grants only execution of the fixed article command function.

Readers see only a currently published, nonfuture article and its published revision. A newer draft revision remains private even when the article has an older published revision. Column grants also hide current draft pointers and author metadata. Forced row policies bind access through deployment-owned `axiom_access.reader_sites` records to the authenticated database login's role OID. Session variables and SET ROLE cannot select another site; a dropped/recreated login name does not inherit its old OID binding. Provisioners must explicitly manage bindings when creating, rotating or retiring logins. Database roles are not end-user CMS roles.

The narrow SECURITY DEFINER scope helper has a fixed search path, qualified relations, no caller-supplied role argument and no PUBLIC execution grant. Runtime roles cannot read or edit its binding table. Deployment retains authority for controlled data migrations; these boundaries do not protect against a compromised database administrator/deployer or substitute for the future core actor policy.

## Deployment migration CLI

Build the `migration` target of `tools/axiompress/database/Dockerfile` from the repository root. It contains only deployment tooling and PDO PostgreSQL, runs as UID/GID 10001, and has no Docker socket or application bootstrap. Keep its code/migrations read-only and mount its password and CA certificate read-only. The Dockerfile-specific context allowlist excludes local plans, caches and credentials.

Supply these deployment environment values:

| Variable                 | Purpose                                                                 |
| ------------------------ | ----------------------------------------------------------------------- |
| `AXIOM_PG_HOST`          | PostgreSQL hostname matching its certificate                            |
| `AXIOM_PG_PORT`          | Valid TCP port, usually 5432                                            |
| `AXIOM_PG_DATABASE`      | Dedicated database name                                                 |
| `AXIOM_PG_PASSWORD_FILE` | Mounted deployer password, 16Ã¢â‚¬â€œ256 bytes without trailing newline |
| `AXIOM_PG_CA_FILE`       | Mounted trusted CA certificate/bundle                                   |

The SQL login is always `axiom_deployer`. TLS uses `verify-full`; there is no plaintext-test switch. The CLI is `php /app/migrate.php [operator-owned-migration-directory]`, defaulting to `/app/migrations`. Success emits the applied versions; an already-current database emits an empty list. Failure exits nonzero with a bounded diagnostic reference, without printing SQL, passwords or data. Use a dedicated connection, not an application pool.

Migrations form a consecutive `0001_name.sql` sequence, at most 100 files and 1 MiB per file. Sources must be regular, nonsymlink UTF-8 files with LF bytes and no BOM/NUL. Treat the deployment source directory and its ancestors as immutable/operator-controlled. The runner captures bytes, verifies the entire applied history's names/order/SHA-256 checksums, and refuses missing or edited history; corrections require a new forward migration. No automatic down migrations, destructive reset or history rewrite is provided.

A session advisory lock excludes concurrent migrators immediately. Each migration and its ledger entry commit in one transaction. SQL executes through a PostgreSQL DO block inside that explicit transaction, so embedded COMMIT/ROLLBACK and operations requiring transaction-free execution fail at the database boundary. Prior successful versions remain committed if a later migration fails. Ledger initialization is also transactional. A failed migration can be retried after correcting its still-unapplied source. A killed client loses its transaction and lock; connection checks and statement/lock/idle timeouts bound failure handling. Production schema changes still require reviewed rollout, lock/load, backup and recovery plans.

## Verification

Run as root on the Linux Docker daemon host, from the checkout root:

```sh
python3 tools/axiompress/database/run-checks.py
```

On this Windows workspace, select the dedicated `AxiomPress-Linux` WSL distro explicitly, then run that command in the mounted checkout. Docker Desktop configuration is not changed. The harness creates only randomly named disposable PostgreSQL/network resources, generates fresh SCRAM secrets and a short-lived test CA/server certificate, runs the verification image, and removes containers, network and secrets before publishing its result under ignored `docs/`.

The suite verifies bootstrap refusal without unrelated data loss, actual TLS hostname/CA rejection, SCRAM authentication, migration idempotence/history validation, failed-DDL rollback/retry, embedded-commit denial, concurrent exclusion, client interruption, role drift, role-OID rebinding, tenant/publication filtering and SQL privilege denials. CI runs the same real PostgreSQL suite in its own job. Local success does not establish production HA, disaster recovery, sustained load, supported live upgrades, WordPress importing or complete authenticated CMS behavior.
