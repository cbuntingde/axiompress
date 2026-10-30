# AxiomPress

AxiomPress is an independent, clean-break CMS fork of WordPress. Its goal is a modern PHP core with explicit services, core-owned authorization and isolated extensions, while preserving familiar WordPress publishing workflows and administration conventions. The clean break concerns runtime authority and internal contracts; it does not authorize incidental feature loss.

Canonical project domain: [axiompress.dev](https://axiompress.dev).

## Project status

**In progress; not ready for production.** Basic account access, article editing, publication and isolated plugin execution have passed local tests. The full administration interface, editor features, data migration and production deployment are not complete. The existing WordPress site remains available for comparison as work continues.

Implemented components include:

-   PHP 8.5 request handling that does not load WordPress plugins, PHP themes or the WordPress startup files.
-   Restricted Linux processes for plugin execution, with gVisor isolation, explicit permissions, resource limits and crash recovery.
-   Direct gVisor execution without a Docker daemon, with approved package contents, permission withdrawal and interrupted-worker recovery.
-   PostgreSQL 18 storage with separately restricted accounts for deployment, authentication and public reads.
-   Login, sessions and protection against forged requests; account permissions are checked before accessing site data.
-   Article editing, saved revisions, publication and scheduled publication, with checks for conflicting edits and repeated requests.
-   Background jobs with retries and duplicate prevention. Delivery currently records event information; it does not execute arbitrary plugin callbacks.
-   Private image uploads processed separately with gVisor. Regenerated images require an explicit publication action before becoming public.
-   Host PHP/PostgreSQL image-job processing with verified TLS and a restricted database account; PNG, JPEG and WebP decoding runs separately through direct gVisor.
-   Site settings, categories, tags and saved article relationships, with public pages rendered by core-owned templates.
-   A basic administration workspace for testing article, image and settings operations; it is not a complete WordPress administration replacement.
-   Gutenberg editing tests for paragraphs, headings, bold/italic formatting, saving, reloading and publication through AxiomPress APIs.
-   Operator-controlled approval of plugin packages and permissions, including permission withdrawal and recovery checks.
-   Core protections that prevent official WordPress packages from replacing AxiomPress.
-   Reviewed local Linux service packages with file integrity checks and systemd installation. Installation validates prerequisites without starting or enabling the extension service.
-   Repeatable tests for database behavior, plugin isolation, editing and performance.

PostgreSQL 18 stores AxiomPress data. Public reads can use PostgreSQL articles through an explicit configuration setting; the default still reads the existing WordPress MySQL database for comparison. Plugin execution and image processing have been tested with gVisor on a dedicated WSL2 Linux system. Runc is used only for development tests. Independent security and deployment review are still required before production use.

Plugin execution and image-job processing can now run without Docker. The Linux extension-service installer uses a dedicated core account, a service-owned cgroup subtree, read-only code and policy, and persistent recovery state. Local systemd tests cover package integrity, guest restrictions, normal restart, forced termination and refusal of missing isolation controls. Docker remains available for development and testing. This is not a complete production CMS installer: PHP-FPM/PostgreSQL provisioning, image-job service scheduling, signed distribution and updates, independent security review and real VPS acceptance remain unfinished.

The [account and publishing tests](tools/axiompress/database/PHASE3_ACCEPTANCE.md) cover authentication, article changes, public reads, database failures and publication changes during plugin execution. Additional tests run plugin and image processing under gVisor and exercise administration and Gutenberg editing in Chrome. Passing these tests does not establish complete WordPress functionality or production readiness.

Remaining work includes the full administration and editing experience, a smaller editor download, image selection within the editor, plugin storage and installation/update management, activity records, recovery, data migration and signed releases. Existing WordPress plugins and PHP themes cannot run unchanged. Unsupported user workflows or data require explicit decisions and migration reporting rather than silent removal.

## Architecture and policies

-   [Architecture, authority and decisions](ARCHITECTURE.md)
-   [Release and deployment policy](AXIOMPRESS_RELEASE_POLICY.md)
-   [Fork provenance and original baseline](FORK_PROVENANCE.md)
-   [Contributing to AxiomPress](CONTRIBUTING.md) and [repository guidance](AGENTS.md)
-   [Core behavior and configuration](src/axiompress/README.md) and [public PostgreSQL configuration](src/axiompress/PublicReads.md)
-   [Worker supervision and containment limits](tools/axiompress/supervisor/README.md)
-   [Extension service, Linux host setup and verification](tools/axiompress/service/README.md)
-   [Running plugins and image processing without Docker](tools/axiompress/runtime/README.md)
-   [Linux extension-service packaging and installation](tools/axiompress/linux/README.md)
-   [Package and installation policy](tools/axiompress/policy/README.md)
-   [Data and API specifications](tools/axiompress/contracts/README.md)
-   [PostgreSQL schema and migration operations](tools/axiompress/database/README.md)
-   [Background jobs and scheduling](src/axiompress/Jobs/README.md), [image handling](src/axiompress/Media/README.md) and [administration features](src/axiompress/Platform/README.md)
-   [Supported article formatting](src/axiompress/Blocks/README.md) and [Gutenberg editor tests](tools/axiompress/experiments/GUTENBERG_NATIVE.md)

Official WordPress is a source of selectively reviewed changes, not a branch to merge routinely. Security fixes must be assessed against both retained code and equivalent rewritten behavior. Official core packages must never be installed over this fork. Production code and configuration belong to a separate deployment identity and must be read-only to application identities.

## Local development

Requirements:

-   Docker with a running Linux engine for the comparison site and container-based integration tests; the PowerShell runners use PowerShell 7 (`pwsh`). Direct worker execution and the Linux extension service do not require Docker.
-   Node.js 24.18 or newer and npm 11.16 or newer, as declared in `package.json`.
-   Composer 2 and 64-bit PHP 8.5 for independent core/tooling checks. Direct runtime tests require Linux with gVisor and cgroups v2; extension-service installation requires systemd 254 or newer. See the linked Linux guides for PHP extensions and host preparation.

The following Compose commands start the **existing WordPress site for comparison**, rather than the new PostgreSQL-backed application. On a new clone, copy `.env.example` to `.env` and review its settings. Set `LOCAL_PHP=8.5-fpm` to use the PHP 8.5 comparison image. Preserve an existing `.env` and database.

From the repository root:

```sh
npm ci
npm run build:dev
npm run env:start
```

On Windows PowerShell, use `npm.cmd` if the local execution policy blocks the npm PowerShell shim.

`env:start` also runs `composer update -W` inside the comparison environment; review any resulting dependency changes. Tests for the new application use separate temporary containers and credentials, as described below.

For a **fresh disposable development database only**, initialize the inherited comparison site with:

```sh
npm run env:install
```

That command resets the development database and rewrites local configuration. Do not rerun it against an existing site you want to keep. The comparison site is available at <http://localhost:8889>; its test login details are documented in the archived upstream guide and are for local development only.

The normal Compose environment is writable development infrastructure. It is not the production deployment profile.

### Read-only content preview

With the existing local database running, select one published, unprotected post and one private post. This test creates a separate SELECT-only database account and does not migrate content:

```powershell
$publicPostId = [int](Read-Host 'Published post ID')
$privatePostId = [int](Read-Host 'Private post ID')
pwsh -File tools/axiompress/native/run-checks.ps1 -PublicPostId $publicPostId -PrivatePostId $privatePostId
```

It leaves a service available only on the local computer at port **8890**, with route `GET /v1/sites/1/content/{id}`. This tests reading the MySQL comparison database; it does not configure PostgreSQL authentication, the administration workspace or the Gutenberg editor tests. Private content remains unavailable even with forged cookies or identity headers. The PHP built-in server is for development only.

This test currently assumes database `wordpress_develop`, table `wp_posts` and site 1. Read the [core configuration instructions](src/axiompress/README.md) before using it with a different environment. Generated credentials and configuration stay in `.cache/axiompress-native/`.

## Verification

PHP standards and component tests:

```sh
composer lint:axiompress
composer test:protocol
composer test:broker
php tests/axiompress/blocks-test.php
node tests/axiompress/blocks-parser-test.cjs
```

The PostgreSQL and HTTPS tests create temporary databases and containers on the Linux computer running Docker. From the repository directory, run with Python 3, OpenSSL, Docker and administrator access available:

```sh
sudo python3 tools/axiompress/database/run-checks.py
```

Add `--isolated-plugin --isolated-media` to require gVisor on a computer with registered `runsc` and cgroups v2. These tests fail if the required isolation is unavailable. They create and remove their own databases, networks and containers; they do not migrate the existing WordPress site or leave the new application running.

On the configured Windows computer, the dedicated `AxiomPress-Linux` WSL distribution also supports `--browser --editor-proof` with installed Windows Chrome, Node and the repository Playwright dependencies. The Gutenberg tests require the specified Gutenberg and supporting JavaScript files to be built first. See the [editor test instructions](tools/axiompress/experiments/GUTENBERG_NATIVE.md) for the exact WSL command and requirements. These editor pages are available only while the tests run.

Run the plugin process tests one at a time, with no other tests using their containers:

```powershell
pwsh -File tools/axiompress/supervisor/run-checks.ps1
pwsh -File tools/axiompress/supervisor/run-live-checks.ps1
pwsh -File tools/axiompress/supervisor/run-admission-checks.ps1
```

The live tests require suitable published content and a test administrator account; their parameters let you supply the IDs. These tools require Docker control, which must never be given to the web application or plugin processes. See the [plugin process documentation](tools/axiompress/supervisor/README.md) and [test instructions](tools/axiompress/experiments/README.md) for requirements and limitations.

New PHP code uses PSR-12; retained WordPress modules keep their existing coding standards. The AxiomPress GitHub workflow runs component, database and HTTPS tests. Browser tests, operating-system isolation, the full WordPress test suite and production review require separate checks.

## Contributing and upstream attribution

Use this fork's [issues](https://github.com/cbuntingde/axiompress/issues) and pull requests for AxiomPress work. Follow [AGENTS.md](AGENTS.md), preserve unrelated changes and provide verification appropriate to the affected boundary.

The original root README is preserved unchanged as [README-WORDPRESS.md](README-WORDPRESS.md), and the original contributor guide as [CONTRIBUTING-WORDPRESS.md](CONTRIBUTING-WORDPRESS.md). These document upstream WordPress workflows; their synchronization and submission instructions do not override AxiomPress policy.

Keep required contributor instructions and project documentation in version control. Never commit local configuration, credentials, generated caches or database data.

AxiomPress retains WordPress attribution and the [GPL-2.0-or-later license](src/license.txt). Repository or product renaming does not remove existing copyright, license or provenance notices.
