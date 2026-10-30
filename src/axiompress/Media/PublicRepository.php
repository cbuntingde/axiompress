<?php

declare(strict_types=1);

namespace AxiomPress\Core\Media;

use AxiomPress\Core\ImageResponse;
use AxiomPress\Core\PostgresConnection;
use AxiomPress\Core\PublishedContentRepository;
use AxiomPress\Core\Response;
use PDO;

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

    public function read(int $site, string $id): Response|ImageResponse
    {
        if ($site !== $this->site || !preg_match('/\A[a-f0-9]{32}\z/D', $id) || $this->db->inTransaction()) {
            return new Response(404, array('error' => 'not_found'));
        }
        $statement = $this->db->prepare('SELECT axiom_media.public_file(?,?)');
        $statement->execute(array($site, $id));
        try {
            $data = $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
        return is_string($data) ? new ImageResponse(base64_decode($data, true))
            : new Response(404, array('error' => 'not_found'));
    }
}
