<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use PDO;
use RuntimeException;

/** Anonymous PostgreSQL reader. Authentication/command credentials are never used here. */
final readonly class PublishedContentRepository
{
    public function __construct(private PDO $db, private int $site)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $site < 1 || $site > 9999999999) {
            throw new RuntimeException('Invalid PostgreSQL public repository scope.');
        }
        $identity = $db->query("SELECT
            pg_has_role(session_user,'axiom_reader','USAGE') AS reader,
            pg_has_role(session_user,'axiom_owner','MEMBER')
                OR pg_has_role(session_user,'axiom_deployer','MEMBER')
                OR pg_has_role(session_user,'axiom_authenticator','MEMBER') AS privileged,
            EXISTS (SELECT FROM pg_roles WHERE rolname IN (session_user,'axiom_reader')
                AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) AS elevated")
            ->fetch(PDO::FETCH_ASSOC);
        if (!$identity['reader'] || $identity['privileged'] || $identity['elevated']) {
            throw new RuntimeException('Restricted PostgreSQL public identity required.');
        }
        $db->exec("SET timezone='UTC'; SET statement_timeout='3s'; SET lock_timeout='1s';
            SET default_transaction_read_only=on");
    }

    public static function fromEnvironment(): self
    {
        [$db, $site] = PostgresConnection::fromEnvironment('AXIOM_PUBLIC_');
        return new self($db, $site);
    }

    public function read(int $site, int $id): ?array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Public reads require fresh autocommit snapshots.');
        }
        if ($site !== $this->site || $id < 1 || $id > 999999999999999999) {
            return null;
        }
        // Only granted public columns appear here; forced RLS also enforces publication/login-OID scope.
        // Autocommit gives each call a fresh statement snapshot, including post-extension rechecks.
        $statement = $this->db->prepare('SELECT r.revision_id,
            CASE WHEN octet_length(r.title)<=4096 THEN r.title ELSE NULL END AS title,
            CASE WHEN octet_length(r.body)<=131072 THEN r.body ELSE NULL END AS body
            FROM axiom_content.articles a JOIN axiom_content.revisions r
                ON r.site_id=a.site_id AND r.article_id=a.id AND r.revision_id=a.published_revision_id
            WHERE a.site_id=? AND a.id=? AND a.published_at<=statement_timestamp()');
        $statement->execute(array($site, $id));
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if ($row === false) {
            return null;
        }
        if ($row['title'] === null || $row['body'] === null) {
            throw new ContentLimitExceeded();
        }
        return array('site_id' => (string) $site, 'content_id' => (string) $id,
            'revision' => $row['revision_id'], 'title' => $row['title'], 'text' => $row['body']);
    }
}
