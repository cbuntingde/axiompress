# Experimental Linux worker supervisor

This is trusted operator infrastructure, not a web endpoint or a WordPress plugin. It launches a fixed read-only PHP worker image and routes content.read messages through the existing broker. The runtime stress harness uses fixture callbacks; the separate live runner now connects the real core adapter to the isolated worker through a private core bridge.

## Run the checks

From repository root with Docker Desktop/Linux running:

```powershell
& tools/axiompress/supervisor/run-checks.ps1
```

The runner builds worker and supervisor targets, resolves immutable image IDs and runs a uniquely named singleton test container. Results are written under ignored docs/axiompress-supervisor-runtime-last.jsonl. Base PHP is pinned by digest; build-time Alpine dependencies are not a complete reproducible release lock. Actual image IDs are recorded in the results/runtime inventory.

The supervisor test container deliberately receives the Docker socket and therefore has host-level runtime authority. It is separate from application containers. Never mount that socket or supervisor credentials into PHP/web/plugin workers. Only trusted operators run this command. The fixed test name prevents two harnesses from reconciling each other's workers; do not run another supervisor deployment using the prototype label namespace concurrently.

## Fixed runtime profile

Workers have no bind mounts, core files, broker socket, deployment credentials or network. Configuration is non-root uid/gid 10001, read-only root, all capabilities dropped, no-new-privileges, default seccomp, explicit runc, IPC none, 128 MiB memory with equal memory+swap ceiling, 0.5 CPU, 16 PIDs, 64 file descriptors and 64 MiB non-executable tmpfs. Docker log persistence is disabled to avoid a second unbounded worker-output sink. Inspect verifies critical host settings before start. Worker probes also check UID, effective capabilities, no-new-privileges and seccomp in /proc/self/status.

Only an immutable local image ID and a fixed fixture mode are accepted from trusted construction. Worker JSON cannot choose images, runtime flags, mounts, environment or commands. The image packages immutable source; no runtime install hook or Composer resolution occurs. The live runner now selects an approved image through persistent installation policy; see ../policy/README.md. Direct image construction remains available to the trusted hostile-test harness.

## Framing, deadlines and cleanup

Linux nonblocking pipes and stream_select handle partial writes and incremental framed input. Only one broker request may be outstanding; result kind/dispatch ID and process exit are checked before accepting completion. Completion does not authorize arbitrary trailing bytes or a still-running worker. Worker stdout/stderr limits are 1 MiB/64 KiB; frame bodies remain capped at 256 KiB.

Five-second monotonic checks and a separate pcntl alarm apply to an invocation, including startup and PHP adapter execution. The supervisor requires exclusive SIGALRM ownership and restores signal/error handlers after use. The tested blocking PHP adapter sleep is interrupted at deadline. Trusted adapters must not disable/swallow alarms; native operations may have their own interruptibility limitations and must use explicit I/O timeouts. This is not proof of bounded arbitrary native-extension execution.

Cleanup first closes/terminates the Docker attachment, then inspects state and force-removes the whole container, including descendants. Closing the attachment first avoids daemon removal waiting on a flooded client pipe. Cleanup commands have independent bounded deadlines. Failures are surfaced, not counted as successful rejection. Authority is always invalidated. A failed create command is followed by an exact-name/label lookup because daemon-side creation may have occurred.

Operator-only recover.php uses exclusive admission maintenance ownership before reconcile() removes containers in the fixed prototype worker label namespace. Raw reconcile() remains available only to exclusive stress harnesses. If the daemon is unreachable, or completes a create after the lookup, immediate cleanup cannot be guaranteed: fail closed, restore runtime access and establish daemon quiescence before recovery. Production lifecycle supervision and delayed-create reconciliation remain deployment gates. No code can promise daemon cleanup while the daemon is unavailable.

## Fixtures and assurance limits

The useful fixture counts Unicode letter/number word groups (with internal apostrophes) and estimates reading seconds at 200 words/minute. Empty text yields zero. Output is a constrained numeric component, not HTML. The worker is untrusted: a malicious plugin can lie about its calculations; isolation limits authority rather than proving output semantics.

Hostile modes exercise malformed/truncated frames, forged handles, trailing output, stdout/stderr floods, process crash, OS memory exhaustion with PHP memory_limit disabled, infinite CPU work and shell fork pressure. A blocked-adapter case verifies the alarm separately from cooperative worker behavior. Each case checks the expected rejection and an empty owned-container inventory before the next case. OOMKilled must be true for the memory fixture. Environment/filesystem/network probes run known test code and are checked alongside host runtime configuration.

