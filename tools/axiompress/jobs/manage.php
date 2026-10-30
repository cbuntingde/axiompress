<?php

declare(strict_types=1);

use AxiomPress\Database\Connection;

require dirname(__DIR__) . '/database/Connection.php';

try {
    $operation = $argv[1] ?? '';
    $counts = array('describe' => 5, 'disable' => 6, 'subscribe' => 7);
    if (!isset($counts[$operation]) || count($argv) !== $counts[$operation]) {
        throw new RuntimeException('Use describe SITE ARTICLE INSTALL, disable ... GENERATION, '
            . 'subscribe ... GENERATION TTL.');
    }
    if (
        !preg_match('/\A[1-9][0-9]{0,9}\z/D', $argv[2])
        || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $argv[3])
        || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $argv[4])
        || ($operation !== 'describe' && !preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $argv[5]))
        || ($operation === 'subscribe' && (!ctype_digit($argv[6]) || (int) $argv[6] < 60 || (int) $argv[6] > 86400))
    ) {
        throw new RuntimeException('Invalid operator subscription arguments.');
    }
    $db = Connection::fromEnvironment();
    $db->beginTransaction();
    $db->exec('SET LOCAL ROLE axiom_owner');
    $lock = $db->prepare("SELECT pg_advisory_xact_lock(hashtextextended('axiom-auth:'||?,0))");
    $lock->execute(array($argv[2]));
    $read = $db->prepare('SELECT * FROM axiom_jobs.subscriptions WHERE site_id=? AND article_id=? AND installation=?
        FOR UPDATE');
    $read->execute(array($argv[2], $argv[3], $argv[4]));
    $record = $read->fetch(PDO::FETCH_ASSOC);
    if ($operation !== 'describe') {
        if (($record['generation'] ?? 0) != $argv[5] || ($operation === 'disable' && $record === false)) {
            throw new RuntimeException('Subscription generation conflict.');
        }
        $count = $db->prepare('SELECT count(*) FROM axiom_jobs.subscriptions WHERE site_id=?');
        $count->execute(array($argv[2]));
        if ($record === false && (int) $count->fetchColumn() >= 128) {
            throw new RuntimeException('Subscription capacity exceeded.');
        }
        $write = $db->prepare('INSERT INTO axiom_jobs.subscriptions
            (site_id,article_id,installation,generation,enabled,created_at,expires_at)
            VALUES(?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP+make_interval(secs=>?))
            ON CONFLICT(site_id,article_id,installation) DO UPDATE SET generation=excluded.generation,
            enabled=excluded.enabled,created_at=excluded.created_at,expires_at=excluded.expires_at');
        $write->execute(array($argv[2], $argv[3], $argv[4], (int) $argv[5] + 1,
            $operation === 'subscribe' ? 'true' : 'false', $operation === 'subscribe' ? $argv[6] : '60'));
        $read->execute(array($argv[2], $argv[3], $argv[4]));
        $record = $read->fetch(PDO::FETCH_ASSOC);
    }
    $db->commit();
    fwrite(STDOUT, json_encode($record ?: array(), JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Subscription operation failed; diagnostic=' . bin2hex(random_bytes(12)) . "\n");
    exit(1);
}
