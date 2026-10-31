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
            if ($version !== 2) {
                throw new RuntimeException('Unsupported read-only policy schema.');
            }
            if ($this->db->query('PRAGMA journal_mode')->fetchColumn() !== 'delete') {
                throw new RuntimeException('Operator preparation of DELETE policy journaling required.');
            }
            $this->db->exec('PRAGMA query_only=ON');
            return;
        }
        if (!in_array($version, array(0, 1, 2), true)) {
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
                        plugin_id TEXT NOT NULL, version TEXT NOT NULL, sdk_image TEXT NOT NULL,
                        publisher TEXT REFERENCES publishers(key_id)
                    ); CREATE TABLE installations (
                        installation TEXT PRIMARY KEY, site TEXT NOT NULL, image TEXT NOT NULL REFERENCES images(image),
                        generation INTEGER NOT NULL CHECK(generation > 0),
                        enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
                        read_grant INTEGER NOT NULL CHECK(read_grant IN (0,1))
                    ); CREATE TABLE publishers (
                        key_id TEXT PRIMARY KEY, public_key TEXT NOT NULL,
                        revoked INTEGER NOT NULL DEFAULT 0 CHECK(revoked IN (0, 1))
                    ); PRAGMA user_version=2');
                }
                $this->db->exec('COMMIT');
            } catch (Throwable $error) {
                $this->db->exec('ROLLBACK');
                throw $error;
            }
        } elseif ($version === 1) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->db->exec('CREATE TABLE publishers (
                    key_id TEXT PRIMARY KEY, public_key TEXT NOT NULL,
                    revoked INTEGER NOT NULL DEFAULT 0 CHECK(revoked IN (0, 1)))');
                $this->db->exec('ALTER TABLE images ADD COLUMN publisher TEXT REFERENCES publishers(key_id)');
                $this->db->exec('PRAGMA user_version=2');
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

    public function addPublisher(string $publicKey): string
    {
        $this->writable();
        $keyId = PublisherApproval::keyIdFor($publicKey);
        $statement = $this->db->prepare('INSERT INTO publishers(key_id, public_key) VALUES(?, ?)');
        $statement->execute(array($keyId, $publicKey));
        return $keyId;
    }

    public function revokePublisher(string $keyId): void
    {
        $this->writable();
        if (!preg_match('/\A[a-f0-9]{64}\z/', $keyId)) {
            throw new RuntimeException('Unknown publisher key identifier.');
        }
        $statement = $this->db->prepare('UPDATE publishers SET revoked=1 WHERE key_id=?');
        $statement->execute(array($keyId));
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Unknown publisher key identifier.');
        }
    }

    /** @return array<int, array{key_id: string, revoked: int}> */
    public function listPublishers(): array
    {
        $rows = $this->db->query('SELECT key_id, revoked FROM publishers ORDER BY key_id')->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    }

    public function register(ImageBinding $binding, array $envelope): void
    {
        $this->writable();
        $approval = PublisherApproval::fromEnvelope($envelope);
        $keyId = $approval->keyId();
        $publisher = $this->db->prepare('SELECT public_key, revoked FROM publishers WHERE key_id=?');
        $publisher->execute(array($keyId));
        $record = $publisher->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('Unknown publisher key identifier.');
        }
        if ((int) $record['revoked'] !== 0) {
            throw new RuntimeException('Publisher key is revoked.');
        }
        if (
            openssl_verify(
                PublisherApproval::canonicalBytes($binding),
                $approval->signature(),
                $record['public_key'],
                OPENSSL_ALGO_SHA256
            ) !== 1
        ) {
            throw new RuntimeException('Publisher signature is invalid.');
        }
        $values = array($binding->image, $binding->package->digest, $binding->package->manifestDigest,
            $binding->package->pluginId, $binding->package->version, $binding->sdkImage, $keyId);
        $statement = $this->db->prepare('INSERT OR IGNORE INTO images VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute($values);
        $statement = $this->db->prepare('SELECT image, package_digest, manifest_digest, plugin_id,' .
            ' version, sdk_image, publisher FROM images WHERE image=?');
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
