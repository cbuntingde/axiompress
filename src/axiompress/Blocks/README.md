# Native block documents

`Document` is a bounded canonical Gutenberg-grammar subset, not a general HTML sanitizer or a full Gutenberg implementation. Existing paragraph/heading text documents and native image blocks retain their original serialization. Unknown blocks, attributes, arbitrary HTML and noncanonical encodings are rejected on native writes. Existing unsupported source data must not be silently replaced by an editor.

Versioned rich text uses `core/paragraph` with `{"axiomVersion":1}` and `core/heading` with `{"level":2,"axiomVersion":1}` (levels 2–4). These map to native `rich-paragraph` / `rich-heading` values with an `html` string. The marker is mandatory: the old text-only form does not acquire HTML execution semantics. Native documents remain bounded to 64 blocks and 128 KiB, including comments and attributes.

Inline version 1 admits only balanced attribute-free `strong`, `em`, `code` and `br` tags, canonical escaped UTF-8 text, at most eight nesting levels and 4096 inline tokens per block. Links, attributes, scripts, style, event handlers, images, comments and arbitrary entities are not admitted. The core renderer reparses and validates before emitting approved inline markup; it continues to escape ordinary text. This deliberately small contract is an integration milestone, not approval to discard unsupported source formatting during migration.

The disposable Gutenberg experiment initially edits bold/italic paragraph/heading documents only. It rejects unsupported versions, nested blocks, code formatting and unknown attributes before mounting document content. The existing textarea prototype cannot edit rich documents; its refusal must preserve the saved source rather than flatten it. Full workflow integration and block/format expansion remain gated by the parity plan.

Run `php tests/axiompress/blocks-test.php` for canonical round-trips and markup rejection. `node tests/axiompress/blocks-parser-test.cjs` checks the pinned upstream parser against native fixtures. The database harness `--editor-proof` runs actual first-party Gutenberg editing against the native HTTPS/PostgreSQL commands; it does not mount the experiment in the native application router.

The parser source, license and reviewed adaptations are recorded in NOTICE.md. Any future document expansion needs explicit attribute/URL/nesting rules, render tests, round-trip tests and loss-aware migration mapping. Do not infer whole-site, block-library, plugin or HTML compatibility from successful parsing.
