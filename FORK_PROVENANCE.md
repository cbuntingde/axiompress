# Fork provenance

Recorded: 2026-10-28, before architectural implementation.

- Local baseline commit: `6f5c4bf210f82ec7b6b15bf83bdb368d6eddbf8c`.
- Local branch: `trunk`.
- Configured origin: `https://github.com/cbuntingde/wordpress-develop.git`.
- Upstream SVN identity recorded in that commit: `https://develop.svn.wordpress.org/trunk@64995`.
- SVN repository UUID: `602fd350-edb4-49c9-b593-d223f7449a82`.
- Official GitHub project referenced by the baseline commit: `https://github.com/WordPress/wordpress-develop` (PR 14981).

Evidence: local `git remote -v`, `git rev-parse HEAD`, and `git log -1 --format=fuller`. No official upstream remote is configured locally; remote equality with the official GitHub repository has not been independently checked. The recorded baseline identifies committed ancestry, not the locally modified working tree.

At recording time, local changes existed in `.gitignore`, `composer.json`, and `src/wp-content/plugins/hello.php`, with untracked `AGENTS.md` and `tests/phpunit/tests/plugins/`. Preserve and assess those separately; they are not part of the baseline commit.

Retain this baseline when recording future selective upstream ports. Preserve required license notices and attribution.

## Repository identity change: 2026-10-29

The existing GitHub repository was renamed from `cbuntingde/wordpress-develop` to `cbuntingde/axiompress`. Current origin: `https://github.com/cbuntingde/axiompress.git`; default branch remains `trunk`. The local checkout directory and Compose project identity remain `wordpress-develop`.

Verified with GitHub CLI repository metadata, `git remote -v`, and `git ls-remote --heads origin trunk`. This is a repository-location/branding change, not a new upstream baseline, history rewrite or data migration. The original baseline and originally configured origin above remain unchanged. The original root README is preserved as `README-WORDPRESS.md`.
