<?php

declare(strict_types=1);

use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Supervisor\AdmissionGate;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../policy/bootstrap.php';
require __DIR__ . '/Supervisor.php';
if (Supervisor::remainingWorkers() !== array()) {
    throw new RuntimeException('Exclusive idle prototype namespace required.');
}
$image = (string) getenv('AXIOM_APPROVED_IMAGE');
$sdk = (string) getenv('AXIOM_SDK_IMAGE');
$binding = ImageBinding::inspect(
    Package::capture('/app/packages/reading-summary'),
    Supervisor::inspectImage($image),
    $sdk
);
$store = new PolicyStore('/tmp/load-policy.sqlite');
$store->register($binding);
for ($index = 1; $index <= 4; $index++) {
    $store->create('load-' . $index, 'site:' . $index, $image);
    $store->change('load-' . $index, 1, true, array('content.read'), $image);
}
unset($store);
mkdir('/tmp/load-admission', 0700);
$children = array();
try {
    for ($index = 1; $index <= 4; $index++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Load child could not start.');
        }
        if ($pid === 0) {
            try {
                $site = 'site:' . $index;
                $read = static function (string $requestedSite, string $id) use ($index): stdClass {
                    file_put_contents('/tmp/load-ready-' . $index, 'ready');
                    while (!file_exists('/tmp/load-release')) {
                        usleep(1000);
                        clearstatcache(true, '/tmp/load-release');
                    }
                    return (object) array('content_id' => $id, 'revision' => 'v1',
                        'title' => 'Controlled load fixture', 'text' => 'One two three four five.');
                };
                $supervisor = Supervisor::fromPolicy(
                    new PolicyStore('/tmp/load-policy.sqlite', true),
                    'load-' . $index,
                    $site,
                    static fn(string $actor, string $requestedSite, string $id): bool =>
                        $actor === 'load-editor' && $requestedSite === $site && $id === 'post:1',
                    $read,
                    '/tmp/load-admission'
                );
                $start = hrtime(true);
                $result = $supervisor->run('load-' . $index, $site, 'load-editor', 'post:1');
                if ($result->component->word_count !== 5) {
                    throw new RuntimeException('Loaded useful result mismatch.');
                }
                echo json_encode(array('kind' => 'worker', 'installation' => 'load-' . $index,
                    'loaded_ms' => round((hrtime(true) - $start) / 1_000_000, 3)), JSON_THROW_ON_ERROR) . "\n";
                exit(0);
            } catch (Throwable $error) {
                fwrite(STDERR, 'Loaded child failed: ' . $error->getMessage() . "\n");
                exit(1);
            }
        }
        $children[$pid] = true;
    }
    $deadline = hrtime(true) + 3_500_000_000;
    while (count(glob('/tmp/load-ready-*')) !== 4) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Loaded barrier deadline.');
        }
        usleep(1000);
    }
    $start = hrtime(true);
    try {
        (new AdmissionGate('/tmp/load-admission'))->acquire('excess', 'site:5');
        throw new LogicException('Global cap admitted excess work.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'admission_busy') {
            throw $error;
        }
    }
    echo json_encode(array('kind' => 'saturated', 'active' => 4, 'queue_capacity' => 0,
        'rejection_ms' => round((hrtime(true) - $start) / 1_000_000, 3)), JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    $deadline = hrtime(true) + 1_500_000_000;
    while (!file_exists('/tmp/load-release')) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Host responsiveness probe did not release workers.');
        }
        usleep(1000);
        clearstatcache(true, '/tmp/load-release');
    }
} finally {
    file_put_contents('/tmp/load-release', 'release');
    $deadline = hrtime(true) + 8_000_000_000;
    $failed = false;
    while ($children !== array()) {
        foreach (array_keys($children) as $pid) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                $failed = $failed || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
                unset($children[$pid]);
            }
        }
        if (hrtime(true) >= $deadline) {
            foreach (array_keys($children) as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            throw new RuntimeException('Loaded children cleanup deadline.');
        }
        usleep(1000);
    }
    if ($failed || Supervisor::remainingWorkers() !== array()) {
        throw new RuntimeException('Loaded worker result or cleanup failed.');
    }
}
echo json_encode(array('kind' => 'complete', 'outcome' => 'passed', 'image' => $image), JSON_THROW_ON_ERROR) . "\n";
