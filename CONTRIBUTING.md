# Contributing to AxiomPress

AxiomPress is an independent WordPress fork. Submit fork-specific issues and pull requests to [cbuntingde/axiompress](https://github.com/cbuntingde/axiompress). A WordPress Trac ticket is not required for an AxiomPress change.

Read [AGENTS.md](AGENTS.md), [ARCHITECTURE.md](ARCHITECTURE.md), the [release policy](AXIOMPRESS_RELEASE_POLICY.md) and [fork provenance](FORK_PROVENANCE.md) before changing security boundaries or inherited behavior.

-   Work in reviewable stages. Explain the behavior, authorization and failure paths affected by a change, and include focused verification.
-   Preserve unrelated working-tree changes, WordPress attribution and existing license notices.
-   Do not routinely merge or synchronize upstream. Selectively adapted upstream changes need their source commit/advisory, applicability, adaptations and verification recorded in the commit or pull request.
-   Do not restore privileged plugin/theme execution or compatibility hooks in the native kernel. Do not install official WordPress core packages over this fork.
-   Independent PHP targets 64-bit PHP 8.5 and PSR-12; retained WordPress modules retain their existing standards. Use the relevant tests and the commands in [README.md](README.md).
-   Keep local plans under ignored `docs/`. Put required contributor/runtime instructions in version-controlled guidance. Do not commit credentials, `.env`, caches or database data.
-   State remaining runtime, migration, isolation and deployment verification limits. A successful build or container start is not a production security verdict.

The original upstream contributor guide is preserved unchanged as [CONTRIBUTING-WORDPRESS.md](CONTRIBUTING-WORDPRESS.md). Its WordPress submission process applies to contributions to upstream WordPress, not this fork. Preserve the distinction when reporting or adapting an upstream issue.
