# Repository guidance

## Fork direction

The product is AxiomPress (https://axiompress.dev). Follow AXIOMPRESS_RELEASE_POLICY.md. Official WordPress core packages must never be installed over this fork. Production code must be read-only to application identities; release deployment belongs to a separate identity.

This repository is developing an independent, clean-break WordPress fork. Legacy runtime backward compatibility is intentionally outside the target architecture. Prioritize the fork's explicit security boundaries and maintainability over preserving legacy plugin, theme, hook, database, or bootstrap contracts.

This direction does not authorize unrelated rewrites or removal of working functionality. Implement changes in reviewable stages with evidence appropriate to their scope.

## Product continuity and feature parity

- Preserve familiar WordPress publishing workflows and administration conventions while replacing unsafe runtime coupling. A clean break in executable compatibility does not authorize incidental feature loss or an unrelated visual redesign.
- Prefer selectively reviewed Gutenberg and first-party interface components over recreating established editing behavior. Verify their dependencies, native API adapters, accessibility, licensing and security boundaries before adopting them. Do not load the inherited PHP administration bootstrap or arbitrary plugin scripts to obtain visual parity.
- Treat the minimal native administration workspace as an integration prototype, not evidence of complete WordPress administration or Gutenberg feature parity. Prototype limitations must not silently become release requirements.
- Before replacing or retiring an inherited workflow, record its retained behavior, native implementation/reuse boundary, remaining gaps and acceptance evidence. Removing or deferring a previously supported user workflow requires an explicit scope decision; unimplemented is not the same as intentionally excluded.
- Cutover requires an explicitly reviewed feature scope, loss-aware migration, usable familiar workflows and authorization/failure/accessibility evidence. Preserve the inherited comparison environment until those gates pass. Do not claim full WordPress feature, plugin, theme or API compatibility from visual similarity or block parsing.
- Keep detailed parity matrices and reuse experiments in ignored `docs/`. These tracked requirements remain authoritative when local plans are unavailable. Existing explicit architectural exclusions remain in force unless the user changes them.

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

## Public writing and commit messages

- Use plain, professional language in READMEs, commit subjects/bodies, pull request descriptions, release notes and other published project material. Describe the change, its purpose, current behavior and relevant limitations in terms readers can understand.
- Do not use development slang, internal planning labels or unexplained jargon as descriptions. Avoid wording such as "vertical slice", "spike", "dogfooding", "plumbing", "harness", "gate", "native pipeline", "proof" or "WIP". Describe the actual feature, test or unfinished work instead. Do not substitute internal phase numbers for an explanation of project status.
- Preserve exact commands, paths, API identifiers, product names and technical terms needed for accuracy; explain their purpose plainly. This rule does not authorize renaming code or hiding incomplete functionality, security limitations or failed checks.
- Do not link to, name or direct readers to Git-ignored documents in public project material or commit/PR messages. Do not mention their existence as supporting evidence. Verify documentation links with `git ls-files`; a file existing locally does not make it available to repository readers.
- Put information needed by repository readers in tracked documentation, or explain it directly. Keep local planning and evidence private without advertising unavailable documents. Repository instructions may specify where local files belong and how their ignore rules are maintained.
- Use descriptive plain-English commit subjects rather than unnecessary workflow labels or shorthand. Do not rewrite published commit history solely to apply this writing rule unless the user explicitly requests it.

## Verification and scope

- Independent PHP under `src/axiompress/`, `tools/axiompress/`, and `tests/axiompress/` targets 64-bit PHP 8.5 and PSR-12 with LF source bytes. Run `composer lint:axiompress`, `composer test:protocol`, and `composer test:broker`; retain WordPress coding standards for inherited modules. The focused AxiomPress workflow checks the independent paths separately; excluding them from the legacy ruleset is not permission to skip lint.

- Preserve unrelated working-tree changes.
- Use focused checks appropriate to the change. Report untested runtime, migration, isolation, and deployment gates explicitly.
- For security ports, verify the affected behavior and the fork's boundary conditions; a clean patch application or successful build alone is insufficient.
