<?php

declare(strict_types=1);

namespace AxiomPress\Core\Platform;

use AxiomPress\Core\PostgresConnection;
use AxiomPress\Core\PublishedContentRepository;
use PDO;
use RuntimeException;

final readonly class PublicRepository
{
    public function __construct(private PDO $db, private int $site)
    {
        new PublishedContentRepository($db, $site);
    }

    public static function fromEnvironment(): self
    {
        [$db, $site] = PostgresConnection::fromEnvironment('AXIOM_PUBLIC_');
        return new self($db, $site);
    }

    public function settings(int $site): ?array
    {
        return $this->query($site, 'SELECT axiom_platform.public_settings(?)', array($site));
    }

    public function articles(int $site, int $after = 0): array
    {
        return $this->query($site, 'SELECT axiom_platform.public_articles(?,?)', array($site, $after)) ?? array();
    }

    public function terms(int $site, int $id): array
    {
        return $this->query($site, 'SELECT axiom_platform.public_terms(?,?)', array($site, $id)) ?? array();
    }

    public function media(int $site, string $id): ?array
    {
        return $this->query($site, 'SELECT axiom_platform.public_media(?,?)', array($site, $id));
    }

    private function query(int $site, string $sql, array $arguments): ?array
    {
        if ($site !== $this->site) {
            return null;
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Fresh public configuration snapshot required.');
        }
        $query = $this->db->prepare($sql);
        $query->execute($arguments);
        try {
            $json = $query->fetchColumn();
        } finally {
            $query->closeCursor();
        }
        return is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
    }
}
