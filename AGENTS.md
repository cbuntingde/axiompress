# Repository guidance

## Fork direction

The product is AxiomPress (https://axiompress.dev). Follow AXIOMPRESS_RELEASE_POLICY.md. Official WordPress core packages must never be installed over this fork. Production code must be read-only to application identities; release deployment belongs to a separate identity.

This repository is developing an independent, clean-break WordPress fork. Legacy runtime backward compatibility is intentionally outside the target architecture. Prioritize the fork's explicit security boundaries and maintainability over preserving legacy plugin, theme, hook, database, or bootstrap contracts.

This direction does not authorize unrelated rewrites or removal of working functionality. Implement changes in reviewable stages with evidence appropriate to their scope.

## Relationship with official WordPress

- Treat official WordPress as a source of reviewed changes, not a branch to merge routinely.
- Before the first architectural divergence, record the exact upstream repository URL and baseline commit in a tracked provenance document. Verify these values from Git; do not infer them from a version string. Preserve any baseline already recorded.
- Do not routinely merge, rebase onto, or wholesale synchronize upstream after the architectural break. A deliberate integration strategy change requires explicit user direction.
- Continue monitoring upstream security work. Assess applicability to both retained code and equivalent behavior in rewritten modules. Replacing an implementation does not prove the underlying vulnerability is absent.
- Port relevant security fixes, accessibility improvements, editor changes, and standards updates selectively. Inspect their dependencies and assumptions before adapting them.
- For each upstream-derived change, record its source commit or advisory, applicability, adaptations, and verification in the commit or PR description. Preserve required attribution and license notices.
- Do not reintroduce removed compatibility machinery, shared plugin authority, or legacy bootstrap behavior merely to make an upstream patch apply.
- Retained upstream modules require closer upstream review. Replaced modules require independent security and maintenance coverage. Keep ownership and provenance clear as modules change.
- Own the fork's architecture, extension contracts, versioning, and release gates. Upstream release timing does not determine fork readiness.
- Version the offline importer against explicitly supported WordPress source versions. Data migration support does not imply executable plugin or theme compatibility.

## Security architecture principles

- Third-party plugin and theme code must not execute inside the privileged core process in the target architecture.
- A separate process alone is not sufficient isolation. Enforce operating-system boundaries, restricted credentials and filesystem access, network policy, resource limits, and brokered capabilities.
- Core owns authentication, authorization, sessions, and access to core data. Plugin requests require explicit actor, site, resource, and installation-grant checks.
- Do not claim sandboxing or modern PHP eliminates plugin vulnerabilities. Distinguish implemented controls, verified containment, assumptions, and remaining risks.

## Local plans and tracked guidance

- `docs/` holds local architecture plans and working documents and is intentionally ignored by Git.
- Do not force-add files under `docs/` or remove its ignore rule unless the user explicitly requests it.
- Durable instructions needed by future contributors belong in tracked guidance such as this file. Do not make required repository behavior depend solely on ignored plans.

## Verification and scope

- Preserve unrelated working-tree changes.
- Use focused checks appropriate to the change. Report untested runtime, migration, isolation, and deployment gates explicitly.
- For security ports, verify the affected behavior and the fork's boundary conditions; a clean patch application or successful build alone is insufficient.
