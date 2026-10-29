"""Validate schema documents and the shared fixture corpus with jsonschema."""
import json
import sys
from pathlib import Path

from jsonschema import Draft202012Validator

root = Path(__file__).resolve().parents[2]
fixtures = json.loads((root / 'tests/axiompress/protocol-fixtures.json').read_text())
validators = {}
for name in ('messages', 'manifest'):
    schema = json.loads((root / f'tools/axiompress/protocol/{name}.schema.json').read_text())
    Draft202012Validator.check_schema(schema)
    validators[name] = Draft202012Validator(schema)
for case in fixtures['messages']:
    if validators['messages'].is_valid(case['message']) != case['valid']:
        raise AssertionError(case['name'])
validators['manifest'].validate(fixtures['manifest'])
for key, value in [('entrypoint', '../worker.php'), ('capabilities', ['content.write']), ('actor', 'admin')]:
    bad = dict(fixtures['manifest'])
    bad[key] = value
    if validators['manifest'].is_valid(bad):
        raise AssertionError(f'Invalid manifest accepted: {key}')
sys.stdout.write(f"PASS: schema meta-validation, {len(fixtures['messages'])} message fixtures, 4 manifest cases\n")
