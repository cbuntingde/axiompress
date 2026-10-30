<?php

declare(strict_types=1);

use AxiomPress\Core\Auth\Store;
use AxiomPress\Core\Media\Kernel;
use AxiomPress\Core\Media\PublicRepository;
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
$connect = static fn(string $user, string $file): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents('/run/secrets/' . $file),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$auth = $connect('auth_a', 'auth-a');
$store = new Store($auth, 1);
$hash = $admin->query('SELECT password_hash FROM axiom_identity.credentials WHERE user_id=1')->fetchColumn();
$token = bin2hex(random_bytes(32));
$store->create(1, $hash, $token, '');
$kernel = new Kernel(static fn(): Store => $store, 'https://localhost');
$cookie = '__Host-axiom_session=' . $token;
$csrf = hash_hmac('sha256', 'axiompress.csrf.v1', $token);
$key = static fn(): string => bin2hex(random_bytes(32));
$call = static function (
    string $method,
    string $path,
    array $input = array(),
    ?string $commandKey = null,
    ?string $requestCookie = null,
    ?string $requestCsrf = null
) use (
    $kernel,
    $cookie,
    $csrf,
    $key
): mixed {
    return $kernel->handle(
        $method,
        $path,
        $input === array() ? '' : json_encode($input, JSON_THROW_ON_ERROR),
        $requestCookie ?? $cookie,
        'https://localhost',
        'application/json',
        $requestCsrf ?? $csrf,
        $commandKey ?? $key(),
        true
    );
};
// These are SQL-boundary fixture bytes, not a claim of valid image decoding. Real GD/runsc proof is separate.
$fixture = "\x89PNG\r\n\x1a\nSQL-only fixture";
$input = array('data' => base64_encode($fixture), 'mime' => 'image/png', 'name' => '<b>name</b>.png');
$commandKey = $key();
$created = $call('POST', '/v1/media', $input, $commandKey);
$check($created->status === 202 && $created->data['status'] === 'pending', 'Upload enters private quarantine');
$id = $created->data['id'];
$path = '/v1/media/' . $id;
$check($call('POST', '/v1/media', $input, $commandKey)->data === $created->data, 'Upload retry is idempotent');
$check($call('GET', $path . '/file')->status === 404, 'Original quarantine bytes never downloadable');
$check($call('GET', $path, requestCookie: '')->status === 401, 'Anonymous metadata denied');
$check($call('POST', '/v1/media', $input, requestCsrf: '')->status === 403, 'Upload requires current CSRF');
$check($call('POST', '/v1/media', $input + array('creator' => '1'))->status === 400, 'Upload actor claims rejected');
$check($call('POST', '/v1/media', array('data' => base64_encode('<svg/>'), 'mime' => 'image/svg+xml', 'name' => 'x'))
    ->status === 400, 'Executable image formats denied');
$check($call('POST', '/v1/media', array('data' => base64_encode('wrong'), 'mime' => 'image/png', 'name' => 'x'))
    ->status === 400, 'Claimed PNG without magic denied');
$reader = new PublicRepository($connect('reader_a', 'reader-a'), 1);
$check($reader->read(1, $id)->status === 404, 'Unprocessed image not public');
$worker = $connect('job_a', 'job-a');
$task = json_decode($worker->query('SELECT axiom_media.claim()')->fetchColumn(), true);
$check($task['id'] === $id && isset($task['lease']), 'Dedicated processor identity claims original');
$finish = $worker->prepare('SELECT axiom_media.finish(?,?,?,?,?,?)');
$finish->execute(array($id, $task['lease'], 'ready', base64_encode($fixture), 1, 1));
$check(json_decode($finish->fetchColumn(), true)['outcome'] === 'ready', 'Only lease holder commits fixed PNG bytes');
$check(
    $admin->query("SELECT source IS NULL FROM axiom_media.assets WHERE id='$id'")->fetchColumn(),
    'Original upload removed after transformation'
);
$check($call('GET', $path . '/file')->body() === $fixture, 'Owner reads transformed bytes');
$check($reader->read(1, $id)->status === 404, 'Transformed image remains private until explicit publication');
$metadata = $call('GET', $path);
$published = $call('POST', $path . '/publish', array('expected_version' => $metadata->data['version']));
$check($published->status === 200 && $reader->read(1, $id)->body() === $fixture, 'Editor explicitly publishes PNG');
$check($reader->read(2, $id)->status === 404, 'Public image configured site enforced');
$check(
    $call('POST', $path . '/withdraw', array('expected_version' => $metadata->data['version']))->status === 409,
    'Stale media visibility command conflicts'
);
$withdrawn = $call('POST', $path . '/withdraw', array('expected_version' => $published->data['version']));
$check($withdrawn->status === 200 && $reader->read(1, $id)->status === 404, 'Withdrawal removes public image');
$finish->execute(array($id, $task['lease'], 'ready', base64_encode($fixture), 1, 1));
$check(json_decode($finish->fetchColumn(), true)['error'] === 'conflict', 'Completed processor lease cannot replay');
$other = new Store($connect('auth_b', 'auth-b'), 2);
$otherToken = bin2hex(random_bytes(32));
$other->create(2, $hash, $otherToken, '');
$check(
    ($other->media($otherToken, 'read', $id, array(), '')['error'] ?? '') === 'not_found',
    'Cross-site media denied'
);
foreach (array($auth, $connect('reader_a', 'reader-a'), $worker) as $connection) {
    try {
        $connection->query('SELECT source FROM axiom_media.assets');
        throw new LogicException('Private image store table accessible.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'No direct original/blob storage access');
    }
}
// This magic-only SQL fixture is not a decoded image. Do not leak its synthetic ready
// record into subsequent browser/decoder integration cases that share this disposable site.
$cleanup = $admin->prepare('DELETE FROM axiom_media.assets WHERE id=?');
$cleanup->execute(array($id));
$admin->exec('DELETE FROM axiom_auth.attempts; DELETE FROM axiom_auth.sessions');
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
