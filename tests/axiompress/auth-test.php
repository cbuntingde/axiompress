<?php

declare(strict_types=1);

use AxiomPress\Core\Auth\Kernel;
use AxiomPress\Core\Auth\Store;
use AxiomPress\Database\Connection;
use AxiomPress\Database\MigrationRunner;

require '/core/bootstrap.php';
require '/app/Connection.php';
require '/app/MigrationRunner.php';

$checks = 0;
$check = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$admin = Connection::fromEnvironment();
$check((new MigrationRunner($admin))->migrate('/app/migrations')
    === range(1, count(glob('/app/migrations/*.sql'))), 'All migrations apply');
$admin->exec('SET ROLE axiom_owner');
$admin->exec("INSERT INTO axiom_identity.sites(id,slug,name) OVERRIDING SYSTEM VALUE
    VALUES(1,'one','One'),(2,'two','Two');
    INSERT INTO axiom_identity.users(id,username,display_name) OVERRIDING SYSTEM VALUE
    VALUES(1,'alice','Alice'),(2,'bob','Bob');
    INSERT INTO axiom_identity.memberships(site_id,user_id,role) VALUES(1,1,'owner'),(2,2,'owner');
    INSERT INTO axiom_auth.sites SELECT oid,1 FROM pg_roles WHERE rolname='auth_a';
    INSERT INTO axiom_auth.sites SELECT oid,2 FROM pg_roles WHERE rolname='auth_b'");
$password = file_get_contents('/run/secrets/user-password');
$hash = password_hash($password, PASSWORD_ARGON2ID, array('memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1));
$statement = $admin->prepare('INSERT INTO axiom_identity.credentials(user_id,password_hash) VALUES(?,?)');
$statement->execute(array(1, $hash));
$statement->execute(array(2, $hash));
$connect = static fn(string $user, string $secret): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents($secret),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$db = $connect('auth_a', '/run/secrets/auth-a');
$store = new Store($db, 1);
$other = new Store($connect('auth_b', '/run/secrets/auth-b'), 2);
$kernel = new Kernel(static fn(): Store => $store, 'https://localhost');
$call = static function (
    string $method = 'POST',
    string $path = '/v1/session/login',
    ?string $body = null,
    string $cookie = '',
    string $origin = 'https://localhost',
    string $csrf = '',
    bool $secure = true,
    string $type = 'application/json'
) use (
    $kernel,
    $password
): AxiomPress\Core\Response {
    return $kernel->handle($method, $path, $body ?? json_encode(
        array('username' => 'alice', 'password' => $password),
        JSON_THROW_ON_ERROR
    ), $cookie, $origin, $type, $csrf, '127.0.0.1', $secure);
};
$check($call(secure: false)->status === 400, 'TLS required');
$check($call(origin: 'https://localhost.evil')->status === 403, 'Origin suffix denied');
$check($call(origin: 'null')->status === 403, 'Null origin denied');
$check($call(type: 'text/plain')->status === 400, 'Form/simple content rejected');
foreach (
    array('{', '[]', '{"username":"alice","password":"x","username":"bob"}',
    '{"username":"alice","password":"x","site_id":2}', '{"username":"alice","password":42}',
    '{"username":"alice","password":""}', '{"username":"Alice","password":"x"}') as $invalid
) {
    $check($call(body: $invalid)->status === 400, 'Strict login input');
}
$check($call(body: str_repeat('x', 8193))->status === 413, 'Bounded body');
$check($call(path: '/v1/session/login?actor=admin')->status === 404, 'Query cannot supply authority');
$check($call(method: 'OPTIONS')->status === 405, 'No permissive CORS preflight');
$check($call(body: '{"username":"alice","password":"wrong"}')->status === 401, 'Wrong password rejected');
$check($call(body: '{"username":"missing","password":"wrong"}')->status === 401, 'Unknown user generic denial');
$check(
    $call(body: json_encode(array('username' => 'bob', 'password' => $password)))->status === 401,
    'Other-site valid credentials denied'
);
$response = $call();
$check(
    $response->status === 200 && $response->data['user_id'] === '1' && $response->data['site_id'] === '1',
    'Real login establishes correct principal'
);
$header = $response->headers['Set-Cookie'];
$cookie = explode(';', $header)[0];
$token = explode('=', $cookie, 2)[1];
$csrf = $response->data['csrf_token'];
$check(strlen($token) === 64 && !str_contains($response->body(), $token), 'Opaque token only in cookie');
foreach (array('Secure', 'HttpOnly', 'SameSite=Strict', 'Path=/') as $attribute) {
    $check(str_contains($header, $attribute), 'Session cookie flags');
}
$check(!str_contains($header, 'Domain='), 'Host-only cookie');
$check(
    $call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie, origin: '')->status === 200,
    'Session resolves without actor claims'
);
$check(
    $call(method: 'GET', path: '/v1/session', body: '', cookie: 'wordpress_logged_in=admin')->status === 401,
    'Legacy cookie cannot authenticate'
);
$check(
    $call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie . '; ' . $cookie)->status === 400,
    'Duplicate session cookies denied'
);
$check($call(method: 'DELETE', path: '/v1/session', body: '', cookie: $cookie)->status === 403, 'Logout needs CSRF');
$check(
    $call(method: 'DELETE', path: '/v1/session', body: '', cookie: $cookie, csrf: str_repeat('0', 64))->status === 403,
    'Forged CSRF rejected'
);
$check($other->resolve($token) === array(), 'Other database login cannot resolve session');
$admin->exec("UPDATE axiom_identity.memberships SET role='author' WHERE site_id=1 AND user_id=1");
$check(
    $call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie)->data['role'] === 'author',
    'Roles rechecked instead of cached'
);
$rotated = $call(cookie: $cookie);
$check($rotated->status === 200, 'Authenticated login succeeds');
$check($call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie)->status === 401, 'Old token invalidated');
$cookie = explode(';', $rotated->headers['Set-Cookie'])[0];
$csrf = $rotated->data['csrf_token'];
$check(
    $call(method: 'DELETE', path: '/v1/session', body: '', cookie: $cookie, csrf: $csrf)->status === 200,
    'CSRF-bound logout'
);
$check($call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie)->status === 401, 'Logout revokes token');

