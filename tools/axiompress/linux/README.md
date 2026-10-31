# Linux extension-service installation

These tools package reviewed local AxiomPress code and install a systemd unit for the direct gVisor extension service. Installation never enables or starts the service and never replaces an existing unit. This is not a complete CMS installer: PHP-FPM, PostgreSQL, complete scheduled image-processing integration, signed release distribution and production-host acceptance remain separate work.

## Requirements

- A dedicated Linux host with systemd 254 or newer and cgroups v2. The service requires delegated CPU, memory and process controls and `cgroup.kill`.
- An existing dedicated core account with equal nonzero UID and primary GID. That identity must belong only to the core application. Choose unused IDs when creating it; do not change an existing account to match an example.
- An operator-approved gVisor release with protected companion binaries; 64-bit PHP 8.5 CLI with `pcntl` and PDO SQLite.
- A private root-owned plugin artifact store and SQLite policy database, prepared using the [runtime guide](../runtime/README.md) and [installation policy](../policy/README.md).

Code, executables, configuration and all their parent directories must belong to root and be protected from application writes. Host executable paths must be absolute, contain no symbolic links and use only letters, numbers, `/`, `_`, `-` and `.`. Use the actual PHP executable rather than an alternatives link.

## Build a local release

From an operator-reviewed source checkout on Linux:

```sh
sudo python3 tools/axiompress/linux/package.py \
  --output /opt/axiompress/releases/local-001
```

The destination must be new and its parent must already exist with protected root ownership. The package includes only the source paths explicitly listed in `package-files.json`, together with licensing, fork provenance and release policy. Maintainers must review that list when adding service dependencies. It excludes the inherited WordPress runtime, local plans, credentials, development tests and dependency caches. A manifest records file contents, permissions and ownership. The command prints its SHA-256 digest; preserve that reviewed digest separately.

This local manifest detects changes to the reviewed package. It does not authenticate a remote publisher or replace signed release verification. Only run an installer whose source and provenance you already trust.

## Configure and install

Create a private root-owned configuration, mode `0600`, for example `/etc/axiompress/extensions.json`:

```json
{
  "format": "axiompress.extensions-service/1",
  "instance": "axiompress-extensions",
  "runsc": "/opt/axiompress/gvisor/release-20261026.0/runsc",
  "runsc_sha256": "REPLACE_WITH_VERIFIED_GVISOR_EXECUTABLE_SHA256",
  "php": "/usr/bin/php8.5",
  "store": "/var/lib/axiompress-artifacts/extensions",
  "policy": "/var/lib/axiompress-policy/installations.sqlite",
  "core_uid": 10001
}
```

The example UID is not automatically allocated. Provision the dedicated core user and matching group first. Store and policy must be private, protected root-owned paths, separate from the service's writable state.

Install from the reviewed release directory, using its recorded manifest digest:

```sh
sudo python3 /opt/axiompress/releases/local-001/tools/axiompress/linux/install.py \
  --release /opt/axiompress/releases/local-001 \
  --sha256 RELEASE_MANIFEST_SHA256 \
  --config /etc/axiompress/extensions.json
```

The installer checks the complete release inventory, configuration ownership, PHP version/extensions, core identity, pinned gVisor executable and systemd version, then validates the generated unit with `systemd-analyze verify`. It creates `/etc/systemd/system/axiompress-extensions.service` exclusively and reloads systemd. It refuses to overwrite an existing service. It does not download dependencies, approve packages, modify a Docker daemon or enable automatic startup.

## Start and operate

After reviewing the installed unit and configuration:

```sh
sudo systemctl start axiompress-extensions.service
sudo systemctl status axiompress-extensions.service
```

Systemd creates private state at `/var/lib/axiompress/axiompress-extensions` and a channel directory at `/run/axiompress/axiompress-extensions`. The broker socket is `broker.sock` in that channel directory. Configure the core's broker client with that absolute socket path and the approved installation ID. Verify a real permitted invocation before enabling startup:

