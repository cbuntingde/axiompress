"""Exercise the independent native HTTP entrypoint against existing local content."""
import argparse
import json
import sys
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument("--base", default="http://127.0.0.1:8890")
parser.add_argument("--public", type=int, required=True)
parser.add_argument("--private", type=int, required=True)
args = parser.parse_args()
checks = 0


def request(path, status, method="GET", headers=None):
    global checks
    req = urllib.request.Request(args.base + path, method=method, headers=headers or {})
    try:
        response = urllib.request.urlopen(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read(300001)
        assert response.status == status, (path, response.status, body[:128])
        assert len(body) < 300001
        assert response.headers["Cache-Control"] == "no-store"
        assert response.headers["X-Content-Type-Options"] == "nosniff"
        assert response.headers["Content-Type"].startswith("application/json")
        assert "default-src 'none'" in response.headers["Content-Security-Policy"]
        assert response.headers.get("Access-Control-Allow-Origin") is None
        checks += 7
        return json.loads(body) if body else None


public_path = f"/v1/sites/1/content/{args.public}"
data = request(public_path, 200)
assert data["api"] == "axiompress.core/0.1"
assert set(data["content"]) == {"site_id", "content_id", "revision", "title", "text"}
assert data["content"]["content_id"] == args.public
checks += 3
request(public_path, 200, "HEAD")
request(public_path, 405, "POST")
request(f"/v1/sites/1/content/{args.private}", 404)
request(f"/v1/sites/1/content/{args.private}", 404, headers={
    "Authorization": "Bearer forged-admin", "Cookie": "wordpress_logged_in=forged",
    "X-Axiom-Actor": "user:1", "X-Axiom-Site": "1",
})
request(f"/v1/sites/2/content/{args.public}", 404)
for suffix in ("0", "01", "-1", "999999999999999999", "9%20OR%201=1", "9?actor=admin", "9/extra"):
    request("/v1/sites/1/content/" + suffix, 404)
for path in ("/wp-login.php", "/wp-config.php", "/Configuration.php", "/", "/../bootstrap.php"):
    request(path, 404)
sys.stdout.write(json.dumps({"outcome": "passed", "checks": checks, "public_id": args.public,
                            "private_id": args.private, "base": args.base}) + "\n")
