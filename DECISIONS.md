# Decisions

## 2026-10-31 — Gutenberg editor construction path
- Options: Adopt pinned official @wordpress packages behind license/dependency/security review vs native reimplementation without upstream packages
- Rationale: User chose reviewed adoption on 2026-10-31: fastest path to genuine Gutenberg behavior while keeping the fork clean-break (no wp-admin bootstrap, no packages installed over core, selective review per AGENTS.md)
- Owner: user

## 2026-10-31 — Administration navigation scope
- Options: Staged menu (Dashboard/Posts/Media now, then Pages/Comments/Appearance/Users/Tools/Settings per slice) vs full menu shell with unavailable sections marked
- Rationale: User chose staged on 2026-10-31: unimplemented sections stay visibly absent, never faked; Plugins stays out per isolation architecture, per-installation plugin storage already denied
- Owner: user

## 2026-10-31 — Gutenberg block adoption order
- Options: Core subset first (paragraph/heading/image/list/quote/buttons/columns, each reviewed and round-trip-safe) vs full 50+ block queue
- Rationale: User chose core subset on 2026-10-31, matching the per-block review rule already in force; image block already proven with published-image picker and byte-canonical markup
- Owner: user
