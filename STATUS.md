# AxiomPress development status

Last reviewed: 2026-10-31. Implementation baseline: `13613337f1`, following `50461b71bf`, plus uncommitted scheduler hardening in the working tree. Check Git status and subsequent commits before resuming; this page describes verified development work, not production readiness.

## Current implementation

The independent PHP 8.5 core has PostgreSQL-backed authentication, article changes and revisions, publication, jobs, private image processing, taxonomy and settings. The administration workspace and reviewed Gutenberg paragraph/heading editing experiment remain incomplete replacements for familiar WordPress workflows. The proof editor now serves the pinned build's minified scripts (43 files, about 5.4 MB, down from about 12.9 MB); this is still not a production performance budget. Preserve the inherited comparison environment.

Approved plugins and fixed image decoders can execute directly with gVisor. The existing Docker backend remains available. Package permissions, revocation, Unix peer checks, resource restrictions and recovery have local Linux evidence. The host PHP/PostgreSQL image controller has passed HTTP upload, private regeneration, publication, withdrawal and interrupted-job tests.

Linux extension-service packaging and installation passed 19 systemd checks, including integrity, approval, guest restrictions and forced restart recovery. Installation does not enable or start services. The privileged host launcher requires privilege transitions; guest no-new-privileges and empty capability restrictions remain enforced.

Image scheduling now has a hardened installer for an inactive systemd service and timer using an already provisioned direct runtime. Installation requires direct-backend fields with no Docker configuration, three separate directories for batch intent, runtime state and artifact store, a delegated cgroup parent with CPU, memory and process controllers, and database credentials kept as root-owned files outside worker directories. The service unit bounds the batch with start and stop timeouts, delegated resource controls and cgroup termination of the controller process. Unit rendering, system verification, interval and instance rejection, missing-field and Docker-key refusal, and separate-directory refusal passed. The timer was validated against actual PostgreSQL jobs and direct gVisor execution. Automatic creation of the media runtime delegation and restart acceptance on a production host remain unfinished.

## Resume here

1. Return to the incomplete publishing platform work. Done in this cycle: smaller proof bundle and verified package-update mechanics. Still open: production editor mounting and production CSP, media selection inside the proof editor (the native workspace already selects prepared images; the proof allows only paragraphs and headings by design), per-installation plugin storage (denied by explicit scope decision on 2026-10-31; the architecture exclusion stands), a site-administrator update interface, and audit retention, deletion and read access control (append-only retention with capacity enforcement and count-budgeted rotation is implemented; recovery is implemented). The proof editor embeds already-published site images; uploads still go through the native workspace or API first. Administration and Gutenberg proof suites pass against the disposable site. Review feature scope before retiring any inherited workflow.
2. Complete production acceptance for the image-processing timer: automatic media runtime delegation, restart behavior on a supported host, signed distribution and independent security review. Blocked on a supported VPS host, release signing infrastructure with trusted keys, and independent reviewers. Keep installation inactive until explicit operator action.

## Recorded verification

- Direct worker suite: 64 local gVisor/PHP checks passed on 2026-10-31, with no Docker execution dependency and no production acceptance claim.
- Direct PostgreSQL image processing: 12 checks passed on 2026-10-31 covering actual job claims, direct gVisor decoding, live-lease replacement, expired-lease rejection and publication, alongside 51 migration, 72 authentication, 59 HTTPS, 56 content, 43 public-read, 36 job, 23 media and 28 platform checks.
- Publishing workspace acceptance: 18 Chrome administration checks passed on 2026-10-31 (21 with media fixtures) and 23 Gutenberg editor checks passed without media fixtures. With a prepared media fixture the proof reaches 26 checks, covering published-image selection, preview, native persistence and reload restoration alongside article saves, publication, preview, settings, terms, retry, session renewal, native editing and unsupported-document preservation. Two transient editor timeouts at different steps each passed on an identical re-run with no product change; the minified and unminified parsers produce identical output on representative documents. One argument-order defect in the new proof fetch was caught by the new checks and fixed; byte-canonical markup was verified against the native round-trip before the passing run.
- Editor bundle: minified proof inventory generates cleanly under the existing pin and assertions, and the full 23-check browser proof passes against the minified bundle (43 scripts, 5,374,354 bytes).
- Package updates: generation-advancing image changes, stale-generation rejection, cross-plugin-identity refusal, disable/enable and rollback through a new generation are implemented in the operator policy tool and covered by package/policy checks plus grant restoration in the HTTPS suite. A site-administrator update interface is not implemented.
- Operator audit retention: approve-read and disable attempts append exactly one bounded JSON record with timestamp, operator identity, generations, image, grants, outcome and operator-visible diagnostic; database internals are never recorded. Spool capacity is enforced before mutations run, lax or linked spool files are refused, and a full spool rotates to a timestamped archive within a count budget instead of blocking operations. The suite covers applied, rejected, full-spool, rotation, pruning and CLI end-to-end cases (133 package/policy checks). Retention, deletion and read access control remain deployment work.
- Linux extension-service installation: 19 actual systemd checks at the implementation baseline.
- Scheduling: unit rendering, system verification and installer refusal checks passed on 2026-10-31; the timer was validated against actual PostgreSQL jobs and direct gVisor execution. Installation stays inactive until explicit operator action.
- Required contributor checks: `composer lint:axiompress`, `composer test:protocol` (252 checks), `composer test:broker` (45 checks), block documents (32 checks), parser compatibility (8 checks), schema meta-validation (12 fixtures, 4 manifest cases) and domain contracts (56 checks) passed on 2026-10-31. `native-core-test.php` does not run on this Windows host (no sqlite driver by default, no symlink privilege, POSIX path expectations); it remains a Linux CI gate and the file is unchanged.

These are recorded results at the stated dates, not a claim that checks were rerun whenever this page is read. Repeat affected runtime tests after relevant changes.

## Remaining release work

Full administration/editor coverage, loss-aware data migration, PHP-FPM/PostgreSQL installation, signed distribution and updates, independent security review and supported-VPS acceptance remain unfinished. Local WSL2 evidence does not establish production acceptance. Data import support will not imply executable WordPress plugin or PHP theme compatibility.

## Authoritative references

- [Repository rules](AGENTS.md), [architecture](ARCHITECTURE.md) and [release policy](AXIOMPRESS_RELEASE_POLICY.md).
- [Linux installation and scheduling](tools/axiompress/linux/README.md).
- [Direct worker configuration and verification](tools/axiompress/runtime/README.md).
- [Account and publishing tests](tools/axiompress/database/PHASE3_ACCEPTANCE.md).

Keep this status current when implementation or verification changes. Record incomplete work explicitly; a successful check of one component does not complete the whole platform.
