# Running workers without Docker

AxiomPress can launch approved PHP plugins directly with gVisor `runsc`. The trusted extension service still owns the Unix socket, installation permissions, resource limits and recovery. Docker is not called by this backend. The existing Docker backend remains available for development and comparison.

The [Linux service installation guide](../linux/README.md) packages reviewed local code and installs the extension service under systemd without automatically starting or enabling it.

This is an implementation under local Linux testing, not a finished production installer. A production host still requires acceptance testing, independent security review, approved runtime distribution, signed releases and operating procedures. It does not make ordinary shared PHP hosting suitable for untrusted extensions.

## Host requirements

- A dedicated Linux host with cgroups v2, delegated CPU, memory and process controllers, and whole-group termination through `cgroup.kill`.
- A verified gVisor release, including its `gvisor-bin` companion directory. The executable is pinned by SHA-256; all runtime files must belong to root and be protected from application writes.
- 64-bit PHP 8.5 with `pcntl` and PDO SQLite for the extension service; Python 3.12 or newer. Core and database operations require their own PHP extensions and restricted database identities.
- An operator-approved PHP filesystem containing the fixed SDK files and PHP executable at `/usr/local/bin/php`. Image processing requires an approved PHP/GD filesystem. No application or plugin can supply runtime flags or execute a package installation script.

Install the trusted code on the Linux filesystem with root ownership and no group/other write access. Windows-mounted checkout permissions are unsuitable for the deployed service. Use absolute paths without symbolic links for host executables and configuration. Do not change an approved runtime filesystem while a service uses it; publish a new digest instead.

