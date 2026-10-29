<?php

declare(strict_types=1);

use AxiomPress\Core\ExtensionClient;
use AxiomPress\Core\Kernel;
use AxiomPress\Core\PublishedContentRepository;
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
$id = 9007199254740993;
$admin->beginTransaction();
$admin->exec("INSERT INTO axiom_access.reader_sites SELECT oid,2 FROM pg_roles WHERE rolname='reader_b';
    INSERT INTO axiom_content.articles(site_id,id,author_id,status,
        current_revision_id,published_revision_id,published_at)
    OVERRIDING SYSTEM VALUE VALUES
    (1,$id,1,'published',repeat('b',64),repeat('a',64),now()-interval '1 minute'),
    (2,$id,2,'published',repeat('c',64),repeat('c',64),now()-interval '1 minute'),
    (1," . ($id + 1) . ",1,'published',repeat('d',64),repeat('d',64),now()+interval '1 day'),
    (1," . ($id + 2) . ",1,'draft',repeat('e',64),NULL,NULL),
    (1," . ($id + 3) . ",1,'archived',repeat('f',64),NULL,NULL);
    INSERT INTO axiom_content.revisions(site_id,article_id,revision_id,version,title,body,created_by) VALUES
    (1,$id,repeat('a',64),1,'<b>Public title</b>','Public text 😀',1),
    (1,$id,repeat('b',64),2,'Private draft','Secret draft',1),
    (2,$id,repeat('c',64),1,'Other site','Other site body',2),
    (1," . ($id + 1) . ",repeat('d',64),1,'Future','Scheduled private',1),
    (1," . ($id + 2) . ",repeat('e',64),1,'Draft','Private',1),
    (1," . ($id + 3) . ",repeat('f',64),1,'Archived','Private',1)");
$admin->commit();
$connect = static fn(string $user, string $file): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents('/run/secrets/' . $file),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$db = $connect('reader_a', 'reader-a');
$repository = new PublishedContentRepository($db, 1);
$other = new PublishedContentRepository($connect('reader_b', 'reader-b'), 2);
$kernel = new Kernel(static fn(): PublishedContentRepository => $repository);
$path = '/v1/sites/1/content/' . $id;
$row = $repository->read(1, $id);
$check($row['site_id'] === '1' && $row['content_id'] === (string) $id, 'Lossless decimal string identifiers');
$check($row['revision'] === str_repeat('a', 64) && $row['text'] === 'Public text 😀', 'Published pointer only');
$check(count($row) === 5 && !isset($row['author_id'], $row['current_revision_id']), 'Exact public fields');
$check($row['title'] === '<b>Public title</b>', 'Plain source text preserved without rendering');
$check($repository->read(2, $id) === null, 'Configured site cannot be selected by caller');
$check($other->read(2, $id)['title'] === 'Other site', 'Other SQL login reads only its bound site');
$check(
    (new PublishedContentRepository($connect('reader_a', 'reader-a'), 2))->read(2, $id) === null,
    'Incorrect configured site does not override row policy'
);
foreach (array($id + 1, $id + 2, $id + 3, $id + 4, 0, -1) as $hidden) {
    $check($repository->read(1, $hidden) === null, 'Hidden/missing/invalid article returns no content');
}
$response = $kernel->handle('GET', $path);
$check($response->status === 200 && !str_contains($response->body(), '<b>'), 'Public JSON never emits source HTML');
$check($kernel->handle('HEAD', $path)->status === 200, 'HEAD follows same publication policy');
foreach (
    array($path . '?actor=owner', $path . '/', '/v1/sites/01/content/' . $id,
    '/v1/sites/1/content/0' . $id, '/v1/sites/2/content/' . $id) as $invalid
) {
    $check($kernel->handle('GET', $invalid)->status === 404, 'Route/tenant authority rejected');
}
$check($kernel->handle('POST', $path)->status === 405, 'Public endpoint is read only');
$check($kernel->handle('GET', str_repeat('x', 257))->status === 414, 'URI is bounded');
$check($db->query('SHOW default_transaction_read_only')->fetchColumn() === 'on', 'Read-only session defense');
foreach (
    array('PG_HOST' => 'postgres', 'PG_PORT' => '5432', 'PG_DATABASE' => 'axiom_auth_test',
    'PG_USER' => 'reader_a', 'PG_PASSWORD_FILE' => '/run/secrets/reader-a',
    'PG_CA_FILE' => '/run/secrets/ca.crt', 'SITE_ID' => '1') as $name => $value
) {
    putenv('AXIOM_PUBLIC_' . $name . '=' . $value);
}
$check(
    PublishedContentRepository::fromEnvironment()->read(1, $id)['revision'] === str_repeat('a', 64),
    'Actual mounted runtime configuration connects with verified TLS'
);
foreach (
    array('PG_HOST' => 'wrong-postgres', 'PG_CA_FILE' => '/run/secrets/wrong-ca.crt',
    'PG_PASSWORD_FILE' => '/run/secrets/reader-b', 'PG_DATABASE' => 'axiom_auth_test;sslmode=disable',
    'SITE_ID' => '0') as $name => $value
) {
    $previous = getenv('AXIOM_PUBLIC_' . $name);
    putenv('AXIOM_PUBLIC_' . $name . '=' . $value);
    try {
        PublishedContentRepository::fromEnvironment();
        throw new LogicException('Invalid public connection configuration accepted.');
    } catch (PDOException | RuntimeException $error) {
        $check(true, 'TLS/password/configuration fails closed');
    } finally {
        putenv('AXIOM_PUBLIC_' . $name . '=' . $previous);
    }
}
$db->beginTransaction();
try {
    $repository->read(1, $id);
    throw new LogicException('Stale transaction snapshot accepted.');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'Public reads require fresh autocommit snapshots.', 'No pooled stale snapshot');
} finally {
    $db->rollBack();
}
$admin->exec("UPDATE axiom_content.articles SET status='draft',published_revision_id=NULL,published_at=NULL
    WHERE site_id=1 AND id=$id");
$check($repository->read(1, $id) === null, 'Same connection sees committed withdrawal');
$admin->exec("UPDATE axiom_content.articles SET status='published',
    published_revision_id=repeat('b',64),published_at=now()
    WHERE site_id=1 AND id=$id");
$check($repository->read(1, $id)['title'] === 'Private draft', 'Fresh read sees explicitly republished draft');
$admin->exec("UPDATE axiom_content.revisions SET body=repeat('é',65536) WHERE site_id=1 AND article_id=$id
    AND revision_id=repeat('b',64)");
$check(strlen($repository->read(1, $id)['text']) === 131072, 'Exact UTF-8 byte limit reads safely');
$admin->exec("UPDATE axiom_content.revisions SET body='Republished' WHERE site_id=1 AND article_id=$id
    AND revision_id=repeat('b',64)");
$admin->exec("DELETE FROM axiom_access.reader_sites WHERE database_role='reader_a'::regrole::oid");
$check($repository->read(1, $id) === null, 'Revoked login scope denies next fresh read');
$admin->exec("INSERT INTO axiom_access.reader_sites SELECT oid,2 FROM pg_roles WHERE rolname='reader_a'");
$check(
    $repository->read(1, $id) === null && $repository->read(2, $id) === null,
    'Rebinding cannot change configured site'
);
$admin->exec("UPDATE axiom_access.reader_sites SET site_id=1 WHERE database_role='reader_a'::regrole::oid");
try {
    new PublishedContentRepository($connect('auth_a', 'auth-a'), 1);
    throw new LogicException('Authentication identity accepted for anonymous reads.');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'Restricted PostgreSQL public identity required.', 'Auth identity never fallback');
}
foreach (
    array('SELECT * FROM axiom_identity.credentials', 'SELECT current_revision_id FROM axiom_content.articles',
    'SELECT * FROM axiom_auth.sessions', 'SELECT * FROM axiom_content.command_receipts',
    'SELECT * FROM axiom_content.outbox') as $sql
) {
    try {
        $db->query($sql);
        throw new LogicException('Public login read private fields.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'Public SQL privileges deny private storage');
    }
}
// Turn off only the extra transaction defense to prove the actual SQL credential is not writable.
$db->exec('SET default_transaction_read_only=off');
try {
    $db->exec("UPDATE axiom_content.articles SET status='draft'");
    throw new LogicException('Public SQL identity obtained mutation authority.');
} catch (PDOException $error) {
    $check($error->getCode() === '42501', 'SQL grants enforce read-only independently');
} finally {
    $db->exec('SET default_transaction_read_only=on');
}
// Existing kernel must perform a fresh PostgreSQL check even after an optional extension fails.
$wrapped = new class ($repository, $admin, $id) {
    private int $calls = 0;
    public function __construct(private PublishedContentRepository $repository, private PDO $admin, private int $id)
    {
    }
    public function read(int $site, int $id): ?array
    {
        if (++$this->calls === 2) {
            $this->admin->exec("UPDATE axiom_content.articles SET status='draft',published_revision_id=NULL,
                published_at=NULL WHERE site_id=1 AND id=" . $this->id);
        }
        return $this->repository->read($site, $id);
    }
};
$decorated = new Kernel(static fn() => $wrapped, new ExtensionClient('/tmp/missing-public-test.sock', 'reader-test'));
$check($decorated->handle('GET', $path)->status === 404, 'Withdrawal during failed decoration never leaks snapshot');
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
