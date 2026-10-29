"""Check core responsiveness while four real isolated workers hold all global admission slots."""
import concurrent.futures
import json
from pathlib import Path
import queue
import re
import secrets
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[3]
approved = json.loads((root / "docs/axiompress-policy-runtime-last.jsonl").read_text())["image"]
sdk = subprocess.check_output(["docker", "image", "inspect", "axiompress-worker-sdk:prototype", "--format", "{{.Id}}"], text=True).strip()
supervisor = subprocess.check_output(["docker", "image", "inspect", "axiompress-supervisor:prototype", "--format", "{{.Id}}"], text=True).strip()
name = "axiompress-load-" + secrets.token_hex(8)
command = ["docker", "run", "--rm", "--name", name, "--network=none", "--read-only", "--tmpfs=/tmp:rw,size=32m",
           "--mount=type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock", "--env=AXIOM_APPROVED_IMAGE=" + approved,
           "--env=AXIOM_SDK_IMAGE=" + sdk, "--entrypoint=php", supervisor, "/app/supervisor/load-test.php"]
process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
lines = queue.Queue()


def collect():
    for line in process.stdout:
        lines.put(line)
    lines.put(None)


thread = threading.Thread(target=collect, daemon=True)
thread.start()
records = []
http = []


def probe(index):
    status = 200 if index % 2 == 0 else 404
    content = 9 if status == 200 else 25
    start = time.monotonic()
    try:
        response = urllib.request.urlopen(f"http://127.0.0.1:8890/v1/sites/1/content/{content}", timeout=1)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        assert response.status == status
        assert response.headers["Cache-Control"] == "no-store"
        body = json.loads(response.read(300001))
        assert ("content" in body) == (status == 200)
    return {"status": status, "milliseconds": round((time.monotonic() - start) * 1000, 3)}


try:
    while True:
        line = lines.get(timeout=15)
        if line is None:
            break
        record = json.loads(line)
        records.append(record)
        if record.get("kind") == "saturated":
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
                http = list(executor.map(probe, range(16)))
            subprocess.run(["docker", "exec", name, "touch", "/tmp/load-release"], check=True, capture_output=True, timeout=2)
    assert process.wait(timeout=5) == 0
    assert len(http) == 16
    assert sum(record.get("kind") == "worker" for record in records) == 4
    assert records[-1] == {"kind": "complete", "outcome": "passed", "image": approved}
    report = {"outcome": "passed", "workers": records, "native_core_http": http,
              "limits": "One controlled four-site fixture round, no warm pool; not a production load/SLO claim."}
    (root / "docs/axiompress-loaded-core-last.json").write_text(json.dumps(report, indent=2) + "\n")
    sys.stdout.write("PASS: four concurrent useful workers, excess denial, 16 native HTTP/visibility checks and worker cleanup\n")
finally:
    subprocess.run(["docker", "rm", "-f", name], capture_output=True, timeout=5)
    process.wait(timeout=5)
    process.stdout.close()
    # The harness requires an exclusive initially idle prototype namespace, as checked inside load-test.php.
    inventory = subprocess.run(["docker", "ps", "-aq", "--filter", "label=dev.axiompress.prototype=worker-v1"],
                               capture_output=True, text=True, check=True, timeout=5).stdout.split()
    if inventory:
        assert all(re.fullmatch(r"[a-f0-9]{12,64}", identity) for identity in inventory)
        subprocess.run(["docker", "rm", "-f", *inventory], capture_output=True, check=True, timeout=5)
        raise RuntimeError("Load harness left worker resources; recovered the exclusive test namespace.")
