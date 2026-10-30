<?php

declare(strict_types=1);

use AxiomPress\Core\Auth\Store;
use AxiomPress\Core\Jobs\Runner;
use AxiomPress\Database\Connection;

require '/core/bootstrap.php';
require '/app/Connection.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$admin = Connection::fromEnvironment();
$admin->exec('SET ROLE axiom_owner');
$admin->exec("INSERT INTO axiom_jobs.runners SELECT oid,1 FROM pg_roles WHERE rolname='job_a';
    INSERT INTO axiom_jobs.runners SELECT oid,2 FROM pg_roles WHERE rolname='job_b'");
$connect = static fn(string $user, string $file): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents('/run/secrets/' . $file),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$db = $connect('job_a', 'job-a');
$runner = new Runner($db, 1);
$foreign = $connect('job_b', 'job-b');
new Runner($foreign, 2);
$auth = $connect('auth_a', 'auth-a');
$store = new Store($auth, 1);
$hash = $admin->query('SELECT password_hash FROM axiom_identity.credentials WHERE user_id=1')->fetchColumn();
$token = bin2hex(random_bytes(32));
$store->create(1, $hash, $token, '');
$key = static fn(): string => bin2hex(random_bytes(32));
$created = $store->content($token, 'create', '0', array('title' => 'Queued article', 'text' => 'Queue text'), $key());
$id = $created['id'];
$expected = static fn(array $r): array =>
    array('expected_revision' => $r['revision'], 'expected_version' => $r['version']);
$admin->exec("INSERT INTO axiom_jobs.subscriptions(site_id,article_id,installation,generation,enabled,expires_at)
    VALUES(1,$id,'service-reading',1,true,clock_timestamp()+interval '1 hour')");
$published = $store->content($token, 'publish', $id, $expected($created), $key());
$check(($published['status'] ?? '') === 'published', 'Job fixture publishes through real command');
$task = json_decode($db->query('SELECT axiom_jobs.claim()')->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
$check(isset($task['lease']) && $task['attempt'] === 1 && $task['site_id'] === '1', 'Durable bounded lease claimed');
$finish = static function (PDO $db, string $lease, string $outcome): array {
    $query = $db->prepare('SELECT axiom_jobs.finish(?,?)');
    $query->execute(array($lease, $outcome));
    return json_decode($query->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
};
$check(($finish($foreign, $task['lease'], 'delivered')['error'] ?? '') === 'conflict', 'Other site cannot ack lease');
$check(json_decode($db->query('SELECT axiom_jobs.claim()')->fetchColumn(), true) === array(), 'Leased task excluded');
$check($finish($db, $task['lease'], 'delivered')['outcome'] === 'delivered', 'Delivery persists in scoped inbox');
$check(($finish($db, $task['lease'], 'delivered')['error'] ?? '') === 'conflict', 'Lease replay cannot redeliver');
$check(
    (int) $admin->query("SELECT count(*) FROM axiom_jobs.inbox WHERE article_id=$id")->fetchColumn() === 1,
    'Inbox event deduplicated'
);
$check($runner->run()['delivered'] === 0, 'Polling does not duplicate committed outbox delivery');
$newEvent = static function () use ($admin, $id, $published): void {
    $admin->prepare("INSERT INTO axiom_content.outbox(site_id,article_id,revision_id,event)
        VALUES(1,?,?,'content.published.v1')")->execute(array($id, $published['revision']));
};
$claim = static fn(): array => json_decode($db->query('SELECT axiom_jobs.claim()')->fetchColumn(), true);
$newEvent();
$task = $claim();
$check($finish($db, $task['lease'], 'unavailable')['outcome'] === 'retry', 'Failed delivery becomes retry');
$check($claim() === array(), 'Backoff prevents immediate retry');
$admin->exec("UPDATE axiom_jobs.deliveries SET available_at=now()-interval '1 second' WHERE status='pending'");
$retried = $claim();
$check($retried['attempt'] === 2 && $retried['lease'] !== $task['lease'], 'Retry rotates lease/increments attempt');
$admin->exec("UPDATE axiom_jobs.subscriptions SET enabled=false WHERE article_id=$id");
$check($finish($db, $retried['lease'], 'delivered')['outcome'] === 'cancelled', 'Revocation rechecked on delivery');
$admin->exec("UPDATE axiom_jobs.subscriptions SET enabled=true,generation=2 WHERE article_id=$id");
$task = $claim();
$check($task !== array(), 'New explicit subscription generation has independent deliveries');
$admin->exec("UPDATE axiom_jobs.subscriptions SET generation=3 WHERE article_id=$id");
$check($finish($db, $task['lease'], 'delivered')['outcome'] === 'cancelled', 'Stale generation cannot deliver');
$tasks = array();
while (($task = $claim()) !== array()) {
    $tasks[] = $task;
}
$admin->exec("UPDATE axiom_content.articles SET status='draft',published_revision_id=NULL,published_at=NULL
    WHERE site_id=1 AND id=$id");
foreach ($tasks as $task) {
    $check($finish($db, $task['lease'], 'delivered')['outcome'] === 'cancelled', 'Withdrawn content cannot deliver');
}
$admin->exec("UPDATE axiom_content.articles SET status='published',published_revision_id=current_revision_id,
    published_at=now() WHERE site_id=1 AND id=$id");
$newEvent();
$task = $claim();
$admin->exec("UPDATE axiom_jobs.deliveries SET lease_until=now()-interval '1 second' WHERE status='leased'");
$check(($finish($db, $task['lease'], 'delivered')['error'] ?? '') === 'conflict', 'Expired lease cannot commit');
$check($claim() === array(), 'Disconnected worker lease recovers with backoff');
$admin->exec("UPDATE axiom_jobs.deliveries SET available_at=now()-interval '1 second' WHERE status='pending'");
$again = $claim();
$check($again['id'] === $task['id'] && $again['lease'] !== $task['lease'], 'Restart reclaims durable task safely');
$admin->exec("UPDATE axiom_jobs.deliveries SET attempts=10 WHERE status='leased'");
$check($finish($db, $again['lease'], 'unavailable')['outcome'] === 'dead', 'Attempt cap creates durable dead letter');
$check($claim() === array(), 'Dead letter does not loop');

$denied = array('SELECT * FROM axiom_jobs.subscriptions', 'SELECT * FROM axiom_jobs.inbox',
    'SELECT * FROM axiom_identity.credentials', "UPDATE axiom_content.articles SET status='draft'",
    'SET ROLE axiom_owner');
foreach ($denied as $sql) {
    try {
        $db->exec($sql);
        throw new LogicException('Unexpected job SQL privilege.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'Function-only job identity');
    }
}
try {
    $auth->query('SELECT axiom_jobs.claim()');
    throw new LogicException('Request identity claimed executor work.');
} catch (PDOException $error) {
    $check($error->getCode() === '42501', 'Request identity cannot become job executor');
}
$created = $store->content($token, 'create', '0', array('title' => 'Scheduled', 'text' => 'Later'), $key());
$intent = $expected($created) + array('due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600));
$scheduleKey = $key();
$scheduled = $store->content($token, 'schedule', $created['id'], $intent, $scheduleKey);
$check(isset($scheduled['scheduled_at']), 'Editor records typed publication intent');
$check(
    $store->content($token, 'schedule', $created['id'], $intent, $scheduleKey) === $scheduled,
    'Scheduled intent retry is idempotent'
);
$check($runner->run()['scheduled'] === 0, 'Scheduler never publishes before due time');
$admin->exec("UPDATE axiom_jobs.schedules SET due_at=now()-interval '1 second' WHERE status='pending'");
$check($runner->run()['scheduled'] === 1, 'Due intent publishes atomically without a persisted session');
$check($runner->run()['scheduled'] === 0, 'Scheduler restarts do not republish completed intent');
$created = $store->content($token, 'create', '0', array('title' => 'Revocable', 'text' => 'Later'), $key());
$store->content($token, 'schedule', $created['id'], $expected($created) + array('due_at' => $intent['due_at']), $key());
$admin->exec("UPDATE axiom_identity.memberships SET role='author' WHERE site_id=1 AND user_id=1;
    UPDATE axiom_jobs.schedules SET due_at=now()-interval '1 second' WHERE status='pending'");
$check($runner->run()['scheduled'] === 0, 'Scheduler reauthorizes current actor role');
$check($admin->query("SELECT status FROM axiom_jobs.schedules WHERE article_id=" . $created['id'])->fetchColumn()
    === 'forbidden', 'Revoked intent is durably denied');
$admin->exec("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1");
$newEvent();
$check($runner->run(1)['delivered'] === 1, 'Bounded executor drains committed event');
$admin->exec("DELETE FROM axiom_auth.attempts; DELETE FROM axiom_auth.sessions;
    UPDATE axiom_jobs.subscriptions SET enabled=false");
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
