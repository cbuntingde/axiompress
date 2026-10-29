"""Run on the Linux daemon host: actual gVisor boundary and cgroup resource observations."""

import json
import os
from pathlib import Path
import secrets
import subprocess
import time

root = Path(__file__).resolve().parents[3]
image = subprocess.check_output(['docker', 'image', 'inspect', 'axiompress-worker:phase2',
                                 '--format', '{{.Id}}'], text=True).strip()
checks = []
observations = {}
owned = []
os.environ['AXIOMPRESS_CORE_SECRET'] = 'operator-only-environment-canary'


def docker(*args):
    result = subprocess.run(['docker', *args], capture_output=True, text=True, timeout=15)
    if result.returncode:
        raise RuntimeError(result.stderr[-1500:])
    return result.stdout.strip()


def check(name, condition):
    if not condition:
        raise AssertionError(name)
    checks.append(name)


def create(code):
    name = 'axiompress-proof-' + secrets.token_hex(12)
    owned.append(name)
    docker('create', '--name', name, '--pull=never', '--runtime=runsc', '--read-only', '--network=none',
           '--ipc=none', '--cap-drop=ALL', '--security-opt=no-new-privileges:true', '--user=10001:10001',
           '--memory=128m', '--memory-swap=128m', '--cpus=0.5', '--pids-limit=64', '--ulimit=nproc=16:16',
           '--ulimit=nofile=64:64', '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m',
           '--entrypoint', 'php', image, '-n', '-d', 'memory_limit=-1', '-r', code)
    return name


def state(name):
    return json.loads(docker('inspect', name))[0]


def wait(name):
    return int(docker('wait', name))


def cgroup(name):
    pid = state(name)['State']['Pid']
    groups = Path('/proc', str(pid), 'cgroup').read_text().splitlines()
    unified = [line[3:] for line in groups if line.startswith('0::')]
    check('unified host cgroup observed', len(unified) == 1)
    path = Path('/sys/fs/cgroup' + unified[0])
    check('actual host resource settings', (path / 'memory.max').read_text().strip() == '134217728'
          and (path / 'memory.swap.max').read_text().strip() == '0'
          and (path / 'pids.max').read_text().strip() == '64'
          and (path / 'cpu.max').read_text().split() == ['50000', '100000'])
    return path


def counters(path):
    return {key: int(value) for key, value in (line.split() for line in path.read_text().splitlines())}


try:
    probes = r'''
$status=file_get_contents('/proc/self/status');
$out=array('nonroot'=>preg_match('/^Uid:\s+10001\s/m',$status)===1,
 'zero_caps'=>preg_match('/^CapEff:\s+0+\s*$/m',$status)===1,
 'readonly_root'=>@file_put_contents('/app/escape','x')===false,
 'no_socket'=>!file_exists('/var/run/docker.sock'),
 'no_core'=>!file_exists('/var/www/wp-config.php'),
 'no_secret'=>getenv('AXIOMPRESS_CORE_SECRET')===false);
foreach(array('127.0.0.1','169.254.169.254','10.0.0.1','1.1.1.1','[::1]') as $host){
 $s=@fsockopen($host,80,$errno,$error,0.1);$out['network_'.$host]=$s===false;
 if(is_resource($s)){fclose($s);}
}
file_put_contents('/tmp/exec-test',"#!/bin/sh\nexit 0\n");chmod('/tmp/exec-test',0755);
$p=@proc_open(array('/tmp/exec-test'),array(0=>array('file','/dev/null','r'),
 1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')),$pipes);
$out['tmpfs_noexec']=!is_resource($p)||proc_close($p)!==0;unlink('/tmp/exec-test');
$f=fopen('/tmp/fill','wb');$bytes=0;$limited=false;$chunk=str_repeat('x',65536);
while($bytes<=67108864){$n=@fwrite($f,$chunk);if($n===false||$n===0){$limited=true;break;}$bytes+=$n;}
fclose($f);$out['tmpfs_quota']=$limited&&$bytes>=62914560&&$bytes<=67108864;
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
    name = create(probes)
    docker('start', name)
    check('boundary worker exits successfully', wait(name) == 0)
    result = json.loads(docker('logs', name))
    for key, value in result.items():
        check(key, value is True)
    observations['boundary_probes'] = result

    name = create('while(true){}')
    docker('start', name)
    group = cgroup(name)
    before = counters(group / 'cpu.stat')
    started = time.monotonic()
    time.sleep(2)
    after = counters(group / 'cpu.stat')
    ratio = (after['usage_usec'] - before['usage_usec']) / ((time.monotonic() - started) * 1e6)
    check('half CPU quota actually throttles', 0.25 <= ratio <= 0.65
          and after['nr_throttled'] > before['nr_throttled'])
    observations['cpu_core_fraction'] = ratio
    observations['busy_worker_memory_bytes'] = int((group / 'memory.current').read_text())
    observations['busy_worker_peak_memory_bytes'] = int((group / 'memory.peak').read_text())
    docker('rm', '-f', name)
    owned.remove(name)

    name = create('sleep(1); $allocation=str_repeat("x",200*1024*1024); echo strlen($allocation);')
    docker('start', name)
    cgroup(name)
    exit_code = wait(name)
    check('memory exhausted at the OS boundary', exit_code != 0 and state(name)['State']['OOMKilled'])
    observations['memory_exit'] = exit_code

    processes = r'''
