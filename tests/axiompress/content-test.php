<?php

declare(strict_types=1);

use AxiomPress\Core\Auth\Store;
use AxiomPress\Core\Content\Kernel;
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
$connect = static fn(string $user, string $secret): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents('/run/secrets/' . $secret),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$db = $connect('auth_a', 'auth-a');
$store = new Store($db, 1);
$other = new Store($connect('auth_b', 'auth-b'), 2);
$hash = $admin->query('SELECT password_hash FROM axiom_identity.credentials WHERE user_id=1')->fetchColumn();
$token = bin2hex(random_bytes(32));
$store->create(1, $hash, $token, '');
$kernel = new Kernel(static fn(): Store => $store, 'https://localhost');
$csrf = hash_hmac('sha256', 'axiompress.csrf.v1', $token);
$cookie = '__Host-axiom_session=' . $token;
$request = static function (
    string $method,
    string $path,
    ?array $input = null,
    ?string $key = null,
    ?string $requestCookie = null,
    ?string $requestCsrf = null,
    string $origin = 'https://localhost'
) use (
    $kernel,
    $cookie,
    $csrf
): AxiomPress\Core\Response {
    return $kernel->handle(
        $method,
        $path,
        $input === null ? '' : json_encode($input, JSON_THROW_ON_ERROR),
        $requestCookie ?? $cookie,
        $origin,
        'application/json',
        $requestCsrf ?? $csrf,
        $key ?? bin2hex(random_bytes(32)),
        true
    );
};
$input = array('title' => 'First title', 'text' => '<script>plain stored text</script>');
$key = bin2hex(random_bytes(32));
$create = $request('POST', '/v1/articles', $input, $key);
$check($create->status === 201, 'Author creates draft');
$id = $create->data['id'];
$path = '/v1/articles/' . $id;
$check($create->data['site_id'] === '1' && $create->data['version'] === '1', 'String identifiers/version');
$check($request('POST', '/v1/articles', $input, $key)->data === $create->data, 'Create retry returns same result');
$check((int) $admin->query('SELECT count(*) FROM axiom_content.articles')->fetchColumn() === 1, 'No duplicate create');
$check(
    $request('POST', '/v1/articles', array('title' => 'Changed', 'text' => ''), $key)->status === 409,
    'Same key with different request conflicts'
);
$read = $request('GET', $path);
$check($read->status === 200 && $read->data['text'] === $input['text'], 'Own draft read');
$check(!str_contains($read->body(), '<script>'), 'JSON safely encodes plain content');
$check($request('GET', $path, requestCookie: '')->status === 401, 'Anonymous private read denied');
$check($request('POST', '/v1/articles', $input, requestCsrf: '')->status === 403, 'Mutation requires CSRF');
$check($request('POST', '/v1/articles', $input, origin: 'https://evil.invalid')->status === 403, 'Mutation origin');
$check($request('POST', '/v1/articles', $input, key: '')->status === 400, 'Mutation requires idempotency key');
$check($request('POST', '/v1/articles', $input + array('actor' => 'owner'))->status === 400, 'Actor injection');
$check(
    $request('POST', '/v1/articles', array('title' => str_repeat('x', 4097), 'text' => ''))->status === 413,
    'Title byte limit'
);
$check(
    $request('POST', '/v1/articles', array('title' => '', 'text' => str_repeat('é', 65537)))->status === 413,
    'Multibyte body limit'
);
$check(
    $request('POST', '/v1/articles', array('title' => '', 'text' => str_repeat('é', 65536)))->status === 201,
    'Exact multibyte body byte limit accepted'
);
$check($request('GET', $path . '?site=2')->status === 404, 'Query authority rejected');
$check($request('DELETE', $path)->status === 405, 'No implicit delete command');
$check(
    $kernel->handle(
        'POST',
        '/v1/articles',
        '{"title":"x","title":"y","text":""}',
        $cookie,
        'https://localhost',
        'application/json',
        $csrf,
        bin2hex(random_bytes(32)),
        true
    )->status === 400,
    'Duplicate JSON keys rejected'
);
$expected = static fn(array $result): array => array(
    'expected_revision' => $result['revision'], 'expected_version' => $result['version']
);
$state = $create->data;
$check($request('POST', $path . '/publish', $expected($state))->status === 403, 'Author cannot publish');
$foreignToken = bin2hex(random_bytes(32));
$other->create(2, $hash, $foreignToken, '');
$check(
    ($other->content($foreignToken, 'read', $id, array(), '')['error'] ?? '') === 'not_found',
    'Cross-site object existence hidden'
);
$admin->exec("INSERT INTO axiom_identity.users(id,username,display_name) OVERRIDING SYSTEM VALUE
    VALUES(3,'charlie','Charlie'); INSERT INTO axiom_identity.memberships VALUES(1,3,'author',NULL)");
$admin->prepare('INSERT INTO axiom_identity.credentials VALUES(3,?,now())')->execute(array($hash));
$charlie = bin2hex(random_bytes(32));
$store->create(3, $hash, $charlie, '');
$check(
    ($store->content($charlie, 'read', $id, array(), '')['error'] ?? '') === 'not_found',
    'Author cannot read another author draft'
);
$check(($store->content($charlie, 'edit', $id, $expected($state) + $input, bin2hex(random_bytes(32)))['error'] ?? '')
    === 'not_found', 'Author cannot edit another author');
$admin->exec("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1;
    INSERT INTO axiom_access.reader_sites SELECT oid,1 FROM pg_roles WHERE rolname='reader_a'");
$reader = $connect('reader_a', 'reader-a');
$public = static fn(): array => $reader->query('SELECT revision_id,title,body FROM axiom_content.revisions')
    ->fetchAll(PDO::FETCH_ASSOC);
$check($public() === array(), 'Draft invisible to public database role');
$publishKey = bin2hex(random_bytes(32));
$published = $request('POST', $path . '/publish', $expected($state), $publishKey);
$check($published->status === 200 && $published->data['status'] === 'published', 'Editor publishes');
$check(count($public()) === 1 && $public()[0]['title'] === $input['title'], 'Public role sees published revision');
$check(
    $request('POST', $path . '/publish', $expected($state), $publishKey)->data === $published->data,
    'Publication retry replays without event duplication'
);
$check((int) $admin->query('SELECT count(*) FROM axiom_content.outbox')->fetchColumn() === 1, 'One publication event');
$state = $published->data;
$edit = $expected($state) + array('title' => 'New draft', 'text' => 'New private text');
$edited = $request('PUT', $path, $edit);
$check($edited->status === 200 && $edited->data['version'] === '3', 'Edit advances version');
$check($public()[0]['title'] === $input['title'], 'Editing preserves earlier public revision');
$check($request('GET', $path)->data['title'] === 'New draft', 'Private read sees current revision');
$check($request('PUT', $path, $edit)->status === 409, 'Stale edit conflicts');
$check($request('POST', $path . '/publish', $expected($state))->status === 409, 'Stale publication conflicts');
$state = $edited->data;
$published = $request('POST', $path . '/publish', $expected($state));
$check($published->status === 200 && $public()[0]['title'] === 'New draft', 'Republish selects current draft');
$check(
    $request('POST', $path . '/publish', $expected($published->data))->status === 409,
    'New key cannot republish identical public revision'
);
$newDraft = $request('PUT', $path, $expected($published->data) + array('title' => 'Later draft', 'text' => 'Later'));
$unpublished = $request('POST', $path . '/unpublish', $expected($newDraft->data));
$check($unpublished->status === 200 && $public() === array(), 'Unpublish withdraws public content');
$check($request('GET', $path)->data['title'] === 'Later draft', 'Unpublish preserves private revision');
$check($admin->query("SELECT revision_id FROM axiom_content.outbox WHERE event='content.unpublished.v1'")
    ->fetchColumn() === $published->data['revision'], 'Withdrawal event identifies withdrawn public revision');
$check(
    $request('POST', $path . '/unpublish', $expected($unpublished->data))->status === 409,
    'Already private article cannot emit withdrawal'
);
$admin->exec("UPDATE axiom_identity.memberships SET role='author' WHERE site_id=1 AND user_id=1");
$check(
    $request('POST', $path . '/publish', $expected($state), $publishKey)->status === 403,
    'Replay reauthorizes role before outcome'
);
$admin->exec('UPDATE axiom_content.articles SET author_id=3 WHERE site_id=1 AND id=' . $id);
$check($request('POST', '/v1/articles', $input, $key)->status === 404, 'Create replay reauthorizes original resource');
$admin->exec('UPDATE axiom_content.articles SET author_id=1 WHERE site_id=1 AND id=' . $id);
$admin->exec("UPDATE axiom_content.command_receipts SET created_at=now()-interval '8 days'");
$check($request('POST', '/v1/articles', $input, $key)->status === 409, 'Expired receipt tombstone denies reuse');
$admin->exec('UPDATE axiom_content.command_receipts SET created_at=now()');

// Independent clients exercise serialization and a revocation committed while a command waits.
$child = <<<'PHP'
require '/core/bootstrap.php';
$db=PDO::connect('pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    'auth_a',file_get_contents('/run/secrets/auth-a'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$store=new AxiomPress\Core\Auth\Store($db,1);
echo $db->query('SELECT pg_backend_pid()')->fetchColumn()."\n"; flush();
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
echo json_encode($store->content(...$input),JSON_THROW_ON_ERROR)."\n";
PHP;
$spawn = static function (array $arguments) use ($child): array {
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $child),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Content concurrency client unavailable.');
    }
    stream_set_timeout($pipes[1], 10);
    $pid = (int) fgets($pipes[1]);
    fwrite($pipes[0], json_encode($arguments, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    return array($process, $pipes, $pid);
};
$finish = static function (array $client): array {
    [$process, $pipes] = $client;
    try {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    if (proc_close($process) !== 0 || $errors !== '') {
        throw new RuntimeException('Content concurrency client failed.');
    }
    return json_decode($output, true, 8, JSON_THROW_ON_ERROR);
};
$state = $request('GET', $path)->data;
$arguments = array($token, 'edit', $id, $expected($state) + $input, bin2hex(random_bytes(32)));
$one = $spawn($arguments);
$arguments[4] = bin2hex(random_bytes(32));
$two = $spawn($arguments);
$results = array($finish($one), $finish($two));
$check(
    count(array_filter($results, static fn(array $r): bool => isset($r['id']))) === 1
    && count(array_filter($results, static fn(array $r): bool => ($r['error'] ?? '') === 'conflict')) === 1,
    'Competing clients have exactly one committed winner'
);
$state = $request('GET', $path)->data;
$admin->beginTransaction();
$admin->exec("SELECT pg_advisory_xact_lock(hashtextextended('axiom-auth:1',0))");
$waiting = $spawn(array($token, 'edit', $id, $expected($state) + $input, bin2hex(random_bytes(32))));
try {
    $blocked = false;
    for ($i = 0; $i < 100; $i++) {
        $blocked = (bool) $admin->query('SELECT EXISTS(SELECT FROM pg_locks WHERE NOT granted AND pid='
            . $waiting[2] . " AND locktype='advisory')")->fetchColumn();
        if ($blocked) {
            break;
        }
        usleep(20000);
    }
    $check($blocked, 'Command actually waits for authorization lock');
    $admin->exec('UPDATE axiom_identity.memberships SET disabled_at=now() WHERE site_id=1 AND user_id=1');
    $admin->commit();
} finally {
    if ($admin->inTransaction()) {
        $admin->rollBack();
    }
}
$check(($finish($waiting)['error'] ?? '') === 'unauthenticated', 'Waiting command observes committed revocation');
$admin->exec('UPDATE axiom_identity.memberships SET disabled_at=NULL WHERE site_id=1 AND user_id=1');
$check($request('GET', $path)->data['version'] === $state['version'], 'Revoked command performs no mutation');

// Inject failure at the last write: article/revision/event and receipt must roll back together.
$admin->exec("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1");
$before = $admin->query('SELECT (SELECT count(*) FROM axiom_content.revisions) AS revisions,
    (SELECT count(*) FROM axiom_content.outbox) AS events,
    (SELECT count(*) FROM axiom_content.command_receipts) AS receipts')->fetch(PDO::FETCH_ASSOC);
$admin->exec("CREATE FUNCTION axiom_content.reject_receipt() RETURNS trigger LANGUAGE plpgsql AS \$\$
    BEGIN RAISE EXCEPTION 'Injected receipt failure'; END; \$\$;
    CREATE TRIGGER reject_receipt BEFORE INSERT ON axiom_content.command_receipts
    FOR EACH ROW EXECUTE FUNCTION axiom_content.reject_receipt()");
try {
    foreach (array('edit', 'publish') as $operation) {
        try {
            $store->content($token, $operation, $id, $expected($state) + $input, bin2hex(random_bytes(32)));
            throw new LogicException('Injected failure unexpectedly committed.');
        } catch (PDOException $error) {
            $check($error->getCode() === 'P0001', 'Injected database failure reaches caller');
        }
    }
} finally {
    $admin->exec('DROP TRIGGER reject_receipt ON axiom_content.command_receipts;
        DROP FUNCTION axiom_content.reject_receipt()');
}
$check($request('GET', $path)->data['version'] === $state['version'], 'Mutation rollback on receipt failure');
$after = $admin->query('SELECT (SELECT count(*) FROM axiom_content.revisions) AS revisions,
    (SELECT count(*) FROM axiom_content.outbox) AS events,
    (SELECT count(*) FROM axiom_content.command_receipts) AS receipts')->fetch(PDO::FETCH_ASSOC);
$check($before === $after, 'Revision, event and receipt writes roll back together');
$admin->exec("INSERT INTO axiom_content.command_receipts(site_id,user_id,key,digest,article_id,result)
    SELECT 1,1,encode(sha256(convert_to('quota:'||i,'UTF8')),'hex'),'quota'," . $id . ", '{}'::jsonb
    FROM generate_series(1,4096-(SELECT count(*)::integer FROM axiom_content.command_receipts WHERE site_id=1)) i");
$check($request('PUT', $path, $expected($state) + $input)->status === 429, 'Receipt capacity fails closed');
$check($request('GET', $path)->data['version'] === $state['version'], 'Receipt quota preserves content');
$admin->exec("DELETE FROM axiom_content.command_receipts WHERE digest='quota';
    INSERT INTO axiom_content.revisions(site_id,article_id,revision_id,version,title,body,created_by)
    VALUES(1," . $id . ",repeat('e',64),1000,'quota','',1)");
$check($request('PUT', $path, $expected($state) + $input)->status === 429, 'Revision capacity fails closed');
$admin->exec("DELETE FROM axiom_content.revisions WHERE revision_id=repeat('e',64);
    INSERT INTO axiom_content.outbox(event_id,site_id,article_id,revision_id,event)
    SELECT md5('quota:'||i),1," . $id . ",'" . $state['revision'] . "','content.published.v1'
    FROM generate_series(1,10000-(SELECT count(*)::integer FROM axiom_content.outbox WHERE site_id=1)) i");
$check($request('POST', $path . '/publish', $expected($state))->status === 429, 'Outbox capacity fails closed');
$check($public() === array(), 'Outbox quota cannot partially publish');
$admin->exec("DELETE FROM axiom_content.outbox WHERE event_id IN
    (SELECT md5('quota:'||i) FROM generate_series(1,10000) i)");
foreach (array('command_receipts', 'outbox') as $table) {
    try {
        $db->query('SELECT * FROM axiom_content.' . $table);
        throw new LogicException('Private command storage exposed.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'No direct command storage permission');
    }
}
$admin->exec("DELETE FROM axiom_auth.attempts; DELETE FROM axiom_auth.sessions;
    UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1");
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
