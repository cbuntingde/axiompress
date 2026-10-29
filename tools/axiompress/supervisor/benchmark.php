<?php

declare(strict_types=1);

use AxiomPress\Policy\PolicyStore;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../policy/bootstrap.php';
require __DIR__ . '/Supervisor.php';

$installation = (string) getenv('AXIOM_INSTALLATION');
$policy = new PolicyStore('/policy/installations.sqlite', true);
$supervisor = Supervisor::fromPolicy(
    $policy,
    $installation,
    'site:1',
    static fn(string $actor, string $site, string $id): bool =>
        $actor === 'benchmark-editor' && $site === 'site:1' && $id === 'post:1',
    static fn(string $site, string $id): stdClass => (object) array(
        'content_id' => $id, 'revision' => 'benchmark-v1', 'title' => 'Declared five-word fixture',
        'text' => 'One two three four five.',
    )
);
$samples = array();
for ($index = 0; $index < 50; $index++) {
    $start = hrtime(true);
    $result = $supervisor->run($installation, 'site:1', 'benchmark-editor', 'post:1');
    if ($result->component->word_count !== 5 || $result->component->reading_seconds !== 2) {
        throw new RuntimeException('Benchmark useful result mismatch.');
    }
    $milliseconds = (hrtime(true) - $start) / 1_000_000;
    $samples[] = $milliseconds;
    echo json_encode(array('sample' => $index + 1, 'cold_ms' => round($milliseconds, 3)), JSON_THROW_ON_ERROR) . "\n";
}
sort($samples, SORT_NUMERIC);
$percentile = static fn(float $fraction): float => round($samples[(int) ceil(count($samples) * $fraction) - 1], 3);
if (Supervisor::remainingWorkers() !== array()) {
    throw new RuntimeException('Benchmark left workers behind.');
}
echo json_encode(
    array('outcome' => 'passed', 'samples' => count($samples), 'image' => $supervisor->workerImage(),
    'php' => PHP_VERSION, 'kernel' => php_uname('r'), 'runtime' => 'runc',
    'workload' => 'approved local reading-summary package, five-word broker fixture, sequential cold invocations',
    'p50_ms' => $percentile(0.50), 'p95_ms' => $percentile(0.95), 'p99_ms' => $percentile(0.99),
    'broker_peak_rss_kib' => getrusage()['ru_maxrss'], 'worker_memory_limit_bytes' => 134217728,
    'warm_pool' => false, 'queue_capacity' => 0,
    'limitations' => 'Broker RSS excludes workers. See separate worker-profile and loaded-core experiments; '
        . 'no SLO claim.'),
    JSON_THROW_ON_ERROR
) . "\n";
