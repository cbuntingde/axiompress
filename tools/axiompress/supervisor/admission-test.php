<?php

declare(strict_types=1);

use AxiomPress\Supervisor\AdmissionGate;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../policy/bootstrap.php';
require __DIR__ . '/Supervisor.php';

$image = getenv('AXIOM_WORKER_IMAGE');
$docker = static function (array $arguments): string {
    $output = array();
    exec('timeout 5 docker ' . implode(' ', array_map('escapeshellarg', $arguments)), $output, $exit);
    if ($exit !== 0) {
        throw new RuntimeException('Recovery fixture Docker command failed.');
    }
    return implode("\n", $output);
};

if (in_array($argv[1] ?? '', array('child', 'contender'), true)) {
    $identity = ($argv[1] === 'child') ? 'child' : 'contender-' . $argv[3];
    try {
        $lease = (new AdmissionGate($argv[2]))->acquire($identity, 'site:' . $identity);
    } catch (RuntimeException $error) {
        if ($argv[1] === 'contender' && $error->getMessage() === 'admission_busy') {
            echo "busy\n";
            exit(0);
        }
        throw $error;
    }
    if ($argv[1] === 'child' && is_string($image) && $image !== '') {
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)) {
            throw new RuntimeException('Immutable fixture image required.');
        }
        $id = $docker(array('create', '--pull=never', '-i', '--network=none', '--read-only',
            '--user=10001:10001', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
            '--memory=128m', '--memory-swap=128m', '--pids-limit=16', '--log-driver=none',
            '--label=dev.axiompress.prototype=worker-v1', $image));
        $docker(array('start', $id));
    }
    echo "ready\n";
    fflush(STDOUT);
    while (true) {
        sleep(1);
    }
}

$directory = '/tmp/admission-' . bin2hex(random_bytes(12));
if (is_string($image) && $image !== '' && Supervisor::remainingWorkers() !== array()) {
    throw new RuntimeException('Exclusive idle prototype runtime required.');
}
mkdir($directory, 0700);
$gate = new AdmissionGate($directory);
$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Admission assertion failed.');
    }
    $checks++;
};
$denied = static function (Closure $operation) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'admission_busy');
        return;
    }
    throw new RuntimeException('Expected admission denial.');
};
$leases = array();
for ($slot = 0; $slot < 4; $slot++) {
    $leases[] = $gate->acquire('install-' . $slot, 'site:' . $slot);
}
$denied(static fn() => $gate->acquire('other', 'site:other'));
$denied(static fn() => $gate->recover(static function (): void {
    throw new LogicException('Cleanup ran while owners were active.');
}));
foreach ($leases as $lease) {
    $lease->finish(true);
}
$lease = $gate->acquire('same-install', 'site:one');
$denied(static fn() => $gate->acquire('same-install', 'site:two'));
$denied(static fn() => $gate->acquire('other-install', 'site:one'));
$lease->finish(true);
$process = proc_open(array(PHP_BINARY, __FILE__, 'child', $directory), array(
    0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
), $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Child failed to start.');
}
try {
    stream_set_timeout($pipes[1], 3);
    $assert(fgets($pipes[1]) === "ready\n");
    $denied(static fn() => $gate->acquire('child', 'site:child'));
    $denied(static fn() => $gate->recover(static function (): void {
        throw new LogicException('Recovery interrupted a separate live process.');
    }));
    proc_terminate($process, 9);
    $deadline = hrtime(true) + 2_000_000_000;
    while (proc_get_status($process)['running']) {
        if (hrtime(true) > $deadline) {
            throw new RuntimeException('Child did not terminate.');
        }
        usleep(1000);
    }
} finally {
    proc_terminate($process, 9);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
}
$denied(static fn() => $gate->acquire('child', 'site:child'));
if (is_string($image) && $image !== '') {
    $assert(count(Supervisor::remainingWorkers()) === 1);
}
try {
    $gate->recover(static function (): void {
        throw new RuntimeException('simulated_daemon_unavailable');
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'simulated_daemon_unavailable') {
        throw $error;
    }
    $assert($error->getMessage() === 'simulated_daemon_unavailable');
}
$denied(static fn() => $gate->acquire('child', 'site:child'));
$gate->recover(static function () use ($image): void {
    if (is_string($image) && $image !== '') {
        Supervisor::reconcile();
    }
});
if (is_string($image) && $image !== '') {
    $assert(Supervisor::remainingWorkers() === array());
}
$lease = $gate->acquire('child', 'site:child');
$lease->finish(false);
$denied(static fn() => $gate->acquire('child', 'site:child'));
$gate->recover(static function (): void {
});
$lease = $gate->acquire('child', 'site:child');
$lease->finish(true);
$assert(count(glob($directory . '/slot-*')) === 0);
file_put_contents($directory . '/slot-0', '{');
try {
    $gate->acquire('corrupt', 'site:corrupt');
    throw new LogicException('Corrupt reservation allowed admission.');
} catch (JsonException $error) {
    $assert(true);
}
$gate->recover(static function (): void {
});
$contenders = array();
$accepted = 0;
$busy = 0;
try {
    for ($index = 0; $index < 8; $index++) {
        $contender = proc_open(array(PHP_BINARY, __FILE__, 'contender', $directory, (string) $index), array(
            0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
        ), $contenderPipes);
        if (!is_resource($contender)) {
            throw new RuntimeException('Contender failed to start.');
        }
        $contenders[] = array($contender, $contenderPipes);
        stream_set_timeout($contenderPipes[1], 3);
        $line = fgets($contenderPipes[1]);
        if ($line === "ready\n") {
            $accepted++;
        } elseif ($line === "busy\n") {
            $busy++;
        } else {
            throw new RuntimeException('Invalid contender result.');
        }
    }
    $assert($accepted === 4 && $busy === 4);
} finally {
    foreach ($contenders as [$contender, $contenderPipes]) {
        proc_terminate($contender, 9);
        foreach ($contenderPipes as $pipe) {
            fclose($pipe);
        }
        proc_close($contender);
    }
}
$gate->recover(static function (): void {
});
foreach (glob($directory . '/*') as $path) {
    unlink($path);
}
rmdir($directory);
echo "PASS: $checks cross-process admission/recovery checks\n";
