# Phase 3: minimal CMS vertical slice

The Phase 3 development gate is complete on the dedicated AxiomPress Linux/WSL2 engine. This is acceptance of the minimal native CMS pipeline, not production readiness or the complete replacement CMS.

## Reproduce the gate

Run as root on the Linux Docker daemon host with the pinned gVisor runtime registered and cgroups v2:

```sh
python3 tools/axiompress/database/run-checks.py --isolated-plugin
```

On this Windows workspace, explicitly select the independent daemon:

```powershell
wsl -d AxiomPress-Linux -u root --exec python3 /mnt/c/chris-dev/wordpress-develop/tools/axiompress/database/run-checks.py --isolated-plugin
```

There is no runtime fallback. The fixture creates its own PostgreSQL database, TLS CA/secrets, package policy, pool, channel and containers. It builds immutable SDK/supervisor/plugin images, mounts only a read-only channel into core and launches real runsc workers without network or credential mounts. It changes or restarts only its own disposable broker container, never the host Docker daemon or an existing site. A cleanup/recovery failure retains incident volumes rather than discarding uncertain worker state. Do not run package builds concurrently with another operator modifying the trusted SDK alias.

The regular command without the flag runs the portable database/auth/content/public HTTPS contracts in CI. It does **not** claim gVisor acceptance. A successful flagged run additionally writes `docs/axiompress-phase3-acceptance-last.json`; regular checks do not overwrite that evidence. Local reports/plans stay ignored; this tracked contract defines the durable gate.

## Accepted requirements

| Requirement                                               | Implementation and evidence                                                                                                                                                                                                                                                                                                                           |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core-owned login/session/CSRF                             | Actual HTTPS login, opaque host-only cookie, current-session resolution, logout/replay denial, Argon2id, expiry/revocation and bounded throttling tests.                                                                                                                                                                                              |
| Explicit actor/site/resource authorization                | Function-only database identity, configured-site binding, current credentials/membership under transaction locks, owner/editor/author policy and cross-site/other-author denials. HTTP role downgrade immediately forbids publication.                                                                                                                |
| Typed content commands and queries                        | Authenticated create/read/edit/publish/unpublish and anonymous GET/HEAD of only the published revision. Neither accepts arbitrary SQL, callbacks or actor claims.                                                                                                                                                                                     |
| Transactional revision/publication state                  | Revision/version expectations, independent competing PDO clients, idempotent retries and reauthorization, atomic pointers/revisions/receipts/outbox. Injected final-write failure produces HTTP 503 without partial publication; same-key retry succeeds after recovery.                                                                              |
| Routing and escaped rendering                             | Bounded canonical routes, safe JSON/no-store/nosniff/CSP, text treated as text, fixed numeric component renderer for validated plugin output. No inherited bootstrap, executable blocks/shortcodes or arbitrary markup callbacks.                                                                                                                     |
| Create and publish an article through the native pipeline | Real HTTPS client logs in, creates/edits/publishes, reads anonymously, edits a new private draft and withdraws publication against PostgreSQL.                                                                                                                                                                                                        |
| Connect an isolated plugin                                | Newly published native article gets a reading-summary component from an actual runsc worker through the peer-authenticated socket broker and operator-approved package/grant.                                                                                                                                                                         |
| Denials/conflicts/failures end to end                     | CSRF/Origin, anonymous private access, cross-site, role downgrade, stale version, invalid backend/credential, database final-write failure, revoked plugin grant and broker outage/restart checks. Real SQL concurrency and revocation-while-waiting tests cover commit ordering.                                                                     |
| Private content across rendering/cache boundaries         | Authenticated cookies never widen public reads. Plugin sees the published snapshot, not a newer private draft. Republish during an invocation returns 409; withdrawal during an invocation returns 404. Optional plugin failure cannot return withdrawn/superseded content. Fresh database snapshots/no-store are retained after success and failure. |

The current acceptance suite includes 51 migration/schema, 72 authentication, 56 content-command, 43 public-reader and 46 flagged HTTPS checks. Independent PHP lint, protocol/broker contracts, native-core and service-contract regressions remain required. Counts describe this milestone, not a substitute for exercising its requirements.

## Boundaries retained after completion

Initial operation is one site per configured native core identity; cross-site denial tests are defense, not a full multisite product. The inherited MySQL comparison endpoint remains the default unless deployment explicitly selects PostgreSQL; this is not importing or cutover. The PHP built-in multiworker server is used only by the concurrency fixture, not a supported production server.

Phase 4 owns durable outbox delivery/jobs, media, themes/blocks/editor/administration, settings and operational audit expansion. Phase 5 owns versioned offline import and cutover. Production FPM/TLS/admission/load, independent security review, host-specific containment acceptance, credential recovery, durable audit, disaster recovery and signed releases remain unfulfilled deployment/release gates. Local runsc evidence does not certify another host or eliminate plugin/core vulnerabilities.
