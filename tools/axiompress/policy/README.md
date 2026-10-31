# Prototype installation policy

The live worker runner resolves its image and content.read grant from a separate SQLite policy store. It never accepts an image or authority claim from worker JSON. This is an operator-only prototype, not a public installer or a production control plane.

Package captures exactly manifest.json and worker.php from an operator-owned immutable source directory, checks bounded sizes and strict manifest fields, rejects symbolic links and hardlinks, and computes digests from the captured bytes. It never executes package PHP. Hostile archive extraction and concurrently mutable source trees are outside this loader's contract.

PackageBuilder builds captured bytes with a fixed recipe and a verified SDK image. Publisher Dockerfiles, build hooks and dependency resolution are unsupported. ImageBinding checks actual Docker inspection against source digests, plugin identity, SDK identity and fixed configuration. Labels establish consistency with this trusted local builder; they are not protection from a compromised Docker administrator. Approved images and build tags are retained; artifact retention and garbage collection need deployment policy.

PolicyStore registers immutable image mappings and creates disabled installations with no grants. Explicit approval enables content.read. Every policy mutation uses an expected generation and increments it atomically. Replacement images must retain plugin identity. Installation IDs cannot be deleted and reused through this API. Supervisor::fromPolicy pins each invocation to installation, site, image and current generation. Disable, grant removal or image replacement invalidates existing authority on its next broker check.

Directory ownership alone does not prove who approved a package, so every
registration additionally requires a publisher signature over the canonical
binding bytes, verified against publisher keys stored in the policy database
before the artifact is recorded. The canonical bytes are the ASCII fields
`plugin_id`, `version`, `package_digest`, `manifest_digest`, image
`sha256:...` digest and SDK `sha256:...` digest joined with single NUL bytes.
Signatures use RSA-2048 with SHA-256. A publisher key identifier is the hex
SHA-256 of the DER-encoded SubjectPublicKeyInfo, derived by the policy code
from the presented key so armoring differences cannot fork identities. Revoking
a key stops new registrations; already-registered artifacts keep running until
the operator disables their installations, so revocation is always paired with
generation review. Publisher additions and revocations append to the operator
audit trail. The live prototype bootstraps its publisher from an explicit
operator signature when the policy volume is empty, and refuses unsigned
registration once any publisher exists. Production key custody, publisher
onboarding and offline ceremonies remain operator release work; the verification
here only proves that unapproved bytes cannot enter the policy store.

The database belongs in a private operator-owned directory. SQLite uses DELETE rollback journaling, full synchronization, foreign keys and bounded lock waits. This permits a genuinely read-only service mount without writable WAL/SHM sidecars while retaining live revocation and locking visibility. Never use immutable SQLite snapshots for active policy. Runtime readers open the database read-only; the operator CLI owns writes. Stop old supervisors before the first operator open converts an existing WAL database to DELETE mode; conversion requires exclusive access and fails if locks prevent it. A hot journal after operator failure requires writable operator recovery; runtime readers fail closed. The read-only mount is defense in depth, not an OS boundary against the Docker-privileged supervisor. Neither workers nor the core bridge receive this volume. Unknown schema versions fail closed. Offline restoration requires stopping all existing authorities before restoring: generation counters alone provide no protection against backup rollback.

The live runner creates and explicitly approves a uniquely named synthetic installation in the axiompress-policy-prototype volume. setup-live.php requires AXIOM_APPROVE_LIVE_TEST=1. manage.php provides operator-only describe, disable and approve-read commands using expected generations. It needs the policy volume but no Docker socket. No public authorization endpoint is exposed.

Set AXIOM_POLICY_AUDIT_FILE to append one JSON record per approve-read or disable attempt. Each record carries timestamp, operator identity, installation, site, expected and resulting generations, image, grants, outcome and an operator-visible diagnostic; database internals are never recorded. Records are size-bounded, writes are locked and flushed, and a mutation is refused before it runs when the spool cannot hold another maximum-size record. A full spool rotates to a microsecond-timestamped archive within a count budget (AXIOM_POLICY_AUDIT_MAX_BYTES, AXIOM_POLICY_AUDIT_MAX_ARCHIVES, 30 by default; 0 disables rotation and refuses instead), so operations continue without losing records and pruning never takes the newest archive. After any operator crash compare the spool and archives against describe output. Retention, deletion and read access control remain deployment work.

Run the complete prototype with tools/axiompress/supervisor/run-live-checks.ps1. The policy unit suite runs in the Linux supervisor image (PDO SQLite required):

```powershell
docker run --rm --network=none --read-only --tmpfs=/tmp:rw,size=32m --entrypoint php axiompress-supervisor:prototype /app/policy/test.php
```

The suite covers 133 package, image-binding, persistence, denial, generation, rollback, concurrency, operator audit and rotation cases. runtime-test.php additionally verifies actual worker denial during a read, disabled admission, success after reapproval with a new generation, and empty/Unicode text. Shared single-host worker admission and operator crash recovery are documented in ../supervisor/README.md. Publisher authentication, stronger runtime isolation and production recovery readiness remain separate gates.