$children=array();$failed=0;
for($i=0;$i<24;$i++){
 $p=@proc_open(array('/bin/sleep','3'),array(0=>array('file','/dev/null','r'),
  1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')),$pipes);
 if(is_resource($p)){$children[]=$p;}else{$failed++;}
}
echo json_encode(array('started'=>count($children),'failed'=>$failed,
 'guest_processes'=>count(glob('/proc/[0-9]*'))),JSON_THROW_ON_ERROR);
foreach($children as $p){proc_close($p);}
'''
    name = create(processes)
    docker('start', name)
    group = cgroup(name)
    peak = 0
    deadline = time.monotonic() + 2
    while time.monotonic() < deadline:
        try:
            peak = max(peak, int((group / 'pids.current').read_text()))
        except FileNotFoundError:
            break
        time.sleep(0.01)
    check('process-pressure worker exits', wait(name) == 0)
    result = json.loads(docker('logs', name))
    check('guest nproc hard limit enforced', 12 <= result['guest_processes'] <= 16 and result['failed'] > 0)
    check('gVisor host tasks remain bounded', 1 <= peak <= 64)
    observations['processes'] = dict(result, peak_host_tasks=peak)

    name = create('file_put_contents("/tmp/plugin-state","private"); echo "written"; sleep(4);')
    docker('start', name)
    for _ in range(30):
        if docker('logs', name) == 'written':
            break
        time.sleep(0.02)
    else:
        raise AssertionError('First installation scratch state was not written')
    second = create('echo file_exists("/tmp/plugin-state") ? "leaked" : "isolated";')
    docker('start', second)
    check('other invocation cannot read plugin scratch state', wait(second) == 0
          and docker('logs', second) == 'isolated')

    report = {'outcome': 'passed', 'runtime': 'runsc', 'checks': checks,
              'observations': observations, 'worker_image': image, 'host_kernel': Path('/proc/version').read_text(),
              'scope': 'Native WSL2 Linux engine with cgroups v2; no ignore-cgroups or nested toolbox. Not production certification.'}
finally:
    for name in owned:
        identifiers = docker('ps', '-aq', '--filter', 'name=^/' + name + '$').split()
        for identifier in identifiers:
            docker('rm', '-f', identifier)

(root / 'docs/axiompress-phase2-runsc-resources-last.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print('PASS: ' + str(len(checks)) + ' native gVisor resource/boundary checks')
