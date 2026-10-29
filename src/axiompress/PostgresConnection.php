<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use PDO;
use RuntimeException;

/** Runtime connections only: deployment tooling and its secrets remain separate. */
final class PostgresConnection
{
    public static function fromEnvironment(string $prefix): array
    {
        if (!in_array($prefix, array('AXIOM_AUTH_', 'AXIOM_PUBLIC_'), true)) {
            throw new RuntimeException('Invalid PostgreSQL configuration namespace.');
        }
        $get = static function (string $name) use ($prefix): string {
            $value = getenv($prefix . $name);
            if ($value === false || $value === '') {
                throw new RuntimeException('Missing PostgreSQL configuration.');
            }
            return $value;
        };
        $host = $get('PG_HOST');
        $database = $get('PG_DATABASE');
        $user = $get('PG_USER');
        $port = $get('PG_PORT');
        $site = $get('SITE_ID');
        if (
            !preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D', $host)
            || !preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $database)
            || !preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $user)
            || !preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) || (int) $port > 65535
            || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $site)
        ) {
            throw new RuntimeException('Invalid PostgreSQL configuration.');
        }
        $password = self::file($get('PG_PASSWORD_FILE'), 256);
        $certificate = $get('PG_CA_FILE');
        self::file($certificate, 65536);
        if (strlen($password) < 16 || strpbrk($password, "\r\n\0") !== false) {
            throw new RuntimeException('Invalid PostgreSQL secret.');
        }
        $db = PDO::connect(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database
            . ';connect_timeout=3;sslmode=verify-full;sslrootcert=' . $certificate,
            $user,
            $password,
            array(PDO::ATTR_PERSISTENT => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false)
        );
        return array($db, (int) $site);
    }

    private static function file(string $path, int $limit): string
    {
        if (!preg_match('~\A/[a-zA-Z0-9/_.-]{1,255}\z~D', $path) || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Mounted PostgreSQL secret/certificate required.');
        }
        $stream = fopen($path, 're');
        if ($stream === false) {
            throw new RuntimeException('PostgreSQL secret unavailable.');
        }
        try {
            $value = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($value === false || $value === '' || strlen($value) > $limit) {
            throw new RuntimeException('PostgreSQL secret/certificate size limit.');
        }
        return $value;
    }
}