```sh
sudo systemctl enable axiompress-extensions.service
```

The unit uses `DelegateSubgroup=controller`. Startup requires the process to be inside the matching service's controller subgroup before enabling controls in its own delegated subtree. All workers live below that service; the helper never provisions a shared host cgroup parent. See systemd's [delegation instructions](https://github.com/systemd/systemd/blob/main/docs/CGROUP_DELEGATION.md). A missing controller, invalid placement, changed runtime digest or unsupported PHP runtime stops startup.

The service has a private network namespace and temporary directories, a read-only system filesystem, explicitly writable state/channel directories and a bounded aggregate memory/task allowance. It remains privileged trusted host software because direct gVisor setup requires host privileges. Host-level `NoNewPrivileges` is not enabled: it prevented this pinned runtime from creating its filesystem helper in the tested service configuration. Guest processes still have `noNewPrivileges=true`, empty capability sets and the fixed isolation profile. These settings do not establish protection against a compromised host administrator or replace independent security review.

Installation approvals remain operator-controlled through the separate policy database. Service startup does not approve an installation. A persistent pool identity survives restart. Recovery must confirm worker termination and empty runtime inventory before stale capacity is released. `systemctl stop` applies to the whole service cgroup. Do not manually erase uncertain worker state to force startup.

Updates require a separately reviewed release and an explicit deployment procedure. The installer deliberately refuses replacement; do not bypass that refusal by overwriting a running release or deleting recovery state. Official WordPress packages must never be installed over AxiomPress.

## Schedule image processing

For an already provisioned dedicated direct media runtime, install an inactive service and timer with `media-schedule.py --instance axiompress-media --config /etc/axiompress/media.json`. The media configuration follows the runtime guide and must use `backend: "direct"`, with no Docker image or network fields. Provision three separate private root-owned directories for the batch intent state, the runtime state and the artifact store, plus a dedicated delegated cgroup parent with CPU, memory and process controllers enabled. Keep the database password file, certificate authority file, core bootstrap and PHP executable as root-owned files outside those worker directories. Database credentials reach only the host PostgreSQL controller; the gVisor decoder receives only bounded image bytes.

Installation validates required fields, directory separation, delegated controllers, credential placement, the host controller and both unit definitions. It refuses existing units and leaves the timer disabled. An operator can start scheduling with `systemctl enable --now axiompress-media.timer` after validating the runtime and database connection.

The timer runs a maximum of four jobs per batch, 15 seconds after the previous batch finishes. `--interval` accepts 5–3600 seconds. A service instance does not overlap itself. The batch controller has a 180-second start limit and a 30-second stop limit; service cgroup termination applies to the controller process. Workers live in the dedicated runtime cgroup, so an interrupted batch retains its intent file and the operator must reconcile the runtime with recovery checks and confirm no workers remain before the next claim. A replacement controller respects a live lease, and an expired lease loses completion authority. This scheduler does not automatically create the media runtime delegation and does not establish restart acceptance on a production host.

## Local installation tests

From the source checkout on the dedicated Linux test host:

```sh
sudo python3 tools/axiompress/linux/run-checks.py \
  --runsc /opt/axiompress/gvisor/release-20261026.0/runsc
```

The test uses a temporary root-owned release, service account, volatile systemd unit and private state. Docker prepares disposable PHP files only. Service and plugin execution use the installed direct gVisor backend. Tests check deterministic packaging, changed-file rejection, approval, Unix peer identity, useful execution, guest privilege/network/filesystem restrictions, persistent restart, forced process termination and refusal of missing delegated placement. The temporary service is never enabled. Failed tests retain uncertain installation files for operator inspection.

Successful WSL2 tests are local development evidence. A supported VPS still needs its own installation, containment, recovery and load acceptance, alongside signed release distribution and independent security review.
