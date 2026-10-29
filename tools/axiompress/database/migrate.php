<?php

declare(strict_types=1);

use AxiomPress\Database\Connection;
use AxiomPress\Database\MigrationRunner;

require __DIR__ . '/Connection.php';
require __DIR__ . '/MigrationRunner.php';

try {
    if (PHP_VERSION_ID < 80500 || PHP_INT_SIZE !== 8 || PHP_SAPI !== 'cli' || count($argv) > 2) {
        throw new RuntimeException('Use the 64-bit PHP 8.5 deployment CLI: migrate.php [migration-directory].');
    }
    $applied = (new MigrationRunner(Connection::fromEnvironment()))->migrate($argv[1] ?? __DIR__ . '/migrations');
    fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'applied' => $applied), JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'axiompress.database migration_failed class=' . get_debug_type($error)
        . ' diagnostic=' . bin2hex(random_bytes(12)) . "\n");
    exit(1);
}