This is restricted-container evidence on the local WSL2 Linux kernel. Ordinary runc containers share that kernel; no gVisor or microVM assurance is claimed. The suite does not establish production throughput, concurrent queue behavior, arbitrary package safety, network-broker policy, live content integration or a signed release system. Existing broker/protocol suites remain available through composer test:broker and composer test:protocol.

## Combined real-core check

```powershell
& tools/axiompress/supervisor/run-live-checks.ps1 -PublicPostId 9 -AdminUserId 1 -SiteId 1
```

This operator-only local test requires the existing Compose CLI/database services. It inspects the CLI service image/network, then creates separate non-root read-only core-bridge containers with the repository mounted read-only and database-network access. The core bridge never gets the Docker socket. The supervisor gets the runtime socket and no database configuration mount. The untrusted worker gets neither. The runtime socket still makes the supervisor trusted host-level infrastructure, not a least-privileged public application service.

CoreBridgeClient verifies the immutable core image, read-only mounts, entrypoint and exact site/post/actor command scope before attaching. Its private handshake handle is distinct from the worker's invocation. There is no core HTTP listener or plugin-supplied identity field. Requests are bounded to 32 per bridge; the client validates response IDs/content IDs, bounds diagnostics and uses 15-second startup / two-second request deadlines. Worker deadlines remain five seconds. Native/daemon outage recovery limitations above still apply.

The bridge uses normal inherited core bootstrap and its hooks. It refreshes runtime content/user/role caches before policy checks to avoid retaining stale request-local snapshots across RPCs. It is a transitional integration adapter, not a side-effect-free database microservice or removal of privileged legacy extensions. It does not promise transactional consistency for arbitrary concurrent writes.

Six cases cover public admin/anonymous reads, private admin read, anonymous private denial, nonexistent-actor denial and a same-bridge private -> public -> private visibility transition. Denial must be observed by the actual broker, not inferred from an arbitrary worker crash. Successful numeric summaries are compared with authorized core text. The last case uses trusted CLI control to change only a freshly created, random-slug synthetic fixture and restores it to private in finally; the worker receives no mutation capability. It is not a production content-management endpoint.

The runner leaves its synthetic private post in the local database, prints its ID, and removes temporary core/worker containers. Exploratory reruns can leave additional private fixtures. Results live in ignored docs/axiompress-live-worker-last.jsonl. No data is imported from a production site. Public API authentication, authenticated package distribution, stronger isolation and production lifecycle supervision remain separate gates.

## Shared admission and crash recovery

Policy-backed invocations require a shared /admission volume on one Linux host, backed by a local filesystem with flock semantics. All production-path supervisor processes and the recovery operator must use the same volume and worker namespace. The live runner creates axiompress-admission-prototype. Workers and core bridges receive neither this volume nor the Docker socket. Storage is trusted and operator-owned; symlink checks do not make a concurrently hostile host filesystem safe.

AdmissionGate reserves four global worker slots and at most one per site or installation. Overload returns admission_busy immediately; there is no waiting queue. Short nonblocking allocation locks serialize reservation access. A shared maintenance lock lasts through worker removal. Descriptors are close-on-exec so runtime clients cannot inherit ownership. Normal cleanup releases capacity; a failed or uncertain create/removal keeps the reservation. If release contends with the allocator it also fails closed and requires recovery. Reservations are bounded to four files; lock files must never be deleted or replaced while processes run.

Process death releases the kernel lock but deliberately leaves its reservation. recover.php obtains exclusive maintenance ownership, refuses to interrupt live owners, removes the prototype worker namespace and only then clears stale reservations. A cleanup failure retains reservations. This is an external operator entrypoint, not an automatically scheduled recovery daemon. It does not recover core-bridge containers, coordinate multiple hosts, guarantee fairness, survive hostile storage changes, or prove power-loss/delayed-daemon-create safety. Stop admission and establish runtime quiescence before outage recovery or volume replacement. Never expire reservations solely by wall-clock age.

Run tools/axiompress/supervisor/run-admission-checks.ps1 with the prototype namespace idle. It tests independent processes, four global holders, site/install contention, SIGKILL with a real running orphan, failed cleanup, recovery and corrupt-state denial. Do not run stress/live/recovery harnesses concurrently: their direct-constructor fixtures share the singleton prototype worker namespace.

An operator can run recovery in a separate container without policy or database credentials:

```powershell
docker run --rm --network=none --read-only --tmpfs=/tmp:rw,size=32m --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock --mount type=volume,source=axiompress-admission-prototype,target=/admission --entrypoint php axiompress-supervisor:prototype /app/supervisor/recover.php
```

Pin the supervisor image by immutable ID in deployment. The tag above is a local development convenience.
