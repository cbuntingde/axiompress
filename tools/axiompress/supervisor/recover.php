<?php

declare(strict_types=1);

use AxiomPress\Supervisor\AdmissionGate;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../policy/bootstrap.php';
require __DIR__ . '/Supervisor.php';

// Operator entrypoint: needs the shared admission volume and Docker socket, never core credentials.
(new AdmissionGate('/admission'))->recover(Supervisor::reconcile(...));
echo "Recovered prototype worker namespace and admission capacity.\n";
