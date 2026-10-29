# AxiomPress experimental plugin protocol

This standalone PHP library implements framing and message validation only. It does not load WordPress, launch workers, authenticate connections, authorize content, render HTML or enforce OS isolation. Load ProtocolError.php and Protocol.php explicitly until the supervisor has its own bootstrap/autoloading.

The wire format is a four-byte unsigned big-endian body length, followed by UTF-8 JSON. Maximum body: 262144 bytes; maximum container nesting: 16. Zero/oversized frames, incomplete reads, duplicate decoded keys (including escaped aliases), malformed UTF-8, unsupported versions/operations/types and unknown fields fail with ProtocolError. Never resynchronize after an error; the supervisor must close/terminate the invocation. Clean EOF before a frame returns null. Consecutive calls read consecutive frames.

read() is for blocking streams controlled by the supervisor. It does not impose a deadline. Empty reads from a nonblocking or timed-out stream are terminal incomplete-frame errors; an event-loop incremental parser is not provided. The future supervisor must enforce monotonic deadlines and terminate the worker independently, including when the worker stalls. encode() returns a complete validated frame; the caller must handle partial writes and write deadlines.

Messages have explicit kind: request, dispatch, response or completion. Request permits content.read; dispatch permits component.render. Both carry an invocation handle with 64 lowercase hex characters and params containing only content_id. A syntactically valid handle is not an authenticated or authorized handle. Responses return limited content fields; completion returns numeric reading_summary data. Either may return a structured error instead of a result. Error text is data, never trusted HTML; broker errors must use fixed safe messages.

messages.schema.json documents the shapes. Wire constraints supplement JSON Schema: duplicate keys, byte limits, nesting, framing and PHP integer representation are enforced by the codec. JSON Schema string lengths count characters; the codec additionally limits UTF-8 bytes. A JSON numeric value written as 1.0 may satisfy JSON Schema integer semantics but is rejected by the PHP codec. All numeric component fields must use integer notation on the wire.

manifest.schema.json defines a deliberately narrow source-package manifest. The policy package loader validates captured source bytes and binds them to a fixed-recipe image; see ../policy/README.md. Archive ingestion and a publisher-authenticated installer remain pending. Schema validity alone does not establish package integrity, grant approval or safe entrypoint execution. Installation/site identities and verified package digest come from trusted policy records, never a manifest claim. Worker PHP is never included in this process.

## Checks

From repository root:

```powershell
php tests/axiompress/protocol-test.php
docker compose --env-file .env exec -T php php tests/axiompress/protocol-test.php
php vendor/bin/phpcs --standard=PSR12 tools/axiompress/protocol tests/axiompress --extensions=php
```

Schemas and fixtures can be checked with Python jsonschema (development-only dependency):

```powershell
python -m pip install --target .cache/axiompress-schema-validation jsonschema
$env:PYTHONPATH = Join-Path $PWD '.cache/axiompress-schema-validation'
python tests/axiompress/schema-test.py
```

The schema test meta-validates Draft 2020-12 documents and shares message fixtures with PHP. The PHP runner also covers every truncation boundary, one-byte transport fragmentation, concatenated frames, invalid headers, duplicate keys and body limits. These checks do not prove broker authority or sandbox containment.
