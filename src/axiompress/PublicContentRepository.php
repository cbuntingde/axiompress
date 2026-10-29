<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use PDO;
use RuntimeException;

/** Reads a deployment-owned public projection, using a SELECT-only view credential. */
final readonly class PublicContentRepository
{
    public function __construct(private PDO $db, private string $view, private int $site)
    {
        if (!preg_match('/\Aaxiompress_public_[a-z0-9_]{1,32}\z/D', $view) || $site < 1) {
            throw new RuntimeException('Invalid repository scope.');
        }
    }

    public static function connect(Configuration $configuration): self
    {
        $db = PDO::connect(
            'mysql:host=' . $configuration->host . ';port=' . $configuration->port
                . ';dbname=' . $configuration->database . ';charset=utf8mb4',
            $configuration->user,
            $configuration->password(),
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 2)
        );
        // Deployment privileges are the write boundary; transaction mode is defense in depth.
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        $db->exec('SET SESSION MAX_EXECUTION_TIME=1000');
        return new self($db, $configuration->publicView, $configuration->site);
    }

    public function read(int $site, int $id): ?array
    {
        if ($site !== $this->site || $id < 1) {
            return null;
        }
        // Identifiers are deployment configuration validated above; values are native bound parameters.
        // Oversized values are never transferred into PHP, including from a misconfigured projection.
        $query = $this->db->prepare('SELECT ID, post_modified_gmt,
            CASE WHEN OCTET_LENGTH(post_title) <= 4096 THEN post_title ELSE NULL END AS title,
            CASE WHEN OCTET_LENGTH(post_content) <= 131072 THEN post_content ELSE NULL END AS body
            FROM `' . $this->view . '` WHERE ID = :id AND post_status = :status
                AND post_password = :password AND post_type IN (:post, :page)
                AND post_date_gmt <= :now LIMIT 1');
        $query->execute(array('id' => $id, 'status' => 'publish', 'password' => '',
            'post' => 'post', 'page' => 'page', 'now' => gmdate('Y-m-d H:i:s')));
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $query->closeCursor();
        if ($row === false) {
            return null;
        }
        if ($row['title'] === null || $row['body'] === null) {
            throw new ContentLimitExceeded();
        }
        $plain = static fn(string $value): string =>
            html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array('site_id' => $site, 'content_id' => $id,
            'revision' => hash('sha256', json_encode(array($site, $id, $row), JSON_THROW_ON_ERROR)),
            'title' => $plain($row['title']), 'text' => $plain($row['body']));
    }
}
