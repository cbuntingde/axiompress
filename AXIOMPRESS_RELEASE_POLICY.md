# AxiomPress release and deployment policy

Product: AxiomPress. Canonical domain: https://axiompress.dev . Initial independent version: 0.1.0-dev. The inherited WordPress runtime/schema version remains internal provenance, not the fork release number. Existing WordPress copyright and license notices remain intact.

## Updates

Official core discovery, automatic selection, installation, rollback, and reinstallation are disabled in the application. Previously cached offers are ignored. Repository WP-CLI configuration rejects core download/update/check-update/verify-checksums because upstream packages and checksums do not describe AxiomPress. A separately invoked CLI can ignore repository configuration: operating-system permissions must prevent writes to deployed code.

The inherited WordPress plugin and theme paths retain shared-process execution during this transition. They must not be exposed as the secured extension system. The independent extension service enforces separate worker execution and operator-controlled approval; broader installation/update and release management remain incomplete. Application identities must not modify plugin/theme code or install packages.

## Deployment boundary

Production installation must not require Docker. The supported design is an ordinary Linux web server, PHP-FPM and PostgreSQL, plus a separate AxiomPress service that runs approved plugins and image processing under gVisor directly. Docker may remain available for development, building and testing. The existing Docker-based worker implementation remains in use until the direct implementation passes the same security and recovery tests; this policy is not a claim that the migration is complete.

The direct worker service owns runtime control and restricted, approved runtime files. Neither the web application nor plugin workers receive that control, deployment credentials or package-signing authority. Missing gVisor or required Linux isolation/resource controls must stop worker execution rather than select a weaker fallback. Each supported Linux/VPS configuration needs explicit installation, startup, containment, resource and recovery verification. Ordinary shared hosting without these controls is not an implied deployment target.

Deploy immutable, versioned artifacts with application code owned by a deployment identity. The PHP/web/CLI service identity must have no write permission to core, plugins, themes, configuration, or release metadata. In containers, mount code and configuration read-only, use a read-only root filesystem, and grant only separate uploads and bounded temporary storage writable mounts. Do not mount a Docker socket, deployment credentials, or signing keys into application containers. Development Compose deliberately remains writable for builds and is not a production configuration.

Set DISALLOW_FILE_MODS to true in production configuration as defense in depth. This disables plugin/theme/core application modifications but is not an operating-system security boundary. WP_AUTO_UPDATE_CORE=false can document operator intent; core enforcement here does not depend on that setting or filters.

`tools/deployment/readonly.compose.yml` is an opt-in local hardening overlay for the existing Compose environment. It makes repository mounts read-only, runs PHP/CLI without root or Linux capabilities, and provides bounded temporary storage. It intentionally provides no writable uploads mount, so uploads are unavailable in this profile. Build assets before enabling it. Do not confuse this profile with an isolated-plugin runtime or a production artifact/deployment system. The standard development stack remains writable.

The external deployment process stages a complete artifact, checks signatures and expected product/version, tests migrations against a backup, switches releases atomically where supported, verifies health, and records the result. Rollback must account for database compatibility and post-deployment writes. Do not overwrite a live tree piecemeal.

## Release verification requirements

No hosted update feed, release signing infrastructure, or deployment controller exists yet. Do not point clients at an invented endpoint on axiompress.dev. Before distribution, define a versioned manifest binding product, release version, artifact digest and size, schema requirements, and signing key ID. Require a detached signature verified against independently distributed trusted keys before extraction; reject unknown keys, invalid signatures, wrong product, oversized artifacts, and unauthorized downgrade/replay. Define key rotation/revocation, archive path/link validation, and artifact retention before implementation.

Cloudflare DNS/hosting configuration has not been changed. Domain ownership alone is not a software-update trust root. Keep private signing keys outside Cloudflare-hosted public artifacts and application infrastructure.

## Upstream security maintenance

Monitor official advisories and relevant patches. Assess retained code and equivalent rewritten behavior, selectively port fixes with regression evidence, preserve attribution, and release through AxiomPress deployment. Do not merge upstream routinely or restore its updater to obtain fixes. See AGENTS.md and FORK_PROVENANCE.md.
