<?php

declare(strict_types=1);

use AxiomPress\Supervisor\AdmissionGate;
use AxiomPress\Supervisor\Supervisor;
use AxiomPress\Supervisor\DirectRuntime;

require dirname(__DIR__) . '/policy/bootstrap.php';
require dirname(__DIR__) . '/supervisor/Supervisor.php';

// Caller holds the exclusive service-owner lock and has reaped all invocation handlers.
// Unknown/partial create intent requires daemon quiescence established by an operator.
$stateDirectory = getenv('AXIOM_STATE_DIRECTORY') ?: '/state';
$direct = getenv('AXIOM_WORKER_BACKEND') === 'direct'
    ? new DirectRuntime((string) getenv('AXIOM_DIRECT_CONFIG')) : null;
$records = glob($stateDirectory . '/workers/*');
$pool = (string) getenv('AXIOM_SERVICE_POOL');
foreach ($records as $path) {
    if (
        is_link($path) || !is_file($path) || ($direct === null && file_get_contents($path) !== 'created')
        || !preg_match('/\Aaxiompress-worker-[a-f0-9]{24}\z/D', basename($path))
    ) {
        throw new RuntimeException('Ambiguous worker creation; operator recovery required.');
    }
}
(new AdmissionGate($stateDirectory . '/admission'))->recover(static function () use ($records, $pool, $direct): void {
    if ($direct !== null) {
        $direct->recover();
    } else {
        Supervisor::reconcile($pool);
        if (Supervisor::remainingWorkers($pool) !== array()) {
            throw new RuntimeException('Worker cleanup is not confirmed.');
        }
    }
    foreach ($records as $path) {
        if (!unlink($path)) {
            throw new RuntimeException('Journal recovery failed.');
        }
    }
});
