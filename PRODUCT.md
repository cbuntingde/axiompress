# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Owners, editors and authors write and publish articles, manage images and configure their site. The user confirmed this focused workspace as the first native administration UI. Deployment/package operators have a distinct authority boundary.

The first workspace is an integration prototype. Its focused scope does not authorize removing familiar WordPress workflows from the finished product or establish a complete administration feature set.

## Product Purpose

AxiomPress is an independent clean-break CMS fork of WordPress, with modern core services and explicit security boundaries. The native publishing workflow must use its own authenticated APIs, database policy and isolated extension contracts.

## Operating Context

The PHP 8.5 native kernel runs alongside an inherited WordPress comparison site during staged replacement. Native domain data uses PostgreSQL 18. Development containment is verified on the dedicated Linux/WSL2 Docker engine with gVisor. The local prototype is not production certified.

## Capabilities and Constraints

Core owns authentication, sessions, CSRF and current resource authorization. Authors edit their own articles; editors/owners control publication. Private drafts and quarantined images cannot become public through a cookie or plugin claim. No third-party script executes in the privileged administration origin. Production code is immutable to application identities. Official WordPress packages cannot overwrite the fork.

## Brand Commitments

Product/project name: AxiomPress. Canonical domain: https://axiompress.dev. No approved logo, marketing claims, customer testimonials or new public update service are supplied.

## Evidence on Hand

Tracked architecture, release policy, fork provenance and Phase 3 acceptance contract. The native login/article/public-read/isolated-plugin pipeline has actual PostgreSQL/HTTPS/gVisor evidence. Phase 4 extends it; importing and production rollout remain later gates.

## Product Principles

-   Preserve explicit core, operator and extension authority boundaries.
-   Make writing/publishing, image state and site settings understandable and recoverable.
-   Preserve familiar administration conventions and publishing workflows; reuse reviewed Gutenberg/interface components where native security boundaries permit it.
-   Require an explicit feature scope and parity evidence before cutover. Distinguish prototype limitations from approved feature exclusions.
-   Treat unsupported legacy behavior explicitly; do not silently execute compatibility callbacks.
-   Report verified behavior and remaining operational gates candidly.
