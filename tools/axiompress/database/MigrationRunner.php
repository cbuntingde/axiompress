<?php

declare(strict_types=1);

namespace AxiomPress\Database;

use PDO;
use RuntimeException;
use Throwable;

/** Deployment-only, forward-only migrations. Never load this runner in the public core. */
final readonly class MigrationRunner
{
    private const LOCK = '1096306770,1296648018';

    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('PostgreSQL deployment connection required.');
        }
        $version = (int) $db->query('SHOW server_version_num')->fetchColumn();
        if (
            $version < 180000 || $version >= 190000
            || $db->query('SELECT session_user')->fetchColumn() !== 'axiom_deployer'
            || $db->query('SELECT current_user')->fetchColumn() !== 'axiom_deployer'
        ) {
            throw new RuntimeException('PostgreSQL 18 and a dedicated axiom_deployer login required.');
        }
        if (
            (int) $db->query("SELECT count(*) FROM pg_roles WHERE
            rolname IN ('axiom_owner','axiom_deployer','axiom_reader') AND NOT rolsuper
            AND NOT rolinherit AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls
            AND rolcanlogin = (rolname='axiom_deployer')")->fetchColumn() !== 3
        ) {
            throw new RuntimeException('Role attributes differ from the deployment bootstrap contract.');
        }
        if ($db->inTransaction()) {
            throw new RuntimeException('Migration connection must be exclusively owned and outside a transaction.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Return only versions applied by this run; an already-current database returns an empty list. */
    public function migrate(string $directory): array
    {
        $files = self::snapshot($directory);
        if (!$this->db->query('SELECT pg_try_advisory_lock(' . self::LOCK . ')')->fetchColumn()) {
            throw new RuntimeException('migration_busy');
        }
        try {
            $this->db->exec("SET ROLE axiom_owner; SET search_path=pg_catalog; SET timezone='UTC';
                SET statement_timeout='30s'; SET lock_timeout='3s'; SET idle_in_transaction_session_timeout='30s';
                SET client_connection_check_interval='1s'");
            $this->prepareLedger();
            $records = $this->db->query('SELECT version, name, checksum FROM axiom_migrations.ledger
                ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($records as $index => $record) {
                if (
                    !isset($files[$index]) || (int) $record['version'] !== $index + 1
                    || $record['name'] !== $files[$index]['name']
                    || !hash_equals($record['checksum'], $files[$index]['checksum'])
                ) {
                    throw new RuntimeException('Applied migration history differs from the deployment snapshot.');
                }
            }
            $applied = array();
            foreach (array_slice($files, count($records)) as $file) {
                $this->db->beginTransaction();
                try {
                    // DO inside an explicit transaction makes embedded transaction control illegal
                    // at the database boundary, including controls hidden in dynamic SQL.
                    do {
                        $tag = '$axiom_' . bin2hex(random_bytes(12)) . '$';
                    } while (str_contains($file['sql'], $tag));
                    $quoted = $this->db->quote($file['sql']);
                    if ($quoted === false) {
                        throw new RuntimeException('Migration SQL quoting failed.');
                    }
                    $this->db->exec('DO ' . $tag . 'BEGIN EXECUTE ' . $quoted . '; END;' . $tag);
                    $statement = $this->db->prepare('INSERT INTO axiom_migrations.ledger(version,name,checksum)
                        VALUES (:version,:name,:checksum)');
                    $statement->execute(array('version' => $file['version'], 'name' => $file['name'],
                        'checksum' => $file['checksum']));
                    $this->db->commit();
                    $applied[] = $file['version'];
                } catch (Throwable $error) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $error;
                }
            }
            return $applied;
        } finally {
            try {
                $this->db->query('SELECT pg_advisory_unlock(' . self::LOCK . ')')->closeCursor();
            } finally {
                $this->db->exec('RESET ROLE');
            }
        }
    }

    private function prepareLedger(): void
    {
        $owner = $this->db->query("SELECT pg_get_userbyid(nspowner) FROM pg_namespace
            WHERE nspname='axiom_migrations'")->fetchColumn();
        if ($owner !== false) {
            if ($owner !== 'axiom_owner') {
                throw new RuntimeException('Unknown migration schema owner.');
            }
            return;
        }
        $this->db->beginTransaction();
        try {
            $this->db->exec("CREATE SCHEMA axiom_migrations AUTHORIZATION axiom_owner;
                REVOKE ALL ON SCHEMA axiom_migrations FROM PUBLIC;
                CREATE TABLE axiom_migrations.ledger (
                    version integer PRIMARY KEY CHECK(version>0), name text NOT NULL UNIQUE,
                    checksum text NOT NULL CHECK (octet_length(checksum)=64 AND checksum ~ '^[a-f0-9]{64}$'),
                    applied_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
                )");
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function snapshot(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Operator-owned migration directory required.');
        }
        $paths = glob($directory . '/*');
        if ($paths === false || $paths === array() || count($paths) > 100) {
            throw new RuntimeException('Expected one to 100 ordered migrations.');
        }
        sort($paths, SORT_STRING);
        $files = array();
        foreach ($paths as $index => $path) {
            $name = basename($path);
            if (
                !preg_match('/\A([0-9]{4})_[a-z0-9_]+\.sql\z/D', $name, $parts)
                || (int) $parts[1] !== $index + 1 || is_link($path) || !is_file($path)
            ) {
                throw new RuntimeException('Migration names must form a consecutive sequence starting at 0001.');
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Migration unavailable.');
            }
            try {
                $sql = stream_get_contents($stream, 1048577);
            } finally {
                fclose($stream);
            }
            if (
                $sql === false || $sql === '' || strlen($sql) > 1048576 || str_contains($sql, "\r")
                || str_contains($sql, "\0") || str_starts_with($sql, "\xef\xbb\xbf") || preg_match('//u', $sql) !== 1
            ) {
                throw new RuntimeException('Migration must be bounded UTF-8 text with LF line endings and no BOM.');
            }
            $files[] = array('version' => $index + 1, 'name' => $name,
                'checksum' => hash('sha256', $sql), 'sql' => $sql);
        }
        return $files;
    }
}