See the official [OCI instructions](https://gvisor.dev/docs/user_guide/quick_start/oci/) and [production guidance](https://gvisor.dev/docs/user_guide/production/). A Docker daemon registration is unnecessary for the direct backend.

## Configuration and authority

Create a private root-owned JSON configuration, mode `0600`, with exactly these fields:

```json
{
  "runsc": "/opt/axiompress/gvisor/release-20261026.0/runsc",
  "runsc_sha256": "REPLACE_WITH_VERIFIED_EXECUTABLE_SHA256",
  "store": "/var/lib/axiompress/extensions/store",
  "state": "/var/lib/axiompress/extensions/runtime",
  "cgroup_parent": "/sys/fs/cgroup/axiompress-extensions"
}
```

The store and state directories must be private root-owned directories, mode `0700`. The cgroup parent must be a dedicated, empty delegated subtree, with `cpu`, `memory` and `pids` enabled for its children. Its creation and delegation belong to the host operator or service manager. The runtime refuses missing controls rather than reducing isolation.

Validate the configuration before starting the service:

```sh
/usr/bin/python3 /opt/axiompress/tools/axiompress/runtime/direct.py \
  --config /etc/axiompress/extensions.json check
```

That command validates configuration and ownership; it is not a containment test.

Each invocation verifies the approved filesystem inventory, creates a persistent intent, establishes a limited cgroup, and then enters that cgroup before executing `runsc`. The worker receives a read-only filesystem, guest UID/GID 10001, no capabilities, no new privileges, no network, explicit OCI syscall restrictions, 128 MiB host memory with no swap, half a CPU, 64 host tasks, 16 guest processes and 64 file descriptors. Plugin scratch space is 64 MiB; image scratch space is 8 MiB. OCI seccomp loading is explicitly enabled. No database credentials, host environment, core filesystem or runtime socket enter the guest.

Cleanup kills the entire worker cgroup, confirms it is empty, removes the gVisor instance and verifies its absence before clearing intent and capacity. Interrupted workers retain intent for recovery. Recovery requires exclusive service ownership; never run it alongside active work or erase uncertain state to restart a service. Allocate a separate runtime configuration, state store and cgroup subtree to image processing so extension-service recovery cannot terminate an image batch.

The controller currently runs as root and is part of the trusted host software. It must be separate from public PHP-FPM and inaccessible to application identities. Do not reuse the Docker service's capability-dropping settings for direct `runsc`: creating the Linux isolation boundary needs host privileges. Service-manager restrictions require host-specific testing.

## Plugin approval

Prepare an approved local SDK filesystem and record its inventory digest during trusted release preparation. Run `package.py --help` for the required SDK, digest, package and store arguments. Package validation reads and captures files; it never includes or executes plugin PHP. An immutable artifact identifies the SDK, package metadata and complete filesystem inventory.

Register an artifact and create a disabled installation:

```sh
/usr/bin/php8.5 /opt/axiompress/tools/axiompress/runtime/register.php \
  /etc/axiompress/extensions.json sha256:ARTIFACT PACKAGE_DIRECTORY \
  /var/lib/axiompress/policy/installations.sqlite INSTALLATION site:1 create
```

Use the actual approved digest and package directory. The [installation policy](../policy/README.md) still applies. Set `AXIOM_POLICY_FILE` when using `policy/manage.php` outside its default container layout. Approval requires the current generation and artifact digest; creating or registering a package does not grant permission to run it.

## Extension service

Provision the existing service's private state directories, persistent pool identity and root-owned channel with the dedicated core group. Keep the package policy read-only to the service deployment where supported by the host. Run `service/server.py` with these operator-owned environment settings:

```text
AXIOM_WORKER_BACKEND=direct
AXIOM_SERVICE_RUNTIME=runsc
AXIOM_DIRECT_CONFIG=/etc/axiompress/extensions.json
AXIOM_PHP_BINARY=/usr/bin/php8.5
AXIOM_POLICY_FILE=/var/lib/axiompress/policy/installations.sqlite
AXIOM_STATE_DIRECTORY=/var/lib/axiompress/extensions/service
AXIOM_CHANNEL_DIRECTORY=/run/axiompress/extensions
AXIOM_SERVICE_POOL=REPLACE_WITH_PERSISTENT_32_CHARACTER_HEXADECIMAL_POOL_ID
AXIOM_CORE_UID=10001
```

The initial process must have UID 0 and the dedicated core GID. The socket authenticates the core's Linux UID. Public requests and package contents cannot select these settings. Four handlers and four admitted workers remain the service limit. The five-second worker deadline remains; the executor has a 20-second outer limit, and recovery has a separate bounded timeout. The core client can stop waiting earlier and treat an optional extension as unavailable.

The [service guide](../service/README.md) describes the public-snapshot contract, channel permissions and permission checks. Its Docker startup, daemon recovery and container capability instructions apply only to the Docker backend. The direct backend has no delayed daemon creation: all worker descendants enter the prelimited cgroup before runtime execution. Startup recovery confirms termination before reopening admission.

## Image processing

`image.py` binds the fixed AxiomPress decoder to an explicitly approved PHP/GD filesystem. It never accepts a publisher-selected decoder. Configure `media/run.py` with `backend: "direct"`, `runtime_config`, `processor_image` containing the approved artifact digest, `php` containing the absolute host PHP executable, and `core_bootstrap` containing the trusted core bootstrap path. Retain the existing `host`, `port`, `database`, `user`, `site`, `secret_file`, `ca_file` and private `state_directory` settings. Docker-only `controller_image` and `network` settings are unnecessary.

The host controller uses the restricted jobs database identity and verified TLS; only bounded image bytes enter the gVisor decoder. PHP PostgreSQL support is required on the host. The controller must use a separate runtime configuration and run as a single operator-owned batch service. If interrupted, stop its owner, reconcile the dedicated runtime, confirm absence of workers, preserve the batch intent as incident evidence, and resolve that intent before starting another batch. Never clear it while cleanup is uncertain.

## Verification

On a dedicated Linux test host with approved SDK files and PHP installed:

```sh
sudo /usr/bin/python3 tools/axiompress/runtime/run-checks.py \
  --runsc /opt/axiompress/gvisor/release-20261026.0/runsc \
  --sdk-root /opt/axiompress/sdk/php \
  --media-sdk-root /opt/axiompress/sdk/php-gd \
  --php /usr/bin/php8.5
```

`--prepare-with-docker` is an alternative for preparing disposable SDK and PHP files from the existing local test images. It does not use Docker to execute plugins or image decoders. Plugin and service execution runs with Docker unavailable on `PATH`; image execution rejects any Docker call.

Tests exercise actual PHP/gVisor execution, initial approval, revocation, filesystem/network denial, memory exhaustion, malformed output, timeouts, Unix peer identity, forged authority, service restart, interrupted-worker recovery and PNG/JPEG/WebP decoding. The PostgreSQL-backed application and Docker backend also retain their separate tests. A successful local run does not establish production deployment acceptance or complete WordPress functionality.

To test the host PHP database controller together with HTTP uploads and direct gVisor decoding:

```sh
sudo /usr/bin/python3 tools/axiompress/database/run-checks.py \
  --direct-media-runsc /opt/axiompress/gvisor/release-20261026.0/runsc \
  --browser
```

This creates a disposable PostgreSQL database and prepares temporary PHP runtime files using Docker. The database port is exposed only on loopback. Image-job claims and completion execute in host PHP with verified TLS and the restricted jobs account; image decoding uses direct gVisor. Neither the controller nor decoder calls Docker. The same HTTP tests cover quarantine, regeneration, private access, publication, withdrawal and invalid input. Additional checks reject the wrong site, password, certificate authority and account role, and test a controller killed after claiming a job. A replacement must respect the existing lease; the expired lease loses completion authority and the original image remains available for a subsequent attempt. Test-only clock adjustments affect only the disposable database.

The default database command continues to use the existing Docker controller. The direct test does not change an application's database, runtime configuration or Docker daemon settings. Production installation, service-manager restrictions, approved runtime distribution and acceptance on a supported VPS remain required.
