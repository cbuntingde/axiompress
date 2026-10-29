"""Profile fixed approved worker bytes; synthetic broker replies do not establish authorization."""
import json
from pathlib import Path
import re
import secrets
import struct
import subprocess
import sys
import time

root = Path(__file__).resolve().parents[3]
image = json.loads((root / "docs/axiompress-policy-runtime-last.jsonl").read_text())["image"]
assert re.fullmatch(r"sha256:[a-f0-9]{64}", image)


def frame(value):
    body = json.dumps(value, ensure_ascii=False).encode()
    assert len(body) <= 262144
    return struct.pack("!I", len(body)) + body


records = []
for label, text, words in (("empty", "", 0), ("five_words", "One two three four five.", 5),
                           ("unicode", "κόσμε café 你好 " * 2000, 6000), ("near_limit", "word " * 26000, 26000)):
    for iteration in range(4):
        name = "axiompress-profile-" + secrets.token_hex(12)
        dispatch = {"api": "axiompress.plugin/0.1", "kind": "dispatch", "id": "dispatch1",
                    "operation": "component.render", "invocation": "a" * 64, "params": {"content_id": "post:1"}}
        reply = {"api": "axiompress.plugin/0.1", "kind": "response", "id": "read1", "result": {
            "content_id": "post:1", "revision": "v1", "title": "Profile", "text": text}}
        command = ["docker", "run", "--rm", "-i", "--name", name, "--label=dev.axiompress.prototype=profile-v1",
                   "--pull=never", "--read-only", "--network=none", "--ipc=none", "--cap-drop=ALL",
                   "--security-opt=no-new-privileges:true", "--user=10001:10001", "--memory=128m",
                   "--memory-swap=128m", "--cpus=0.5", "--pids-limit=16", "--ulimit=nofile=64:64",
                   "--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m", "--log-driver=none", "--entrypoint=/usr/bin/time",
                   image, "-v", "/usr/local/bin/php", "-n", "-d", "memory_limit=96M", "/app/worker.php"]
        start = time.monotonic()
        try:
            result = subprocess.run(command, input=frame(dispatch) + frame(reply), capture_output=True, timeout=8)
            if result.returncode:
                raise RuntimeError("Trusted profile worker failed: " + result.stderr.decode(errors="replace")[-1024:])
        finally:
            cleanup = subprocess.run(["docker", "rm", "-f", name], capture_output=True, timeout=5)
            if cleanup.returncode and b"No such container" not in cleanup.stderr:
                raise RuntimeError("Profile cleanup failed")
        messages = []
        output = result.stdout
        while output:
            assert len(output) >= 4
            length = struct.unpack("!I", output[:4])[0]
            assert 0 < length <= 262144 and len(output) >= length + 4
            messages.append(json.loads(output[4:4 + length]))
            output = output[4 + length:]
        assert len(messages) == 2 and messages[0]["invocation"] == dispatch["invocation"]
        assert messages[1]["result"]["word_count"] == words
        assert messages[1]["result"]["reading_seconds"] == (words * 3 + 9) // 10
        match = re.search(rb"Maximum resident set size \(kbytes\):\s*(\d+)", result.stderr)
        assert match
        records.append({"case": label, "sample": iteration + 1, "input_bytes": len(text.encode()),
                        "cold_profile_ms": round((time.monotonic() - start) * 1000, 3),
                        "worker_lifetime_peak_rss_kib": int(match[1])})
report = {"outcome": "passed", "image": image, "samples": records,
          "max_worker_lifetime_peak_rss_kib": max(row["worker_lifetime_peak_rss_kib"] for row in records),
          "limits": "Controlled approved source; /usr/bin/time replaces entrypoint only for profiling. RSS excludes runtime/page-cache overhead; synthetic replies bypass real broker policy. Hostile containment is tested separately."}
(root / "docs/axiompress-worker-profile-last.json").write_text(json.dumps(report, indent=2) + "\n")
sys.stdout.write(f"PASS: {len(records)} worker profiles; max lifetime PHP RSS {report['max_worker_lifetime_peak_rss_kib']} KiB\n")