foreach (array('user', 'member', 'password', 'idle', 'absolute') as $case) {
    $admin->exec('DELETE FROM axiom_auth.attempts');
    $response = $call();
    $check($response->status === 200, 'Revocation fixture login');
    $cookie = explode(';', $response->headers['Set-Cookie'])[0];
    if ($case === 'user') {
        $admin->exec('UPDATE axiom_identity.users SET disabled_at=now() WHERE id=1');
    } elseif ($case === 'member') {
        $admin->exec('UPDATE axiom_identity.memberships SET disabled_at=now() WHERE site_id=1 AND user_id=1');
    } elseif ($case === 'password') {
        $newHash = password_hash('different-fixture-password', PASSWORD_ARGON2ID);
        $change = $admin->prepare('UPDATE axiom_identity.credentials SET password_hash=? WHERE user_id=1');
        $change->execute(array($newHash));
    } elseif ($case === 'idle') {
        $admin->exec("UPDATE axiom_auth.sessions SET created_at=now()-interval '1 hour',
            last_seen_at=now()-interval '31 minutes',expires_at=now()+interval '7 hours' WHERE user_id=1");
    } else {
        $admin->exec("UPDATE axiom_auth.sessions SET created_at=now()-interval '9 hours',
            last_seen_at=now()-interval '9 hours',expires_at=now()-interval '1 hour' WHERE user_id=1");
    }
    $check(
        $call(method: 'GET', path: '/v1/session', body: '', cookie: $cookie)->status === 401,
        'Fresh revocation: ' . $case
    );
    $admin->exec('UPDATE axiom_identity.users SET disabled_at=NULL WHERE id=1;
        UPDATE axiom_identity.memberships SET disabled_at=NULL WHERE site_id=1 AND user_id=1');
    $admin->prepare('UPDATE axiom_identity.credentials SET password_hash=? WHERE user_id=1')->execute(array($hash));
    $admin->exec('DELETE FROM axiom_auth.sessions');
}
foreach (
    array('SELECT * FROM axiom_identity.credentials', 'SELECT * FROM axiom_auth.sessions',
    'SELECT * FROM axiom_auth.sites', 'DELETE FROM axiom_auth.attempts', 'SET ROLE axiom_owner',
    "UPDATE axiom_content.articles SET status='draft'", 'CREATE TABLE public.forbidden(id int)') as $sql
) {
    try {
        $db->exec($sql);
        throw new LogicException('Unexpected runtime SQL permission.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'Function-only authentication privileges');
    }
}
$admin->exec('DELETE FROM axiom_auth.attempts');
for ($i = 0; $i < 5; $i++) {
    $check($call(body: '{"username":"alice","password":"wrong"}')->status === 401, 'Allowed attempt denied');
}
$check($call()->status === 429, 'Per-account attempt budget enforced before hash');
$admin->exec('DELETE FROM axiom_auth.attempts');
for ($i = 0; $i < 30; $i++) {
    $store->beginLogin('missing' . $i, '127.0.0.1');
}
$check($call()->status === 429, 'Per-peer budget spans account names');
$admin->exec('DELETE FROM axiom_auth.attempts');
for ($i = 0; $i < 7; $i++) {
    $check($call()->status === 200, 'Bounded session creation');
}
$check(
    (int) $admin->query('SELECT count(*) FROM axiom_auth.sessions WHERE user_id=1')->fetchColumn() === 5,
    'Per-user session cap'
);
$check(
    $store->create(1, $hash . 'changed', bin2hex(random_bytes(32)), '') === array(),
    'Credential change between verification and creation fails closed'
);
$admin->exec('UPDATE axiom_auth.sites SET site_id=2 WHERE database_role=\'auth_a\'::regrole::oid');
try {
    $store->beginLogin('bob', '127.0.0.1');
    throw new LogicException('Changed site binding was accepted.');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'Authentication site binding changed.', 'Site binding drift rejected');
}
$admin->exec('UPDATE axiom_auth.sites SET site_id=1 WHERE database_role=\'auth_a\'::regrole::oid');
$admin->exec("DELETE FROM axiom_auth.attempts;
    INSERT INTO axiom_auth.attempts SELECT 1,'account',encode(sha256(convert_to(i::text,'UTF8')),'hex'),now(),1
    FROM generate_series(1,4094) i");
$check($call()->status === 429, 'Rate-state capacity fails closed');
$check(
    (int) $admin->query('SELECT count(*) FROM axiom_auth.attempts WHERE site_id=1')->fetchColumn() === 4094,
    'Rate-state capacity remains bounded'
);
$admin->exec('DELETE FROM axiom_auth.attempts; DELETE FROM axiom_auth.sessions');
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
