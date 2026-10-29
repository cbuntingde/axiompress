<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use PDO;
use Pdo\Sqlite;
use RuntimeException;
use Throwable;

/** Supervisor-only local policy store, separate from the core CMS database. */
final class PolicyStore
{
    private PDO $db;

    public function __construct(string $path, private readonly bool $readOnly = false)
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_dir(dirname($path))) {
            throw new RuntimeException('Trusted absolute policy path required.');
        }
        $oldMask = umask(0077);
        try {
            $options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
            if ($readOnly) {
                $options[Sqlite::ATTR_OPEN_FLAGS] = Sqlite::OPEN_READONLY;
            }
            $this->db = new PDO('sqlite:' . $path, null, null, $options);
        } finally {
            umask($oldMask);
        }
        $this->db->exec('PRAGMA busy_timeout=2000; PRAGMA foreign_keys=ON');
        $version = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        if ($readOnly) {
            if ($version !== 1) {
                throw new RuntimeException('Unsupported read-only policy schema.');
            }
            if ($this->db->query('PRAGMA journal_mode')->fetchColumn() !== 'delete') {
                throw new RuntimeException('Operator preparation of DELETE policy journaling required.');
            }
            $this->db->exec('PRAGMA query_only=ON');
            return;
        }
        if (!in_array($version, array(0, 1), true)) {
            throw new RuntimeException('Unsupported policy schema.');
        }
        if ($version === 0) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                if ((int) $this->db->query('PRAGMA user_version')->fetchColumn() === 0) {
                    $tables = $this->db->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
                    if ((int) $tables !== 0) {
                        throw new RuntimeException('Refusing to initialize an unknown existing database.');
                    }
                    $this->db->exec('CREATE TABLE images (
                        image TEXT PRIMARY KEY, package_digest TEXT NOT NULL, manifest_digest TEXT NOT NULL,
                        plugin_id TEXT NOT NULL, version TEXT NOT NULL, sdk_image TEXT NOT NULL
                    ); CREATE TABLE installations (
                        installation TEXT PRIMARY KEY, site TEXT NOT NULL, image TEXT NOT NULL REFERENCES images(image),
                        generation INTEGER NOT NULL CHECK(generation > 0),
                        enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
                        read_grant INTEGER NOT NULL CHECK(read_grant IN (0,1))
                    ); PRAGMA user_version=1');
                }
                $this->db->exec('COMMIT');
            } catch (Throwable $error) {
                $this->db->exec('ROLLBACK');
                throw $error;
            }
        }
        // Rollback journaling permits truly read-only service mounts without writable WAL/SHM
        // sidecars. Never use immutable=1: it would bypass live revocation/locking visibility.
        if ($this->db->query('PRAGMA journal_mode=DELETE')->fetchColumn() !== 'delete') {
            throw new RuntimeException('Read-only-compatible policy journaling required.');
        }
        $this->db->exec('PRAGMA synchronous=FULL');
    }

    public function register(ImageBinding $binding): void
    {
        $this->writable();
        $values = array($binding->image, $binding->package->digest, $binding->package->manifestDigest,
            $binding->package->pluginId, $binding->package->version, $binding->sdkImage);
        $statement = $this->db->prepare('INSERT OR IGNORE INTO images VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute($values);
        $statement = $this->db->prepare('SELECT * FROM images WHERE image=?');
        $statement->execute(array($binding->image));
        if (array_values($statement->fetch(PDO::FETCH_ASSOC)) !== $values) {
            throw new RuntimeException('Immutable image binding conflict.');
        }
    }

    /** Installation identity/site are immutable. New entries are disabled and have no grants. */
    public function create(string $installation, string $site, string $image): void
    {
        $this->writable();
        self::identifier($installation);
        self::identifier($site);
        if (!preg_match('/\Asite:[1-9][0-9]*\z/D', $site)) {
            throw new RuntimeException('Canonical site identity required.');
        }
        $statement = $this->db->prepare('INSERT INTO installations VALUES (?, ?, ?, 1, 0, 0)');
        $statement->execute(array($installation, $site, $image));
    }

    public function change(
        string $installation,
        int $expectedGeneration,
        bool $enabled,
        array $grants,
        string $image
    ): int {
        $this->writable();
        if ($grants !== array() && $grants !== array('content.read')) {
            throw new RuntimeException('Unsupported installation grants.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->db->prepare('SELECT a.plugin_id FROM installations i
                JOIN images a ON a.image=i.image WHERE installation=?');
            $current->execute(array($installation));
            $plugin = $current->fetchColumn();
            $replacement = $this->db->prepare('SELECT plugin_id FROM images WHERE image=?');
            $replacement->execute(array($image));
            if ($plugin === false || $replacement->fetchColumn() !== $plugin) {
                throw new RuntimeException('Unapproved installation image/plugin identity.');
            }
            $statement = $this->db->prepare('UPDATE installations SET image=?, enabled=?, read_grant=?,
                generation=generation+1 WHERE installation=? AND generation=? AND generation < ?');
            $statement->execute(array($image, (int) $enabled, (int) ($grants !== array()),
                $installation, $expectedGeneration, PHP_INT_MAX));
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Stale or missing installation generation.');
            }
            $this->db->exec('COMMIT');
            return $expectedGeneration + 1;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function resolve(string $installation, string $site): array
    {
        $record = $this->describe($installation, $site);
        if ($record === null || (int) $record['enabled'] !== 1 || (int) $record['read_grant'] !== 1) {
            throw new RuntimeException('Installation is not authorized.');
        }
        return $record;
    }

    /** Operator inspection is not authorization; disabled records remain visible. */
    public function describe(string $installation, string $site): ?array
    {
        $statement = $this->db->prepare('SELECT i.* FROM installations i JOIN images a ON a.image=i.image
            WHERE installation=? AND site=?');
        $statement->execute(array($installation, $site));
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        return $record === false ? null : $record;
    }

    public function generation(string $installation, string $site, string $image): ?int
    {
        $statement = $this->db->prepare('SELECT i.generation FROM installations i JOIN images a ON a.image=i.image
            WHERE installation=? AND site=? AND i.image=? AND enabled=1 AND read_grant=1');
        $statement->execute(array($installation, $site, $image));
        $generation = $statement->fetchColumn();
        return $generation === false ? null : (int) $generation;
    }

    private static function identifier(string $value): void
    {
        if (strlen($value) > 128 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value)) {
            throw new RuntimeException('Invalid policy identifier.');
        }
    }
    private function writable(): void
    {
        if ($this->readOnly) {
            throw new RuntimeException('Policy reader cannot change approvals.');
        }
    }
}
