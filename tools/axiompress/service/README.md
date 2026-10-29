# Isolated extension service

The [Phase 3 acceptance gate](../database/PHASE3_ACCEPTANCE.md) connects this service to HTTPS login/content commands and PostgreSQL public reads. It exercises grant revocation, broker outage/restart and republishing/withdrawal during actual gVisor invocations. The core still sends only already-public snapshots; sessions, private drafts, write authority and database credentials never enter the broker or workers.

Phase 2 isolated-extension prototype. Native Linux gVisor service, resource and separated HTTP checks have been exercised on a dedicated Ubuntu WSL2 engine. This is development acceptance evidence, not production certification. Docker Desktop checks use an explicit development-only runc mode.

## Authority and data flow

The native anonymous core reads its SELECT-only public projection, then optionally connects to `/channel/broker.sock`. Configure `AXIOMPRESS_BROKER_SOCKET=/channel/broker.sock` and `AXIOMPRESS_READING_INSTALLATION` with an operator-approved installation ID. HEAD requests do not invoke extensions. No HTTP query, header, cookie or request body selects an actor, installation, runtime, image or capability.

`server.py` authenticates Linux `SO_PEERCRED` against the dedicated core UID (10001 by default), not JSON identity claims. Core mounts the channel read-only; its root-owned directory is mode 0750, group 10001, and its root-owned socket is mode 0660, group 10001. Core validates those ownership/permission constraints before connecting. Root owns the service socket in this initial deployment model. No other workload may share the core UID and channel mount. Rootful Linux Docker without UID remapping is the supported initial topology.

One length-prefixed JSON object followed by a write half-close is accepted per connection. The request is at most 200,000 bytes with a two-second total read deadline. Its exact fields are `api`, `installation`, `site` and `content`; API is `axiompress.core-broker/0.1`. Content has only canonical `content_id`, a 64-character lowercase hexadecimal `revision`, plain `title` and `text`. Duplicate/unknown fields and actor assertions are rejected. Core sends already-public content only. This snapshot contract cannot carry private reads, sessions, writes, jobs or external side effects.

The fixed PHP executor checks the current installation/site/image grant and binds a fresh short-lived worker handle to one anonymous actor, site, resource and attached worker channel. Every worker broker operation rechecks scope, generation, expiry, sequence/quota and resource authority. No database connection, core configuration or core secret enters this service or a worker. Core re-reads the public record after success **and failure**: withdrawal returns 404, revision change returns 409, and neither returns old content or decoration. Valid results pass through the core's fixed numeric component renderer. An optional extension failure leaves a public 200 response with a bounded diagnostic reference and no extension markup.

## Runtime and lifecycle

The service defaults to `runsc`, requires it to be registered with the daemon and requires cgroups v2. It never falls back. `AXIOM_SERVICE_RUNTIME=runc` additionally requires `AXIOM_ALLOW_DEV_RUNC=1` and is development evidence only. No runtime settings come from a request or package.

Workers use the existing fixed profile: no network/mounts/core credentials, read-only root, UID 10001, no capabilities, no-new-privileges, bounded tmpfs, 128 MiB memory/no swap, half a CPU and 64 file descriptors. Runc has a 16-task host cgroup limit. GVisor has a 64-task host limit for its userspace kernel/gofer plus a separate hard nproc limit of 16 guest processes. Native-host pressure tests reached 16 guest processes and denied further creation; measured host tasks remained below 64. Deployment on another host requires repeating acceptance. Worker lifetime is five seconds; the executor has a 12-second outer deadline and the core client a 15-second exchange deadline. Four connection handlers and four admitted workers are the host limits, with one admitted worker per site/installation. Excess connections are closed immediately; there is no queue or warm pool. Persistent plugin storage and HTTP capabilities are absent and denied. The only worker scratch space is per-invocation quota-limited tmpfs, never shared with another installation.

Provision `/state`, `/state/admission` and `/state/workers` as private root-owned persistent directories (0700), and `/channel` as above. Put one operator-generated random 32-character lowercase hexadecimal pool ID, with no newline, in `/state/pool`; set `AXIOM_SERVICE_POOL` to the same ID. All service replicas for that pool must share this state and channel; only one owner can run. The owner lock is inherited by invocation handlers so restarting the parent cannot reconcile live work. Other pools and the earlier operator prototype use separate runtime inventory labels.

Before admitting work, and after all handlers finish, recovery removes only this pool's workers and then clears stale admission reservations. Confirmed creation is journaled and survives supervisor interruption. A partial/unknown `creating` intent blocks admission and startup: Docker may complete a timed-out create after its client exits. Never clear it based only on a momentarily empty inventory or elapsed time. Stop this service, establish daemon quiescence through an operator-controlled engine restart in a maintenance window, inspect/remove this pool's containers, preserve the intent as incident evidence, then remove that specific resolved intent and restart the service. This deliberately requires operator recovery for ambiguous daemon operations. Ordinary confirmed orphans recover automatically. Use a service manager/container restart policy for process failure; it cannot bypass this recovery gate.

