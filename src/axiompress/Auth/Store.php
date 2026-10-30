<?php

declare(strict_types=1);

namespace AxiomPress\Core\Auth;

use PDO;
use RuntimeException;

/** Core-owned authentication identity: function-only access, never deployment credentials. */
final readonly class Store
{
    public function __construct(private PDO $db, public int $site)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if (
            $site < 1 || $site > 9999999999 || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || (int) $db->query('SELECT axiom_auth.site()')->fetchColumn() !== $site
            || !$db->query("SELECT pg_has_role(session_user,'axiom_authenticator','USAGE')")->fetchColumn()
            || $db->query("SELECT pg_has_role(session_user,'axiom_owner','MEMBER')")->fetchColumn()
            || $db->query('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls
                FROM pg_roles WHERE rolname=session_user')->fetchColumn()
        ) {
            throw new RuntimeException('Invalid site authentication database identity.');
        }
        $db->exec("SET timezone='UTC'; SET statement_timeout='3s'; SET lock_timeout='1s'");
    }

    public static function fromEnvironment(): self
    {
        [$db, $site] = \AxiomPress\Core\PostgresConnection::fromEnvironment('AXIOM_AUTH_');
        return new self($db, $site);
    }

    public function beginLogin(string $username, string $peer): array
    {
        return $this->query('SELECT * FROM axiom_auth.begin_login(?,?)', array($username, hash('sha256', $peer)));
    }

    public function create(int $user, string $expectedHash, string $token, string $oldToken): array
    {
        return $this->query(
            'SELECT * FROM axiom_auth.create_session(?,?,?,?)',
            array($user, $expectedHash, hash('sha256', $token), hash('sha256', $oldToken))
        );
    }

    public function resolve(string $token): array
    {
        return $this->query('SELECT * FROM axiom_auth.resolve_session(?)', array(hash('sha256', $token)));
    }

    public function revoke(string $token): void
    {
        $this->query('SELECT axiom_auth.revoke_session(?)', array(hash('sha256', $token)));
    }

    public function content(string $token, string $operation, string $id, array $input, string $key): array
    {
        if ($operation === 'schedule' || $operation === 'cancel_schedule') {
            $parameters = array(hash('sha256', $token), $this->site, $id);
            $function = 'axiom_jobs.cancel(?,?,?)';
            if ($operation === 'schedule') {
                $function = 'axiom_jobs.schedule(?,?,?,?,?,?,?)';
                $parameters = array_merge($parameters, array(
                    $input['expected_revision'], $input['expected_version'], $input['due_at'], $key,
                ));
            }
            $row = $this->query('SELECT axiom_auth.site() AS site_id,' . $function . ' AS result', $parameters);
            return json_decode($row['result'], true, 8, JSON_THROW_ON_ERROR);
        }
        $row = $this->query('SELECT axiom_auth.site() AS site_id,
            axiom_content.command(?,?,?,?,?,?,?,?,?,?) AS result', array(
            hash('sha256', $token), $this->site, $operation, $id,
            $input['expected_revision'] ?? '', $input['expected_version'] ?? '0',
            $input['title'] ?? '', $input['text'] ?? '', $key, bin2hex(random_bytes(32)),
        ));
        return json_decode($row['result'], true, 8, JSON_THROW_ON_ERROR);
    }

    private function query(string $sql, array $parameters): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: array();
            if (isset($row['site_id']) && (int) $row['site_id'] !== $this->site) {
                throw new RuntimeException('Authentication site binding changed.');
            }
            return $row;
        } finally {
            $statement->closeCursor();
        }
    }

    public function media(string $token, string $operation, string $id, array $input, string $key): array
    {
        $sql = 'SELECT axiom_auth.site() AS site_id,axiom_media.command(?,?,?,?,?::jsonb,?) AS result';
        $row = $this->query($sql, array(
            hash('sha256', $token), $this->site, $operation, $id,
            json_encode($input === array() ? new \stdClass() : $input, JSON_THROW_ON_ERROR), $key,
        ));
        return json_decode($row['result'], true, 8, JSON_THROW_ON_ERROR);
    }

    public function platform(string $token, string $operation, array $input, string $key): array
    {
        $sql = 'SELECT axiom_auth.site() AS site_id,axiom_platform.command(?,?,?,?::jsonb,?) AS result';
        $row = $this->query($sql, array(hash('sha256', $token), $this->site, $operation,
            json_encode($input === array() ? new \stdClass() : $input, JSON_THROW_ON_ERROR), $key));
        return json_decode($row['result'], true, 8, JSON_THROW_ON_ERROR);
    }
}
