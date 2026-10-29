# AxiomPress release and deployment policy

Product: AxiomPress. Canonical domain: https://axiompress.dev . Initial independent version: 0.1.0-dev. The inherited WordPress runtime/schema version remains internal provenance, not the fork release number. Existing WordPress copyright and license notices remain intact.

## Updates

Official core discovery, automatic selection, installation, rollback, and reinstallation are disabled in the application. Previously cached offers are ignored. Repository WP-CLI configuration rejects core download/update/check-update/verify-checksums because upstream packages and checksums do not describe AxiomPress. A separately invoked CLI can ignore repository configuration: operating-system permissions must prevent writes to deployed code.

Plugin and theme updates retain their existing behavior during this transition. They are not isolated yet. Production deployment must disable application file modifications and keep plugin/theme code read-only too. Installation by a deployment controller and execution in isolated workers are future architecture gates, not delivered capabilities.

## Deployment boundary

Deploy immutable, versioned artifacts with application code owned by a deployment identity. The PHP/web/CLI service identity must have no write permission to core, plugins, themes, configuration, or release metadata. In containers, mount code and configuration read-only, use a read-only root filesystem, and grant only separate uploads and bounded temporary storage writable mounts. Do not mount a Docker socket, deployment credentials, or signing keys into application containers. Development Compose deliberately remains writable for builds and is not a production configuration.

Set DISALLOW_FILE_MODS to true in production configuration as defense in depth. This disables plugin/theme/core application modifications but is not an operating-system security boundary. WP_AUTO_UPDATE_CORE=false can document operator intent; core enforcement here does not depend on that setting or filters.

`tools/deployment/readonly.compose.yml` is an opt-in local hardening overlay for the existing Compose environment. It makes repository mounts read-only, runs PHP/CLI without root or Linux capabilities, and provides bounded temporary storage. It intentionally provides no writable uploads mount, so uploads are unavailable in this profile. Build assets before enabling it. Do not confuse this profile with an isolated-plugin runtime or a production artifact/deployment system. The standard development stack remains writable.

The external deployment process stages a complete artifact, checks signatures and expected product/version, tests migrations against a backup, switches releases atomically where supported, verifies health, and records the result. Rollback must account for database compatibility and post-deployment writes. Do not overwrite a live tree piecemeal.

## Release verification requirements

No hosted update feed, release signing infrastructure, or deployment controller exists yet. Do not point clients at an invented endpoint on axiompress.dev. Before distribution, define a versioned manifest binding product, release version, artifact digest and size, schema requirements, and signing key ID. Require a detached signature verified against independently distributed trusted keys before extraction; reject unknown keys, invalid signatures, wrong product, oversized artifacts, and unauthorized downgrade/replay. Define key rotation/revocation, archive path/link validation, and artifact retention before implementation.

Cloudflare DNS/hosting configuration has not been changed. Domain ownership alone is not a software-update trust root. Keep private signing keys outside Cloudflare-hosted public artifacts and application infrastructure.

## Upstream security maintenance

Monitor official advisories and relevant patches. Assess retained code and equivalent rewritten behavior, selectively port fixes with regression evidence, preserve attribution, and release through AxiomPress deployment. Do not merge upstream routinely or restore its updater to obtain fixes. See AGENTS.md and FORK_PROVENANCE.md.
