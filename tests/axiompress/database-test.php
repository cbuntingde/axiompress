<?php

declare(strict_types=1);

use AxiomPress\Database\Connection;
use AxiomPress\Database\MigrationRunner;

require '/app/Connection.php';
require '/app/MigrationRunner.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$db = Connection::fromEnvironment();
$runner = new MigrationRunner($db);
$foundation = '/tmp/axiompress-foundation-' . bin2hex(random_bytes(12));
mkdir($foundation, 0700);
copy('/app/migrations/0001_identity_content.sql', $foundation . '/0001_identity_content.sql');
try {
    $check($runner->migrate($foundation) === array(1), 'Initial migration');
    $check($runner->migrate($foundation) === array(), 'Already current');
} finally {
    unlink($foundation . '/0001_identity_content.sql');
    rmdir($foundation);
}
$check($db->query('SELECT current_user')->fetchColumn() === 'axiom_deployer', 'Role restored');
$db->exec('SET ROLE axiom_owner');
$db->beginTransaction();
$db->exec("INSERT INTO axiom_identity.sites(id,slug,name) OVERRIDING SYSTEM VALUE
    VALUES (1,'one','One'),(2,'two','Two');
    INSERT INTO axiom_identity.users(id,username,display_name) OVERRIDING SYSTEM VALUE
    VALUES (1,'alice','Alice'),(2,'bob','Bob');
    INSERT INTO axiom_identity.memberships VALUES (1,1,'owner'),(2,2,'owner');
    INSERT INTO axiom_access.reader_sites SELECT oid,1 FROM pg_roles WHERE rolname='reader_a';
    INSERT INTO axiom_access.reader_sites SELECT oid,2 FROM pg_roles WHERE rolname='reader_b'");
$credential = $db->prepare('INSERT INTO axiom_identity.credentials(user_id,password_hash) VALUES (1,?)');
$credential->execute(array(password_hash('fixture-only-not-an-enabled-login', PASSWORD_ARGON2ID)));
foreach (
    array(
    array(1, 1, 1, 'published', 'b', 'a', '-1 second'),
    array(1, 2, 1, 'draft', 'c', null, null),
    array(1, 3, 1, 'archived', 'd', null, null),
    array(1, 4, 1, 'published', 'e', 'e', '1 day'),
    array(2, 1, 2, 'published', 'f', 'f', '-1 second'),
    ) as [$site, $id, $author, $status, $current, $published, $interval]
) {
    $article = $db->prepare('INSERT INTO axiom_content.articles
        (site_id,id,author_id,status,current_revision_id,published_revision_id,published_at)
        OVERRIDING SYSTEM VALUE VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP + CAST(? AS interval))');
    $article->execute(array($site, $id, $author, $status, str_repeat($current, 64),
        $published === null ? null : str_repeat($published, 64), $interval));
    $revision = $db->prepare('INSERT INTO axiom_content.revisions
        (site_id,article_id,revision_id,version,title,body,created_by) VALUES (?,?,?,?,?,?,?)');
    $revision->execute(array($site, $id, str_repeat($current, 64), 2, 'Private current', 'Hidden draft', $author));
    if ($current !== $published && $published !== null) {
        $revision->execute(array($site, $id, str_repeat($published, 64), 1, 'Published', 'Public body', $author));
    }
}
$db->commit();
$db->exec('RESET ROLE');
$read = static function (string $user, string $secret): PDO {
    return PDO::connect(
        'pgsql:host=postgres;port=5432;dbname=axiom_domain;sslmode=verify-full;'
        . 'sslrootcert=/run/secrets/ca.crt;connect_timeout=3',
        $user,
        file_get_contents($secret),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false)
    );
};
$a = $read('reader_a', '/run/secrets/reader-a');
$b = $read('reader_b', '/run/secrets/reader-b');
$check($a->query('SELECT title FROM axiom_content.revisions')->fetchAll(PDO::FETCH_COLUMN)
    === array('Published'), 'Public reader sees only published revision');
$check((int) $b->query('SELECT site_id FROM axiom_content.articles')->fetchColumn() === 2, 'Other site reader');
$a->exec("SET axiom.site_id='2'; SET ROLE axiom_reader");
$check((int) $a->query('SELECT site_id FROM axiom_content.articles')->fetchColumn() === 1, 'Session claims ignored');
$a->exec('RESET ROLE');
$deny = static function (PDO $connection, string $sql, string $state = '42501') use ($check): void {
    $connection->beginTransaction();
    try {
        $connection->exec($sql);
        $connection->commit();
    } catch (PDOException $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        $check($error->getCode() === $state, 'Expected SQLSTATE ' . $state . ', got ' . $error->getCode());
        return;
    }
    throw new RuntimeException('Expected database denial.');
};
foreach (
    array(
    'SELECT * FROM axiom_identity.credentials', 'SELECT * FROM axiom_identity.users',
    'SELECT * FROM axiom_identity.memberships', 'SELECT * FROM axiom_access.reader_sites',
    'SELECT current_revision_id FROM axiom_content.articles', 'SELECT * FROM axiom_migrations.ledger',
    'SET ROLE axiom_owner', 'SET SESSION AUTHORIZATION reader_b',
    'CREATE TABLE public.forbidden(id int)', 'CREATE TEMP TABLE forbidden(id int)',
    "UPDATE axiom_content.articles SET status='draft'", 'ALTER TABLE axiom_content.articles DISABLE ROW LEVEL SECURITY',
    "SET row_security=off; SELECT id FROM axiom_content.articles",
    ) as $sql
) {
    $deny($a, $sql);
}
$db->exec('SET ROLE axiom_owner');
$deny($db, "UPDATE axiom_content.articles SET author_id=2 WHERE site_id=1 AND id=1", '23503');
$deny($db, "UPDATE axiom_content.articles SET current_revision_id=repeat('c',64) WHERE site_id=1 AND id=1", '23503');
$deny($db, "UPDATE axiom_content.revisions SET title=repeat('x',4097) WHERE site_id=1 AND article_id=1", '23514');
$deny($db, "UPDATE axiom_content.revisions SET body=repeat('λ',65537) WHERE site_id=1 AND article_id=1", '23514');
$db->exec("UPDATE axiom_content.articles SET status='draft',published_revision_id=NULL,published_at=NULL
    WHERE site_id=1 AND id=1");
$check((int) $a->query('SELECT count(*) FROM axiom_content.revisions')->fetchColumn() === 0, 'Withdrawal visible');
$db->exec('RESET ROLE');
$expectFailure = static function (Closure $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $check(true, $label);
        return;
    }
    throw new RuntimeException('Expected failure: ' . $label);
};
$expectFailure(static fn() => new MigrationRunner($a), 'Runtime reader cannot migrate');
$oldHost = getenv('AXIOM_PG_HOST');
putenv('AXIOM_PG_HOST=wrong-postgres');
$expectFailure(static fn() => Connection::fromEnvironment(), 'TLS rejects wrong hostname');
putenv('AXIOM_PG_HOST=' . $oldHost);
$oldCertificate = getenv('AXIOM_PG_CA_FILE');
putenv('AXIOM_PG_CA_FILE=/run/secrets/wrong-ca.crt');
$expectFailure(static fn() => Connection::fromEnvironment(), 'TLS rejects wrong CA');
putenv('AXIOM_PG_CA_FILE=' . $oldCertificate);
$wrong = file_get_contents('/run/secrets/reader-a');
try {
    PDO::connect(
        'pgsql:host=postgres;dbname=axiom_domain;connect_timeout=3;sslmode=verify-full;'
        . 'sslrootcert=/run/secrets/ca.crt',
        'reader_a',
        $wrong . 'wrong',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    throw new LogicException('Wrong password accepted.');
} catch (PDOException $error) {
    $check(true, 'SCRAM rejects wrong password');
}

$directory = '/tmp/axiompress-migrations-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
$source = file_get_contents('/app/migrations/0001_identity_content.sql');
file_put_contents($directory . '/0001_identity_content.sql', $source);
try {
    file_put_contents(
        $directory . '/0002_probe.sql',
        'CREATE TABLE axiom_content.rollback_probe(id integer); SELECT 1/0;'
    );
    $expectFailure(static fn() => $runner->migrate($directory), 'Failed migration rolls back');
    $db->exec('SET ROLE axiom_owner');
    $check(
        $db->query("SELECT to_regclass('axiom_content.rollback_probe')")->fetchColumn() === null,
        'Failed DDL leaves no relation'
    );
    $check(
        (int) $db->query('SELECT count(*) FROM axiom_migrations.ledger')->fetchColumn() === 1,
        'Failed DDL leaves no ledger row'
    );
    $db->exec('RESET ROLE');
    file_put_contents($directory . '/0002_probe.sql', 'CREATE TABLE axiom_content.rollback_probe(id integer);');
    $check($runner->migrate($directory) === array(2), 'Retry applies pending migration');
    $check($runner->migrate($directory) === array(), 'Retry does not apply twice');
    file_put_contents($directory . '/0001_identity_content.sql', $source . "\n-- changed\n");
    $expectFailure(static fn() => $runner->migrate($directory), 'Applied checksum cannot change');
    file_put_contents($directory . '/0001_identity_content.sql', $source);
    file_put_contents(
        $directory . '/0003_control.sql',
        'CREATE TABLE axiom_content.transaction_escape(id integer); COMMIT;'
    );
    $expectFailure(static fn() => $runner->migrate($directory), 'Migration cannot commit outside runner');
    $db->exec('SET ROLE axiom_owner');
    $check(
        $db->query("SELECT to_regclass('axiom_content.transaction_escape')")->fetchColumn() === null,
        'Embedded commit cannot leave partially committed DDL'
    );
    $db->exec('RESET ROLE');
    file_put_contents($directory . '/0003_control.sql', 'SELECT pg_sleep(2);');
    $process = proc_open(array('php', '/app/migrate.php', $directory), array(0 => array('pipe', 'r'),
        1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Concurrent migrator did not start.');
    }
    fclose($pipes[0]);
    try {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $held = (int) $db->query("SELECT count(*) FROM pg_locks WHERE locktype='advisory'
                AND classid=1096306770 AND objid=1296648018 AND granted AND pid<>pg_backend_pid()")->fetchColumn();
            if ($held === 0) {
                usleep(10000);
            }
        } while ($held === 0 && hrtime(true) < $deadline);
        $check($held === 1, 'Concurrent migrator holds actual database lock');
        try {
            $runner->migrate($directory);
            throw new LogicException('Concurrent migration admitted.');
        } catch (RuntimeException $error) {
            $check($error->getMessage() === 'migration_busy', 'Concurrent migration fails immediately');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        $check(
            $errors === '' && json_decode($output, true, 8, JSON_THROW_ON_ERROR)['applied'] === array(3),
            'Only one migrator applies version'
        );
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process, 9);
        proc_close($process);
    }
    $check($runner->migrate($directory) === array(), 'Advisory lock released after run');
    file_put_contents(
        $directory . '/0004_interrupt.sql',
        'CREATE TABLE axiom_content.interrupted_probe(id integer); SELECT pg_sleep(20);'
    );
    $process = proc_open(array('php', '/app/migrate.php', $directory), array(0 => array('pipe', 'r'),
        1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Interruptible migrator did not start.');
    }
    fclose($pipes[0]);
    try {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $active = (int) $db->query("SELECT count(*) FROM pg_stat_activity
                WHERE usename='axiom_deployer' AND pid<>pg_backend_pid() AND state='active'
                AND wait_event='PgSleep'")->fetchColumn();
            if ($active === 0) {
                usleep(10000);
            }
        } while ($active === 0 && hrtime(true) < $deadline);
        $check($active === 1, 'DDL transaction is executing before interruption');
        $check(proc_terminate($process, 9), 'Migrator process interrupted');
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process, 9);
        proc_close($process);
    }
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        $held = (int) $db->query("SELECT count(*) FROM pg_locks WHERE locktype='advisory'
            AND classid=1096306770 AND objid=1296648018 AND granted AND pid<>pg_backend_pid()")->fetchColumn();
        if ($held !== 0) {
            usleep(20000);
        }
    } while ($held !== 0 && hrtime(true) < $deadline);
    $check($held === 0, 'Disconnected migration releases database lock');
    $db->exec('SET ROLE axiom_owner');
    $check(
        $db->query("SELECT to_regclass('axiom_content.interrupted_probe')")->fetchColumn() === null,
        'Interrupted DDL rolls back'
    );
    $db->exec('RESET ROLE');
    file_put_contents($directory . '/0004_interrupt.sql', 'CREATE TABLE axiom_content.interrupted_probe(id integer);');
    $check($runner->migrate($directory) === array(4), 'Interrupted migration can be retried');
    unlink($directory . '/0004_interrupt.sql');
    unlink($directory . '/0003_control.sql');
    $expectFailure(static fn() => $runner->migrate($directory), 'Missing applied migration refuses downgrade');
    file_put_contents($directory . '/0003_control.sql', "SELECT pg_sleep(2);\r\n");
    $expectFailure(static fn() => $runner->migrate($directory), 'CRLF cannot change deployed bytes');
    unlink($directory . '/0003_control.sql');
    symlink('/app/migrations/0001_identity_content.sql', $directory . '/0003_control.sql');
    $expectFailure(static fn() => $runner->migrate($directory), 'Symlink migration rejected');
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks,
    'schema_sha256' => hash_file('sha256', '/app/migrations/0001_identity_content.sql'),
    'runner_sha256' => hash_file('sha256', '/app/MigrationRunner.php')), JSON_THROW_ON_ERROR) . "\n");
