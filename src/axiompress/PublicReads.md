# Public PostgreSQL reads

Set `AXIOM_PUBLIC_BACKEND=postgres` to serve native articles through `GET` or `HEAD /v1/sites/{site}/content/{id}`. This uses the same anonymous kernel and optional public-only extension flow as the transitional reader. It does not require or inspect login cookies, actor headers or CSRF tokens. Query parameters and noncanonical IDs are rejected. Private, draft, archived, future, missing and other-site content return 404, including for a logged-in owner.

The backend is an explicit deployment choice for the whole public route. An absent `AXIOM_PUBLIC_BACKEND`, or the value `mysql`, retains the existing comparison projection. The value `postgres` selects the native domain. Empty/unknown values or failed PostgreSQL configuration return a non-disclosing 503; core never retries against MySQL or authentication/deployment credentials. An ID in one backend does not identify the same article in the other. Changing the setting is not data migration.

## Configuration and identity

Supply these operator-owned environment values and mount the secret/CA read-only:

| Variable                        | Value                                                 |
| ------------------------------- | ----------------------------------------------------- |
| `AXIOM_PUBLIC_BACKEND`          | `postgres`                                            |
| `AXIOM_PUBLIC_PG_HOST`          | Database hostname matching the server certificate     |
| `AXIOM_PUBLIC_PG_PORT`          | TCP port, normally `5432`                             |
| `AXIOM_PUBLIC_PG_DATABASE`      | Dedicated domain database                             |
| `AXIOM_PUBLIC_PG_USER`          | Separate restricted reader login                      |
| `AXIOM_PUBLIC_PG_PASSWORD_FILE` | Mounted 16–256-byte password without trailing newline |
| `AXIOM_PUBLIC_PG_CA_FILE`       | Mounted trusted CA, bounded to 64 KiB                 |
| `AXIOM_PUBLIC_SITE_ID`          | Canonical positive configured site ID                 |

The database administrator creates a nonprivileged LOGIN and grants `axiom_reader` with INHERIT TRUE. Deployment binds that login's current role OID to the intended site in `axiom_access.reader_sites`. Do not grant owner, deployer or authenticator membership, superuser, database/role creation, replication or RLS bypass. The repository rejects those known privileged identities and group flag drift; operators remain responsible for reviewing all additional role memberships/grants. Retiring or recreating a login requires explicit OID binding management.

The public connection is distinct from `AXIOM_AUTH_*`. Shared runtime connection validation enforces verified PostgreSQL TLS, nonpersistent PDO, native parameters, bounded regular secret files and validated configuration values. Deployment migration tooling is not loaded. A wrong credential never causes use of another credential. SQL privileges are the boundary; read-only transaction defaults add defense in depth. Neither changes the trust placed in core, database administrators or deployment.

## Publication and response semantics

The single bound SELECT joins articles only to their published revision, using only granted public columns. Forced row policies enforce publication time and login-OID site access. The configured site and requested site must also match in PHP. Current draft pointers, author data, sessions, credentials, receipts and outbox rows are never queried or returned.

Native PostgreSQL results use decimal **strings** for `site_id` and `content_id`, preserving 64-bit values in JavaScript. The response retains `api=axiompress.core/0.1` and `content` containing exactly those IDs, opaque revision, title and text. The transitional MySQL prototype retains its existing integer identifiers and markup-stripping behavior. Native title/text are stored source strings, not rendered HTML: JSON escapes HTML-sensitive bytes, and clients must render them as text. Block comments, shortcodes and tags cannot invoke PHP or rendering behavior.

Reads use fresh autocommit statement snapshots; the repository rejects a caller-held transaction rather than risk a stale pooled snapshot. Same-connection reads see committed withdrawal or republishing. A statement observes publication state at its snapshot; already delivered data cannot be recalled. The kernel rechecks visibility and revision after optional extension success or failure, returning 404 for withdrawal or 409 for changed content. Authenticated private reads are separate commands and confer no authority on the public endpoint or broker.

Titles and text remain limited to 4096 and 131072 UTF-8 bytes. SQL checks size before transferring values to PHP. Responses are JSON with no-store, nosniff and a restrictive CSP; public reads never issue a session cookie. HEAD applies identical publication checks and sends no body. There is no listing, public search, cache/CDN policy, block renderer or production server in this slice.

## Verification

Run `python3 tools/axiompress/database/run-checks.py` as root on the dedicated Linux Docker host. The public repository tests real reader logins, forced row policies, cross-site and private-column denials, fresh withdrawal/republication, future/archived/draft visibility, lossless IDs, TLS/password/configuration failure and post-extension withdrawal. HTTPS tests use the actual native image to create, edit, publish and withdraw an article and observe its anonymous GET/HEAD lifecycle. They also exercise invalid backend and wrong public password failures. The existing MySQL reader's Linux unit regressions remain required.

Production FPM/TLS frontend, load/admission, operational audit/recovery, importing, editor UI, plugin commands and outbox delivery remain separate gates. The full development gate, including isolated-plugin failure/revocation checks, is documented in [Phase 3 acceptance](../../tools/axiompress/database/PHASE3_ACCEPTANCE.md). It does not establish production acceptance.
