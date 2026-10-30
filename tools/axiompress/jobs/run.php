<?php

declare(strict_types=1);

use AxiomPress\Core\Jobs\Runner;

require '/app/bootstrap.php';

try {
    if (count($argv) > 2 || (isset($argv[1]) && !preg_match('/\A(?:[1-9][0-9]?|100)\z/D', $argv[1]))) {
        throw new RuntimeException('Use run.php [maximum-jobs:1..100].');
    }
    $result = Runner::fromEnvironment()->run(isset($argv[1]) ? (int) $argv[1] : 32);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Job executor unavailable; diagnostic=' . bin2hex(random_bytes(12)) . "\n");
    exit(1);
}
