<?php

declare(strict_types=1);

namespace AxiomPress\Database;

use PDO;
use RuntimeException;

/** Deployment credentials are never loaded by src/axiompress/bootstrap.php. */
final class Connection
{
    public static function fromEnvironment(): PDO
    {
        $required = static function (string $key): string {
            $value = getenv($key);
            if ($value === false || $value === '') {
                throw new RuntimeException('Missing deployment database configuration.');
            }
            return $value;
        };
        $host = $required('AXIOM_PG_HOST');
        $database = $required('AXIOM_PG_DATABASE');
        $port = $required('AXIOM_PG_PORT');
        if (
            !preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D', $host)
            || !preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $database)
            || !preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) || (int) $port > 65535
        ) {
            throw new RuntimeException('Invalid deployment database target.');
        }
        $password = self::file($required('AXIOM_PG_PASSWORD_FILE'), 256);
        if (
            strlen($password) < 16 || str_contains($password, "\0") || str_contains($password, "\n")
            || str_contains($password, "\r")
        ) {
            throw new RuntimeException('Invalid deployment secret.');
        }
        $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';connect_timeout=3';
        $certificate = $required('AXIOM_PG_CA_FILE');
        self::file($certificate, 65536);
        $dsn .= ';sslmode=verify-full;sslrootcert=' . $certificate;
        return PDO::connect($dsn, 'axiom_deployer', $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false));
    }

    private static function file(string $path, int $limit): string
    {
        if (!preg_match('~\A/[a-zA-Z0-9/_.-]{1,255}\z~D', $path) || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Mounted deployment secret/certificate required.');
        }
        $stream = fopen($path, 're');
        if ($stream === false) {
            throw new RuntimeException('Deployment secret unavailable.');
        }
        try {
            $data = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($data === false || $data === '' || strlen($data) > $limit) {
            throw new RuntimeException('Deployment secret/certificate size limit.');
        }
        return $data;
    }
}