The supervisor has Docker authority, which is host-administrator-equivalent. It belongs on a dedicated trusted host, never in the public core image. Run it as UID 0, GID 10001, with all capabilities dropped and no-new-privileges. Mount only its policy directory, state, channel and daemon socket; its filesystem must be read-only otherwise, with bounded tmpfs and no network. Mount `/policy` read-only for the service; a separate operator identity provisions/updates policy. SQLite uses DELETE rollback journaling so read-only runtime mounts need no writable WAL/SHM sidecars and still observe live grant changes. See the policy guide for the offline conversion of an older WAL policy database. Core receives only its own public DB secret and a read-only channel mount. Workers receive neither. Deployment owns immutable code; application identities cannot replace it.

## Registering gVisor on a Linux host

Follow the [official installation instructions](https://gvisor.dev/docs/user_guide/install/) for the pinned release selected in the architecture (`release-20261026.0`; the verified artifact checksum is also recorded in `../experiments/runtime.ps1`). Preserve the full release bundle, including companion files. Register the verified installed executable with `sudo runsc install`, inspect the resulting Docker runtime configuration, and restart Docker during a maintenance window with `sudo systemctl restart docker`. Confirm:

```sh
docker info --format '{{json .Runtimes}}'
docker run --rm --runtime=runsc hello-world
```

See the [official Docker quick start](https://gvisor.dev/docs/user_guide/quick_start/docker/). This configures the Linux daemon on that host. Installing in Windows or in an unrelated WSL distribution does not register a runtime in Docker Desktop's managed daemon. Do not use the earlier nested `--ignore-cgroups` compatibility experiment as this installation or as resource acceptance evidence.

## Verification

From the repository root in PowerShell 7, run `./tools/axiompress/service/run-checks.ps1` against a Linux Docker host with runsc. On this Windows development host, use `./tools/axiompress/service/run-checks.ps1 -DevelopmentRunc`. The harness builds fixed trusted SDK/worker/supervisor images, uses disposable policy/state/channel filesystems and a random pool ID, runs useful/hostile packages through actual sockets and Docker workers, and writes its result under ignored `docs/`. Do not run package-building checks concurrently with another operator changing the trusted SDK alias. The test policy and hostile installation are never installed into the application's real policy.

`php tests/axiompress/service-contract-test.php` runs portable input rejection checks and is wired into CI. Run `composer lint:axiompress`, `composer test:protocol`, `composer test:broker`, the native core suite and the earlier hostile runtime/admission suites after supervisor changes.

The service test covers core UID authentication, exact framing, duplicate/scope rejection, empty/Unicode/maximum content, renderer/client rejection, publication withdrawal/revision changes, optional failure, hostile crash/hang/malformed/forged/cross-resource/quota behavior, live grant revocation, overload/slow clients, cold invocation measurement, exclusive ownership, orphan recovery and ambiguous-create refusal. Initial local performance budget: 20 cold invocations, p95 below 1500 ms; overload rejection below one second. These are prototype acceptance budgets, not production SLOs. The native-host resource suite separately verifies memory OOM, CPU throttling, guest process and host task bounds, tmpfs size/noexec, network denial and scratch isolation. Service tests exercise 1/4/16/32 installations with immediate overload rejection and a four-worker ceiling. Every worker is fresh: warm-worker reuse is deliberately disabled; first-call and repeated cold-worker timings must not be presented as pooled-worker latency. Production host validation, independent security review, complete administration/editor integration, signed releases and operational rollout remain later gates.

## Local Windows development host and additional proofs

This workspace has a dedicated Ubuntu 24.04.5 WSL2 distribution named `AxiomPress-Linux`, stored outside the checkout. Its Docker Engine is independent of Docker Desktop. The checksum-verified gVisor release bundle is installed under `/opt/axiompress/gvisor/release-20261026.0`; that engine registers `runsc` with `--platform=systrap` and uses systemd cgroups v2. Docker Desktop's configuration and the default Docker context are unchanged. Always select this distro explicitly with `wsl -d AxiomPress-Linux -u root`.

On the Linux daemon host, from the checkout root:

```sh
bash tools/axiompress/service/run-checks.sh
python3 tools/axiompress/service/runtime-proof.py
python3 tools/axiompress/service/http-test.py --disposable-database
```

The HTTP proof creates and removes a dedicated database/network, separate read-only native core and broker containers, private credentials, and operator-approved test packages. It verifies publication privacy, mount/identity separation, rollback with generation advancement, a real in-flight broker process kill, automatic restart and persistent orphan cleanup. Its temporary database never replaces the development WordPress database. The native build context allowlist excludes plans, caches and credentials.

`lifecycle-proof.py` is restricted to root on the dedicated `AxiomPress-Linux` distribution and requires an otherwise empty engine. It temporarily freezes that engine's daemon, verifies retained uncertain-create intent and admission capacity, tests restart refusal, then performs an actual engine restart and operator-fenced recovery. Do not run daemon fault injection against Docker Desktop or an engine with other workloads. Incident metadata and reports remain under ignored `docs/`.

Package builds require Buildx in the trusted operator image. Buildx client state is a fresh private temporary directory outside the package snapshot and is removed without following symlinks. Test policy provisioning requires explicit `AXIOM_SERVICE_TEST=1` and an empty policy directory; it cannot silently add hostile fixtures to an existing policy store.
