"""Runs inside a disposable trusted OCI toolbox, never in the core or a plugin worker."""
import json
from pathlib import Path
import struct
import subprocess
import sys
import tarfile
import time

work = Path("/work/bundle")
work.mkdir()
root = work / "rootfs"
root.mkdir()
with tarfile.open("/artifact/worker.tar") as archive:
    # This is an operator-exported immutable fixture image, not a publisher upload.
    archive.extractall(root, filter="fully_trusted")
subprocess.run(["runsc", "spec", "--", "/usr/local/bin/php", "-n", "-r", "echo PHP_VERSION,PHP_EOL;"],
               cwd=work, check=True, capture_output=True)
configuration = json.loads((work / "config.json").read_text())
configuration["process"].update({"terminal": False, "user": {"uid": 10001, "gid": 10001},
    "env": ["PATH=/usr/local/bin:/usr/bin:/bin"], "cwd": "/app", "noNewPrivileges": True,
    "capabilities": {name: [] for name in ("bounding", "effective", "inheritable", "permitted", "ambient")},
    "rlimits": [{"type": "RLIMIT_NOFILE", "hard": 64, "soft": 64}]})
configuration["root"] = {"path": "rootfs", "readonly": True}
configuration["mounts"] = [mount for mount in configuration["mounts"] if mount["destination"] != "/sys/fs/cgroup"]
(work / "config.json").write_text(json.dumps(configuration))
results = []


def execute(arguments, data=b"", environment=()):
    configuration["process"]["args"] = arguments
    configuration["process"]["env"] = ["PATH=/usr/local/bin:/usr/bin:/bin", *environment]
    (work / "config.json").write_text(json.dumps(configuration))
    command = ["runsc", "--root=/work/state", "--platform=systrap", "--network=none", "--ignore-cgroups=true",
               "--debug-log=/work/runsc-debug.log", "run", "--bundle", str(work), "axiompress-evaluation"]
    start = time.monotonic()
    result = subprocess.run(command, input=data, capture_output=True, timeout=20)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors="replace")[-4096:])
    return result.stdout, round((time.monotonic() - start) * 1000, 3)


version, elapsed = execute(["/usr/local/bin/php", "-n", "-r", "echo PHP_VERSION;"])
assert version.decode() == "8.5.12"
results.append({"case": "php", "outcome": "passed", "cold_oci_ms": elapsed})


def frame(value):
    body = json.dumps(value, ensure_ascii=False).encode()
    return struct.pack("!I", len(body)) + body


for label, text, words, seconds in (("reading", "One two three four five.", 5, 2),
                                   ("empty", "", 0, 0), ("unicode", "κόσμε café 你好", 3, 1)):
    dispatch = {"api": "axiompress.plugin/0.1", "kind": "dispatch", "id": "dispatch1",
                "operation": "component.render", "invocation": "a" * 64, "params": {"content_id": "post:1"}}
    response = {"api": "axiompress.plugin/0.1", "kind": "response", "id": "read1", "result": {
        "content_id": "post:1", "revision": "v1", "title": "Fixture", "text": text}}
    output, elapsed = execute(["/usr/local/bin/php", "-n", "-d", "memory_limit=96M", "/app/worker.php"],
                              frame(dispatch) + frame(response), ["AXIOM_WORKER_MODE=reading"])
    messages = []
    while output:
        assert len(output) >= 4
        length = struct.unpack("!I", output[:4])[0]
        assert 0 < length <= 262144 and len(output) >= 4 + length
        messages.append(json.loads(output[4:4 + length]))
        output = output[4 + length:]
    assert len(messages) == 2 and messages[0]["invocation"] == dispatch["invocation"]
    assert messages[1]["id"] == "dispatch1"
    assert messages[1]["result"] == {"type": "reading_summary", "word_count": words, "reading_seconds": seconds}
    results.append({"case": label, "outcome": "passed", "cold_oci_ms": elapsed})

Path("/tmp/operator-canary").write_text("operator-only")
probes = r'''
$status=file_get_contents('/proc/self/status');
$checks=array(
  'nonroot'=>preg_match('/^Uid:\\s+10001\\s/m',$status)===1,
  'zero_capabilities'=>preg_match('/^CapEff:\\s+0+\\s*$/m',$status)===1,
  'readonly_root'=>@file_put_contents('/app/escape','x')===false,
  'no_core'=>!file_exists('/var/www/wp-config.php'),
  'no_runtime_socket'=>!file_exists('/var/run/docker.sock'),
  'no_operator_file'=>!file_exists('/tmp/operator-canary')
);
foreach(array('127.0.0.1','169.254.169.254','10.0.0.1','1.1.1.1','[::1]') as $host){
  $socket=@fsockopen($host,80,$number,$error,0.1);
  $checks['network_'.$host]=$socket===false;
  if(is_resource($socket)){fclose($socket);}
}
echo json_encode($checks,JSON_THROW_ON_ERROR);
'''
output, elapsed = execute(["/usr/local/bin/php", "-n", "-r", probes])
probes_result = json.loads(output)
assert all(value is True for value in probes_result.values()), probes_result
results.append({"case": "boundary_probes", "outcome": "passed", "checks": probes_result, "cold_oci_ms": elapsed})
report = {"runtime": "release-20261026.0", "platform": "systrap", "outcome": "passed", "results": results,
          "limits": "Nested privileged toolbox, ignore-cgroups; compatibility and configured boundary experiment only. Resource enforcement, native-host review and production integration remain unproven."}
sys.stdout.write(json.dumps(report) + "\n")
