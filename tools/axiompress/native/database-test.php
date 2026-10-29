<?php

declare(strict_types=1);

use AxiomPress\Core\Configuration;

require '/app/bootstrap.php';
$configuration = Configuration::fromEnvironment();
$db = PDO::connect(
    'mysql:host=' . $configuration->host . ';port=' . $configuration->port
    . ';dbname=' . $configuration->database . ';charset=utf8mb4',
    $configuration->user,
    $configuration->password(),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$checks = 0;
foreach (
    array(
    'SELECT * FROM wp_users LIMIT 1',
    'SELECT * FROM wp_posts LIMIT 1',
    'SELECT * FROM wp_options LIMIT 1',
    'UPDATE `' . $configuration->publicView . '` SET post_title=post_title WHERE ID=0',
    'DELETE FROM `' . $configuration->publicView . '` WHERE ID=0',
    'CREATE TABLE axiompress_forbidden_test (id INT)',
    ) as $sql
) {
    try {
        $db->query($sql);
        throw new LogicException('Native credential exceeded its SELECT-only view grant.');
    } catch (PDOException $error) {
        if ((int) $error->errorInfo[1] !== 1142) {
            throw $error;
        }
        $checks++;
    }
}
$count = $db->query('SELECT COUNT(*) FROM `' . $configuration->publicView . '` WHERE
    post_status <> \'publish\' OR post_password <> \'\' OR post_type NOT IN (\'post\', \'page\')
    OR post_date_gmt > UTC_TIMESTAMP()')->fetchColumn();
if ((int) $count !== 0) {
    throw new RuntimeException('Public projection contains nonpublic data.');
}
$checks++;
echo "PASS: $checks actual database privilege/projection checks\n";
