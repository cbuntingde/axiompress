# AxiomPress

AxiomPress is an independent, clean-break CMS fork of WordPress. Its goal is a modern PHP core with explicit services, core-owned authorization and isolated extensions instead of shared plugin execution authority.

Canonical project domain: [axiompress.dev](https://axiompress.dev).

## Project status

**Early development.** Phases 1 through 3 meet their agreed local development gates: architecture, isolated extensions and the minimal authenticated CMS vertical slice. The full CMS rewrite and production isolation/deployment are not complete.

The current checkout includes:

-   A native PHP 8.5 read-only content API that loads no WordPress plugins, themes, hooks or bootstrap.
-   A bounded extension protocol, scoped content broker and restricted Linux worker supervisor.
-   A Linux peer-authenticated core/broker service with gVisor integration, bounded admission and crash recovery.
-   An initial PostgreSQL 18 domain schema and deployment-only migration runner with restricted reader roles.
-   Native PostgreSQL-backed login, opaque sessions, CSRF protection and function-only site-scoped authentication roles.
-   Authenticated PostgreSQL article commands with ownership policy, revision conflicts, idempotent retries and a transactional publication outbox.
-   Anonymous PostgreSQL GET/HEAD reads through a separate restricted identity, following published revisions and current visibility.
-   Operator-managed package/image approval, persistent installation grants, revocation, shared admission and recovery checks.
-   Core protections that prevent official WordPress packages from replacing AxiomPress.
-   Focused tests and repeatable database, runtime, editor and performance experiments.

PostgreSQL 18 is selected for the future domain model. Public reads can use native PostgreSQL articles through an explicit backend setting; the inherited MySQL SELECT-only projection remains the default comparison source. gVisor systrap is integrated with the extension service and exercised on a dedicated WSL2 Linux engine. Runc remains development-only; production deployment still requires independent host and security acceptance. Gutenberg block grammar and reviewed UI packages will be reused selectively.

Core authentication, article commands, anonymous reads and an isolated plugin pass the [Phase 3 acceptance gate](tools/axiompress/database/PHASE3_ACCEPTANCE.md), including database failures and publication changes during plugin execution. Full administration/editor integration, data migration, plugin command authority, outbox delivery and signed release infrastructure remain later-phase work; production admission and deployment acceptance remain required. Legacy executable plugin/theme compatibility is outside the target architecture.

## Architecture and policies

-   [Architecture, authority and decisions](ARCHITECTURE.md)
-   [Release and deployment policy](AXIOMPRESS_RELEASE_POLICY.md)
-   [Fork provenance and original baseline](FORK_PROVENANCE.md)
-   [Contributing to AxiomPress](CONTRIBUTING.md) and [repository guidance](AGENTS.md)
-   [Native core contract](src/axiompress/README.md) and [public PostgreSQL configuration](src/axiompress/PublicReads.md)
-   [Worker supervision and containment limits](tools/axiompress/supervisor/README.md)
-   [Extension service, Linux host setup and verification](tools/axiompress/service/README.md)
-   [Package and installation policy](tools/axiompress/policy/README.md)
-   [Domain contract designs](tools/axiompress/contracts/README.md)
-   [PostgreSQL schema and migration operations](tools/axiompress/database/README.md)

Official WordPress is a source of selectively reviewed changes, not a branch to merge routinely. Security fixes must be assessed against both retained code and equivalent rewritten behavior. Official core packages must never be installed over this fork. Production code and configuration belong to a separate deployment identity and must be read-only to application identities.

## Local development

Requirements:

-   Docker with a running Linux engine; the operator runners use PowerShell 7 (`pwsh`).
-   Node.js 24.18 or newer and npm 11.16 or newer, as declared in `package.json`.
-   Composer 2 and 64-bit PHP 8.5 for independent core/tooling checks. Runtime checks use Linux containers.

On a new clone, copy `.env.example` to `.env` and review its settings. Set `LOCAL_PHP=8.5-fpm` for the current independent-core baseline. Preserve an existing `.env` and database.

From the repository root:

```sh
npm ci
npm run build:dev
npm run env:start
```

On Windows PowerShell, use `npm.cmd` if the local execution policy blocks the npm PowerShell shim.

For a **fresh disposable development database only**, initialize the inherited comparison site with:

```sh
npm run env:install
```

That command resets the development database and rewrites local configuration. Do not rerun it against an existing site you want to keep. The inherited comparison site is available at <http://localhost:8889>; its fixture credentials are documented in the archived upstream guide and are for local development only.

The normal Compose environment is writable development infrastructure. It is not the production deployment profile.

### Native core preview

With the inherited local database running, select one published, unprotected post and one private post. The native runner provisions a separate SELECT-only credential and does not migrate content:

```powershell
$publicPostId = [int](Read-Host 'Published post ID')
$privatePostId = [int](Read-Host 'Private post ID')
pwsh -File tools/axiompress/native/run-checks.ps1 -PublicPostId $publicPostId -PrivatePostId $privatePostId
```

It retains a loopback-only development service on port **8890**. Its route is `GET /v1/sites/1/content/{id}`. Private content remains unavailable even with forged cookies or actor headers. The PHP built-in server is a development adapter, not a production HTTP server.

The local provisioning runner currently assumes `wordpress_develop`, `wp_posts` and site 1. Read the [native core instructions](src/axiompress/README.md) before using it with a different environment. Generated native credentials/configuration stay under ignored `.cache/axiompress-native/`.

## Verification

Fast independent PHP checks:

```sh
composer lint:axiompress
composer test:protocol
composer test:broker
```

Run the worker suites sequentially against an idle prototype namespace:

```powershell
pwsh -File tools/axiompress/supervisor/run-checks.ps1
pwsh -File tools/axiompress/supervisor/run-live-checks.ps1
pwsh -File tools/axiompress/supervisor/run-admission-checks.ps1
```

The live runner requires suitable published content and an administrator fixture; its parameters let you supply their IDs. These are trusted operator tools: the supervisor receives Docker authority, which must never be given to the web application or plugin workers. See the [supervisor documentation](tools/axiompress/supervisor/README.md) and [experiment instructions](tools/axiompress/experiments/README.md) for prerequisites, workload scope and assurance limits.

Independent PHP uses PSR-12; retained WordPress modules retain their existing coding standards. The focused AxiomPress workflow checks standalone contracts and native unit behavior. Runtime experiments, full legacy regressions and production review are separate checks.

## Contributing and upstream attribution

Use this fork's [issues](https://github.com/cbuntingde/axiompress/issues) and pull requests for AxiomPress work. Follow [AGENTS.md](AGENTS.md), preserve unrelated changes and provide verification appropriate to the affected boundary.

The original root README is preserved unchanged as [README-WORDPRESS.md](README-WORDPRESS.md), and the original contributor guide as [CONTRIBUTING-WORDPRESS.md](CONTRIBUTING-WORDPRESS.md). These document upstream WordPress workflows; their synchronization and submission instructions do not override AxiomPress policy.

`docs/` contains local plans and working evidence and is intentionally ignored. Required contributor/runtime behavior belongs in version-controlled guidance and source, not solely in those local plans. Never commit local configuration, credentials, generated caches or database data.

AxiomPress retains WordPress attribution and the [GPL-2.0-or-later license](src/license.txt). Repository or product renaming does not remove existing copyright, license or provenance notices.
