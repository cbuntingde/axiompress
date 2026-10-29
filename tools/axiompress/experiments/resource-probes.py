"""Observe actual OS PID saturation and removal of a controlled hostile shell fixture."""
import json
from pathlib import Path
import secrets
import subprocess
import sys
import time

root = Path(__file__).resolve().parents[3]
image = subprocess.check_output(["docker", "image", "inspect", "axiompress-worker:prototype", "--format", "{{.Id}}"], text=True).strip()
name = "axiompress-resource-" + secrets.token_hex(12)


def docker(*arguments, allow_failure=False):
    result = subprocess.run(["docker", *arguments], capture_output=True, text=True, timeout=5)
    if result.returncode and not allow_failure:
        raise RuntimeError(result.stderr[-2048:])
    return result


try:
    docker("run", "-d", "--name", name, "--label=dev.axiompress.prototype=resource-probe-v1",
           "--pull=never", "--network=none", "--read-only", "--cap-drop=ALL", "--security-opt=no-new-privileges:true",
           "--user=10001:10001", "--memory=128m", "--memory-swap=128m", "--cpus=0.5", "--pids-limit=16",
           "--log-driver=none", "--entrypoint=/usr/local/bin/php", image, "-n", "-r",
           'passthru("while true; do sleep 30 & done"); sleep(10);')
    peak = 0
    deadline = time.monotonic() + 2
    observations = []
    while time.monotonic() < deadline:
        count = len(docker("top", name, "-eo", "pid").stdout.strip().splitlines()) - 1
        observations.append(count)
        assert 1 <= count <= 16, count
        peak = max(peak, count)
        if peak == 16:
            break
        time.sleep(0.02)
    assert peak >= 14, observations
    inspection = json.loads(docker("inspect", name).stdout)[0]
    assert inspection["HostConfig"]["PidsLimit"] == 16
    assert inspection["HostConfig"]["NanoCpus"] == 500000000
    assert inspection["State"]["Running"]
finally:
    docker("rm", "-f", name)
assert not docker("ps", "-aq", "--filter", "name=^/" + name + "$").stdout.strip()
try:
    docker("run", "-d", "--name", name, "--label=dev.axiompress.prototype=resource-probe-v1",
           "--pull=never", "--network=none", "--read-only", "--cap-drop=ALL", "--security-opt=no-new-privileges:true",
           "--user=10001:10001", "--memory=128m", "--memory-swap=128m", "--cpus=0.5", "--pids-limit=16",
           "--log-driver=none", "--entrypoint=/usr/local/bin/php", image, "-n", "-r", "while(true){}")
    cpu_percent = float(docker("stats", "--no-stream", "--format", "{{.CPUPerc}}", name).stdout.strip().rstrip("%"))
    # Docker reports CPU as a percentage of one host core. Allow sampling/startup noise around 50%.
    assert 5 <= cpu_percent <= 80, cpu_percent
finally:
    docker("rm", "-f", name)
assert not docker("ps", "-aq", "--filter", "name=^/" + name + "$").stdout.strip()
report = {"outcome": "passed", "image": image, "observed_pids": observations, "peak_pids": peak,
          "limit": 16, "quota_nanocpus": 500000000, "busy_cpu_percent_sample": cpu_percent, "cleanup_empty": True,
          "limits": "Controlled fixed shell workload; native runc cgroup evidence, not gVisor or arbitrary kernel-escape proof."}
(root / "docs/axiompress-resource-probes-last.json").write_text(json.dumps(report, indent=2) + "\n")
sys.stdout.write(f"PASS: PID saturation {peak}/16, busy CPU {cpu_percent}% and whole-container removal\n")
