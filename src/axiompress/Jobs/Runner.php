<?php

declare(strict_types=1);

namespace AxiomPress\Core\Jobs;

use AxiomPress\Core\PostgresConnection;
use PDO;
use RuntimeException;

/** Site-bound fixed delivery/scheduling executor. No callbacks, network clients or user cookies. */
final readonly class Runner
{
    public function __construct(private PDO $db, public int $site)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $site < 1 || $site > 9999999999) {
            throw new RuntimeException('Invalid job executor configuration.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if (
            (int) $db->query('SELECT axiom_jobs.site()')->fetchColumn() !== $site
            || !$db->query("SELECT pg_has_role(session_user,'axiom_job_runner','USAGE')")->fetchColumn()
            || $db->query("SELECT pg_has_role(session_user,'axiom_owner','MEMBER')
                OR pg_has_role(session_user,'axiom_deployer','MEMBER')
                OR pg_has_role(session_user,'axiom_authenticator','MEMBER')
                OR EXISTS(SELECT FROM pg_roles WHERE rolname IN(session_user,'axiom_job_runner')
                    AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls))")->fetchColumn()
        ) {
            throw new RuntimeException('Restricted site job executor identity required.');
        }
        $db->exec("SET timezone='UTC'; SET statement_timeout='3s'; SET lock_timeout='1s'");
    }

    public static function fromEnvironment(): self
    {
        [$db, $site] = PostgresConnection::fromEnvironment('AXIOM_JOBS_');
        return new self($db, $site);
    }

    public function run(int $maximum = 32): array
    {
        if ($maximum < 1 || $maximum > 100 || $this->db->inTransaction()) {
            throw new RuntimeException('Bounded autocommit job execution required.');
        }
        $summary = array('scheduled' => (int) $this->db->query('SELECT axiom_jobs.tick()')->fetchColumn(),
            'delivered' => 0, 'cancelled' => 0, 'retry' => 0, 'dead' => 0);
        $deadline = hrtime(true) + 20_000_000_000;
        for ($i = 0; $i < $maximum && hrtime(true) < $deadline; $i++) {
            $task = $this->query('SELECT axiom_jobs.claim()', array());
            if ($task === array()) {
                break;
            }
            $result = $this->query('SELECT axiom_jobs.finish(?,?)', array($task['lease'], 'delivered'));
            if (!isset($result['outcome']) || !array_key_exists($result['outcome'], $summary)) {
                throw new RuntimeException('Job lease changed or delivery failed.');
            }
            $summary[$result['outcome']]++;
        }
        return $summary;
    }

    private function query(string $sql, array $arguments): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        try {
            return json_decode($statement->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
        } finally {
            $statement->closeCursor();
        }
    }
}
