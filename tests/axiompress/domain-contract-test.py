"""Adversarial design-contract validation. This does not enable any runtime command or event."""
import copy
import json
from pathlib import Path
import sys
from jsonschema import Draft202012Validator, FormatChecker

root = Path(__file__).resolve().parents[2]
schema = json.loads((root / "tools/axiompress/contracts/domain-design.schema.json").read_text())
Draft202012Validator.check_schema(schema)
assert "date-time" in FormatChecker.checkers, "Install rfc3339-validator; do not silently skip date validation."
validator = Draft202012Validator(schema, format_checker=FormatChecker())
command = {"api": "axiompress.domain.design/0.1", "kind": "command", "operation": "content.propose_revision",
           "id": "request_1", "invocation": "a" * 64, "params": {"content_id": "post:1", "expected_revision": "b" * 64,
           "idempotency_key": "x" * 16, "changes": {"title": "Proposed title"}}}
event = {"api": command["api"], "kind": "event", "event": "content.published.v1", "event_id": "c" * 32,
         "site_id": "site:1", "content_id": "post:1", "revision": "d" * 64, "occurred_at": "2026-10-29T00:00:00Z"}
service = {"api": command["api"], "kind": "service_grant", "principal": "service:reading_summary", "site_id": "site:1",
           "installation": "install_1", "generation": 1, "operations": ["content.read"], "resources": ["post:1"],
           "expires_at": "2026-10-30T00:00:00Z"}
checks = 0
for valid in (command, event, service):
    validator.validate(valid)
    checks += 1
    for key in valid:
        invalid = copy.deepcopy(valid)
        del invalid[key]
        assert not validator.is_valid(invalid), (valid["kind"], key)
        checks += 1
    for key in ("actor", "password", "callback", "sql", "html"):
        invalid = copy.deepcopy(valid)
        invalid[key] = "forged"
        assert not validator.is_valid(invalid), key
        checks += 1
for key, value in (("content_id", "post:1\n"), ("content_id", "post:01"), ("expected_revision", "x"),
                   ("idempotency_key", "short"), ("changes", {}), ("changes", {"status": "publish"}),
                   ("changes", {"text": "x" * 131073})):
    invalid = copy.deepcopy(command)
    invalid["params"][key] = value
    assert not validator.is_valid(invalid), key
    checks += 1
for value in ("2026-99-99T99:99:99Z", "2026-10-29T00:00:00+00:00", "2026-10-29"):
    invalid = copy.deepcopy(event)
    invalid["occurred_at"] = value
    assert not validator.is_valid(invalid), value
    checks += 1
for key, value in (("principal", "user:1"), ("operations", ["content.publish"]), ("resources", []),
                   ("resources", ["post:1", "post:1"]), ("generation", 0)):
    invalid = copy.deepcopy(service)
    invalid[key] = value
    assert not validator.is_valid(invalid), key
    checks += 1
sys.stdout.write(f"PASS: {checks} design command/event/service contract checks\n")
