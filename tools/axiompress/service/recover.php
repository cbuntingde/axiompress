<?php

declare(strict_types=1);

use AxiomPress\Supervisor\AdmissionGate;
use AxiomPress\Supervisor\Supervisor;

require dirname(__DIR__) . '/policy/bootstrap.php';
require dirname(__DIR__) . '/supervisor/Supervisor.php';

// Caller holds the exclusive service-owner lock and has reaped all invocation handlers.
// Unknown/partial create intent requires daemon quiescence established by an operator.
$records = glob('/state/workers/*');
$pool = (string) getenv('AXIOM_SERVICE_POOL');
foreach ($records as $path) {
    if (
        is_link($path) || !is_file($path) || file_get_contents($path) !== 'created'
        || !preg_match('/\Aaxiompress-worker-[a-f0-9]{24}\z/D', basename($path))
    ) {
        throw new RuntimeException('Ambiguous worker creation; operator recovery required.');
    }
}
(new AdmissionGate('/state/admission'))->recover(static function () use ($records, $pool): void {
    Supervisor::reconcile($pool);
    if (Supervisor::remainingWorkers($pool) !== array()) {
        throw new RuntimeException('Worker cleanup is not confirmed.');
    }
    foreach ($records as $path) {
        if (!unlink($path)) {
            throw new RuntimeException('Journal recovery failed.');
        }
    }
});
