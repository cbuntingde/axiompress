<?php

declare(strict_types=1);

use AxiomPress\Core\ExtensionClient;
use AxiomPress\Core\Kernel;
use AxiomPress\Core\PublicContentRepository;

require '/core/bootstrap.php';

$db = PDO::connect('sqlite:' . $argv[1], null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->createFunction('OCTET_LENGTH', static fn(string $value): int => strlen($value), 1);
$db->exec('CREATE TABLE axiompress_public_test (ID INTEGER PRIMARY KEY, post_title TEXT,
    post_content TEXT, post_status TEXT, post_password TEXT, post_type TEXT,
    post_modified_gmt TEXT, post_date_gmt TEXT)');
$db->exec("INSERT INTO axiompress_public_test VALUES (1, 'Public', 'One two three',
    'publish', '', 'post', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$repository = new PublicContentRepository($db, 'axiompress_public_test', 1);
$kernel = new Kernel(
    static fn(): PublicContentRepository => $repository,
    new ExtensionClient('/channel/case.sock', 'service-reading')
);
$response = $kernel->handle('GET', '/v1/sites/1/content/1');
echo json_encode(array('status' => $response->status, 'data' => $response->data), JSON_THROW_ON_ERROR);
