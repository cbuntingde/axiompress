<?php

declare(strict_types=1);

use AxiomPress\Policy\AuditLog;
use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Policy\PublisherApproval;
use AxiomPress\Broker\InvocationRegistry;

require __DIR__ . '/bootstrap.php';
$checks = 0;
$check = static function (bool $value) use (&$checks): void {
    if (!$value) {
        throw new LogicException('Policy assertion failed.');
    }
    ++$checks;
};
$reject = static function (callable $action) use ($check): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        $check(true);
        return;
    }
    throw new LogicException('Expected policy rejection.');
};
$directory = '/tmp/axiompress-policy-test-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
mkdir($directory . '/package', 0700);
$manifest = file_get_contents('/app/packages/reading-summary/manifest.json');
$worker = '<?php throw new RuntimeException("Package must never execute during approval");';
$write = static function (string $json, string $php) use ($directory): void {
    file_put_contents($directory . '/package/manifest.json', $json);
    file_put_contents($directory . '/package/worker.php', $php);
};
try {
    $write($manifest, $worker);
    $package = Package::capture($directory . '/package');
    $check($package->worker === $worker);
    $check($package->manifestDigest === hash('sha256', $manifest));
    $write($manifest, $worker . '// changed');
    $check($package->worker === $worker);
    $check(Package::capture($directory . '/package')->digest !== $package->digest);
    $write($manifest, $worker);
    $validManifest = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
    foreach (
        array('entrypoint' => '../worker.php', 'capabilities' => array('content.write'),
        'plugin_id' => 'invalid/name!', 'version' => "1.0.0\n", 'resource_profile' => 'privileged',
        'install_hook' => 'run.php') as $field => $value
    ) {
        $bad = $validManifest;
        $bad[$field] = $value;
        $write(json_encode($bad, JSON_THROW_ON_ERROR), $worker);
        $reject(fn() => Package::capture($directory . '/package'));
    }
    $write(str_replace('"api":', '"api":"other","api":', $manifest), $worker);
    $reject(fn() => Package::capture($directory . '/package'));
    $write(str_repeat(' ', 16385), $worker);
    $reject(fn() => Package::capture($directory . '/package'));
    $write($manifest, str_repeat('x', 262145));
    $reject(fn() => Package::capture($directory . '/package'));
    $write($manifest, $worker);
    file_put_contents($directory . '/package/Dockerfile', 'RUN forbidden');
    $reject(fn() => Package::capture($directory . '/package'));
    unlink($directory . '/package/Dockerfile');
    unlink($directory . '/package/worker.php');
    file_put_contents($directory . '/outside.php', $worker);
    symlink($directory . '/outside.php', $directory . '/package/worker.php');
    $reject(fn() => Package::capture($directory . '/package'));
    unlink($directory . '/package/worker.php');
    link($directory . '/outside.php', $directory . '/package/worker.php');
    $reject(fn() => Package::capture($directory . '/package'));
    unlink($directory . '/package/worker.php');
    unlink($directory . '/outside.php');
    $write($manifest, $worker);
    $image = 'sha256:' . str_repeat('a', 64);
    $sdk = 'sha256:' . str_repeat('b', 64);
    $inspection = array('Id' => $image, 'Config' => array('User' => '10001:10001',
        'Entrypoint' => array('php', '-n', '-d', 'memory_limit=96M', '/app/worker.php'),
        'Labels' => array('dev.axiompress.package.digest' => $package->digest,
            'dev.axiompress.manifest.digest' => $package->manifestDigest,
            'dev.axiompress.plugin.id' => $package->pluginId, 'dev.axiompress.plugin.version' => $package->version,
            'dev.axiompress.sdk.image' => $sdk, 'dev.axiompress.profile' => 'readonly-v1')));
    $binding = ImageBinding::inspect($package, $inspection, $sdk);
    foreach (
        array('User' => '0', 'Entrypoint' => array('sh'), 'Volumes' => array('/core' => array()),
        'Cmd' => array('extra'), 'Healthcheck' => array('Test' => array('CMD', 'sh')),
        'OnBuild' => array('RUN unknown')) as $key => $value
    ) {
        $bad = $inspection;
        $bad['Config'][$key] = $value;
        $reject(fn() => ImageBinding::inspect($package, $bad, $sdk));
    }
    $bad = $inspection;
    $bad['Config']['Labels']['dev.axiompress.package.digest'] = str_repeat('0', 64);
    $reject(fn() => ImageBinding::inspect($package, $bad, $sdk));
    $store = new PolicyStore($directory . '/policy.sqlite');
    $publisherKey = openssl_pkey_new(
        array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA)
    );
    if ($publisherKey === false) {
        throw new LogicException('Test publisher key generation failed.');
    }
    $publisherDetails = openssl_pkey_get_details($publisherKey);
    if (!is_array($publisherDetails) || !isset($publisherDetails['key'])) {
        throw new LogicException('Test publisher key export failed.');
    }
    $publisherPem = $publisherDetails['key'];
    $publisherId = $store->addPublisher($publisherPem);
    $check($publisherId === PublisherApproval::keyIdFor($publisherPem));
    $check(count($store->listPublishers()) === 1);
    $sign = static function (ImageBinding $target) use ($publisherKey, $publisherPem): array {
        if (
            !openssl_sign(
                PublisherApproval::canonicalBytes($target),
                $signature,
                $publisherKey,
                OPENSSL_ALGO_SHA256
            )
        ) {
            throw new LogicException('Test publisher signing failed.');
        }
        return array(
            'algorithm' => 'rsa2048-sha256',
            'public_key' => $publisherPem,
            'signature' => base64_encode($signature),
        );
    };
    $envelope = $sign($binding);
    $store->register($binding, $envelope);
    $store->register($binding, $envelope);
    $store->create('plugin-a', 'site:1', $image);
    $check($store->generation('plugin-a', 'site:1', $image) === null);
    $check((int) $store->describe('plugin-a', 'site:1')['generation'] === 1);
    $reject(fn() => $store->resolve('plugin-a', 'site:1'));
    $reject(fn() => $store->create('plugin-a', 'site:1', $image));
    $check($store->change('plugin-a', 1, true, array('content.read'), $image) === 2);
    $reader = new PolicyStore($directory . '/policy.sqlite', true);
    $check($reader->resolve('plugin-a', 'site:1')['image'] === $image);
    $check($reader->generation('plugin-a', 'site:2', $image) === null);
    $registry = new InvocationRegistry(static fn(string $id, string $site): ?int =>
        $reader->generation($id, $site, $image));
    $handle = $registry->issue('test', 'plugin-a', 'site:1', 'anonymous', array('post:1'));
    $writer = new PolicyStore($directory . '/policy.sqlite');
    $check($writer->change('plugin-a', 2, false, array(), $image) === 3);
    $check($reader->generation('plugin-a', 'site:1', $image) === null);
    $check($writer->change('plugin-a', 3, true, array('content.read'), $image) === 4);
    $check($reader->generation('plugin-a', 'site:1', $image) === 4);
    $reject(fn() => $registry->context('test', $handle));
    $reopened = new PolicyStore($directory . '/policy.sqlite', true);
    $check($reopened->generation('plugin-a', 'site:1', $image) === 4);
    $reject(fn() => $writer->change('plugin-a', 2, true, array('content.read'), $image));
    $reject(fn() => $writer->change('plugin-a', 4, true, array('content.write'), $image));
    $reject(fn() => $writer->change('plugin-a', 4, true, array('content.read'), 'unregistered'));
    $check($reader->generation('plugin-a', 'site:1', $image) === 4);
    $reject(fn() => $reader->create('readonly', 'site:1', $image));
    $reject(fn() => $reader->change('plugin-a', 4, false, array(), $image));
    $reject(fn() => $reader->register($binding, $envelope));
    $other = $inspection;
    $other['Id'] = 'sha256:' . str_repeat('c', 64);
    $otherBinding = ImageBinding::inspect($package, $other, $sdk);
    $store->register($otherBinding, $sign($otherBinding));
    $check($store->change('plugin-a', 4, true, array('content.read'), $otherBinding->image) === 5);
    $check($reader->generation('plugin-a', 'site:1', $image) === null);
    $check($reader->resolve('plugin-a', 'site:1')['image'] === $otherBinding->image);
    $reject(fn() => $writer->change('plugin-a', 4, true, array('content.read'), $image));
    $check($store->change('plugin-a', 5, true, array(), $otherBinding->image) === 6);
    $check($reader->generation('plugin-a', 'site:1', $otherBinding->image) === null);
    $check((int) $reader->describe('plugin-a', 'site:1')['generation'] === 6);
    $reject(fn() => $store->create('wrong-site', 'not-a-site', $image));
    $reject(fn() => $store->create('unknown-image', 'site:1', 'unregistered'));
    $write($manifest, $worker . '// different artifact');
    $changedPackage = Package::capture($directory . '/package');
    $conflict = $inspection;
    $conflict['Config']['Labels']['dev.axiompress.package.digest'] = $changedPackage->digest;
    $conflictingBinding = ImageBinding::inspect($changedPackage, $conflict, $sdk);
    $reject(fn() => $store->register($conflictingBinding, $sign($conflictingBinding)));
    $store->register($binding, $envelope);
    $check(true);
    $reject(fn() => ImageBinding::inspect($package, $inspection, 'sha256:' . str_repeat('d', 64)));
    $unknown = new PDO('sqlite:' . $directory . '/unknown.sqlite');
    $unknown->exec('CREATE TABLE unrelated(value TEXT); INSERT INTO unrelated VALUES ("preserved")');
    $reject(fn() => new PolicyStore($directory . '/unknown.sqlite'));
    $check($unknown->query('SELECT value FROM unrelated')->fetchColumn() === 'preserved');
    $check((int) $unknown->query('PRAGMA user_version')->fetchColumn() === 0);
    $differentManifest = $validManifest;
    $differentManifest['plugin_id'] = 'other/publisher';
    $write(json_encode($differentManifest, JSON_THROW_ON_ERROR), $worker);
    $differentPackage = Package::capture($directory . '/package');
    $differentImage = $inspection;
    $differentImage['Id'] = 'sha256:' . str_repeat('e', 64);
    $differentImage['Config']['Labels']['dev.axiompress.plugin.id'] = $differentPackage->pluginId;
    $differentImage['Config']['Labels']['dev.axiompress.package.digest'] = $differentPackage->digest;
    $differentImage['Config']['Labels']['dev.axiompress.manifest.digest'] = $differentPackage->manifestDigest;
    $differentBinding = ImageBinding::inspect($differentPackage, $differentImage, $sdk);
    $store->register($differentBinding, $sign($differentBinding));
    $reject(fn() => $store->change('plugin-a', 6, true, array('content.read'), $differentBinding->image));
    $check((int) $reader->describe('plugin-a', 'site:1')['generation'] === 6);
    $check($store->change('plugin-a', 6, true, array('content.read'), $image) === 7);
    $check($reader->resolve('plugin-a', 'site:1')['image'] === $image);
    $check($reader->generation('plugin-a', 'site:1', $otherBinding->image) === null);
    $reject(fn() => $registry->context('test', $handle));
    $legacyStore = new PolicyStore($directory . '/legacy.sqlite');
    $legacyStore->addPublisher($publisherPem);
    $legacyStore->register($binding, $sign($binding));
    $legacyStore->create('legacy', 'site:1', $image);
    $legacyStore->change('legacy', 1, true, array('content.read'), $image);
    unset($legacyStore);
    $legacy = new PDO('sqlite:' . $directory . '/legacy.sqlite');
    $check($legacy->query('PRAGMA journal_mode=WAL')->fetchColumn() === 'wal');
    $reject(fn() => new PolicyStore($directory . '/legacy.sqlite', true));
    unset($legacy);
    $converted = new PolicyStore($directory . '/legacy.sqlite');
    $convertedReader = new PolicyStore($directory . '/legacy.sqlite', true);
    $check($convertedReader->generation('legacy', 'site:1', $image) === 2);
    $legacy = new PDO('sqlite:' . $directory . '/legacy.sqlite');
    $check($legacy->query('PRAGMA journal_mode')->fetchColumn() === 'delete');
    $rogueKey = openssl_pkey_new(
        array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA)
    );
    $rogueDetails = openssl_pkey_get_details($rogueKey);
    $rogueSign = static function (ImageBinding $target) use ($rogueKey, $rogueDetails): array {
        if (
            !openssl_sign(
                PublisherApproval::canonicalBytes($target),
                $signature,
                $rogueKey,
                OPENSSL_ALGO_SHA256
            )
        ) {
            throw new LogicException('Rogue publisher signing failed.');
        }
        return array(
            'algorithm' => 'rsa2048-sha256',
            'public_key' => $rogueDetails['key'],
            'signature' => base64_encode($signature),
        );
    };
    $reject(fn() => $store->register($binding, $rogueSign($binding)));
    $reject(fn() => $store->register($binding, $sign($otherBinding)));
    $reject(fn() => $store->register($binding, array()));
    $reject(fn() => $store->register($binding, array('algorithm' => 'rsa2048-sha256',
        'public_key' => $publisherPem, 'signature' => '!!!')));
    $shortKey = openssl_pkey_new(
        array('private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA)
    );
    $reject(fn() => $store->addPublisher(openssl_pkey_get_details($shortKey)['key']));
    $ellipticKey = openssl_pkey_new(
        array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1')
    );
    $reject(fn() => $store->addPublisher(openssl_pkey_get_details($ellipticKey)['key']));
    $reject(fn() => $store->revokePublisher('bogus'));
    $store->revokePublisher($publisherId);
    $publishers = $store->listPublishers();
    $check(
        count($publishers) === 1 && $publishers[0]['key_id'] === $publisherId
        && (int) $publishers[0]['revoked'] === 1
    );
    $reject(fn() => $store->register($binding, $sign($binding)));
    $untrusted = new PDO('sqlite:' . $directory . '/policy.sqlite');
    $untrusted->exec("INSERT INTO images VALUES('sha256:" . str_repeat('f', 64) . "', '" .
        $package->digest . "', '" . $package->manifestDigest . "', '" . $package->pluginId . "', '" .
        $package->version . "', 'sha256:" . str_repeat('b', 64) . "', NULL)");
    unset($untrusted);
    $store->create('legacy-image', 'site:9', 'sha256:' . str_repeat('f', 64));
    $check($store->describe('legacy-image', 'site:9')['image'] === 'sha256:' . str_repeat('f', 64));
    $auditDir = '/tmp/axiompress-audit-test-' . bin2hex(random_bytes(12));
    mkdir($auditDir, 0700);
    $auditPath = $auditDir . '/audit.jsonl';
    $reject(fn() => AuditLog::open($auditDir . '/missing/audit.jsonl'));
    $reject(fn() => AuditLog::open('relative/audit.jsonl'));
    $reject(fn() => AuditLog::open($auditPath, 1000));
    $log = AuditLog::open($auditPath);
    $context = array('action' => 'approve-read', 'installation' => 'plugin-a', 'site' => 'site:1',
        'expected_generation' => 7, 'image' => $image, 'grants' => 'content.read');
    $check($log->record(static fn() => 8, $context) === 8);
    $lines = explode("\n", trim(file_get_contents($auditPath)));
    $check(count($lines) === 1);
    $entry = json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR);
    $check($entry['outcome'] === 'applied' && $entry['result_generation'] === 8
        && $entry['action'] === 'approve-read' && $entry['installation'] === 'plugin-a'
        && is_int($entry['uid']));
    $check(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $entry['ts']) === 1);
    $check((fileperms($auditPath) & 0777) === 0600);
    try {
        $log->record(static function (): void {
            throw new RuntimeException('Stale or missing installation generation.');
        }, $context);
        throw new LogicException('Rejected mutation was not reported.');
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Stale or missing installation generation.');
    }
    $lines = explode("\n", trim(file_get_contents($auditPath)));
    $check(count($lines) === 2);
    $denied = json_decode($lines[1], true, 8, JSON_THROW_ON_ERROR);
    $check($denied['outcome'] === 'rejected' && $denied['result_generation'] === null
        && $denied['error'] === 'Stale or missing installation generation.');
    $ran = false;
    $reject(function () use ($log, $context, &$ran): void {
        $log->record(static function () use (&$ran): int {
            $ran = true;
            return 9;
        }, array('action' => 'revoke') + $context);
    });
    $check($ran === false);
    $reject(fn() => $log->append(array('nested' => array('x'))));
    $reject(fn() => $log->append(array('big' => str_repeat('x', 5000))));
    $lines = explode("\n", trim(file_get_contents($auditPath)));
    $check(count($lines) === 2);
    $reject(fn() => AuditLog::open($auditPath, 4096));
    $reject(fn() => AuditLog::open($auditPath, 8192, -1));
    $reject(fn() => AuditLog::open($auditPath, 8192, 10001));
    $capped = AuditLog::open($auditDir . '/capped.jsonl', 8192);
    $capped->append(array('seed' => 'x'));
    $tight = AuditLog::open(
        $auditDir . '/capped.jsonl',
        filesize($auditDir . '/capped.jsonl') + AuditLog::MAX_RECORD_BYTES,
        0
    );
    try {
        $tight->record(static fn() => 1, $context);
        throw new LogicException('Full audit spool accepted another record.');
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Audit spool capacity reached; archive the spool to resume.');
    }
    $rotating = AuditLog::open($auditDir . '/rotating.jsonl', 5000, 2);
    $sequence = 0;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $sequence++;
        $check($rotating->record(static fn() => $sequence, $context) === $sequence);
    }
    $archives = array_values(array_filter(
        scandir($auditDir),
        static fn(string $name): bool => (bool) preg_match('/\Arotating\.jsonl\.\d{14}\.\d{6}(\.\d+)?\z/', $name)
    ));
    sort($archives);
    $check(count($archives) === 2);
    $ordered = array();
    foreach (array_merge($archives, array('rotating.jsonl')) as $name) {
        foreach (explode("\n", trim(file_get_contents($auditDir . '/' . $name))) as $line) {
            $ordered[] = json_decode($line, true, 8, JSON_THROW_ON_ERROR)['result_generation'];
        }
    }
    $check($ordered === range($ordered[0], 20) && $ordered[0] > 1);
    $check(count($ordered) === count(array_unique($ordered)));
    $check((fileperms($auditDir . '/' . $archives[0]) & 0777) === 0600);
    chmod($auditPath, 0644);
    $reject(fn() => $log->append(array('late' => 'write')));
    chmod($auditPath, 0600);
    $link = $auditDir . '/linked.jsonl';
    symlink($auditDir . '/elsewhere.jsonl', $link);
    $reject(fn() => AuditLog::open($link));
    unlink($link);
    try {
        $log->record(static function (): void {
            throw new PDOException('SQLSTATE[HY000]: no such table: axiom_content.articles');
        }, $context);
        throw new LogicException('Database failure was not reported.');
    } catch (PDOException $error) {
        $check(true);
    }
    $lines = explode("\n", trim(file_get_contents($auditPath)));
    $entry = json_decode(end($lines), true, 8, JSON_THROW_ON_ERROR);
    $check($entry['error'] === 'database_unavailable');
    $cli = static function (string ...$arguments): array {
        $command = implode(' ', array_map('escapeshellarg', array_merge(
            array(PHP_BINARY, __DIR__ . '/manage.php'),
            $arguments
        )));
        $output = array();
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        return array($status, implode("\n", $output));
    };
    putenv('AXIOM_POLICY_FILE=' . $directory . '/policy.sqlite');
    putenv('AXIOM_POLICY_AUDIT_FILE=' . $directory . '/cli-audit.jsonl');
    [$status, $output] = $cli('approve-read', 'plugin-a', 'site:1', '7', $image);
    $check($status === 0 && json_decode($output, true)['generation'] === 8);
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $check(count($auditLines) === 1 && json_decode($auditLines[0], true)['result_generation'] === 8);
    [$status] = $cli('approve-read', 'plugin-a', 'site:1', '7', $image);
    $check($status !== 0);
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $check(count($auditLines) === 2 && json_decode($auditLines[1], true)['outcome'] === 'rejected');
    [$status] = $cli('disable', 'plugin-a', 'site:1', '8');
    $check($status === 0);
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $check(count($auditLines) === 3 && json_decode(end($auditLines), true)['result_generation'] === 9);
    [$status, $output] = $cli('publisher-list');
    $listed = json_decode($output, true);
    $check(
        $status === 0 && count($listed) === 1 && $listed[0]['key_id'] === $publisherId
        && (int) $listed[0]['revoked'] === 1
    );
    $secondKey = openssl_pkey_new(
        array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA)
    );
    $secondDetails = openssl_pkey_get_details($secondKey);
    file_put_contents($directory . '/publisher.pem', $secondDetails['key']);
    [$status, $output] = $cli('publisher-add', $directory . '/publisher.pem');
    $addedId = json_decode($output, true)['key_id'];
    $check($status === 0 && $addedId === PublisherApproval::keyIdFor($secondDetails['key']));
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $addedEntry = json_decode(end($auditLines), true);
    $check(
        count($auditLines) === 4 && $addedEntry['outcome'] === 'applied'
        && $addedEntry['action'] === 'publisher-add' && $addedEntry['key_id'] === $addedId
    );
    [$status] = $cli('publisher-add', $directory . '/publisher.pem');
    $check($status !== 0);
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $rejectedEntry = json_decode(end($auditLines), true);
    $check(
        count($auditLines) === 5 && $rejectedEntry['outcome'] === 'rejected'
        && $rejectedEntry['action'] === 'publisher-add'
    );
    [$status, $output] = $cli('publisher-revoke', $addedId);
    $check($status === 0 && json_decode($output, true)['revoked'] === true);
    $auditLines = explode("\n", trim(file_get_contents($directory . '/cli-audit.jsonl')));
    $check(
        count($auditLines) === 6 && json_decode(end($auditLines), true)['action'] === 'publisher-revoke'
    );
    [$status] = $cli('publisher-revoke', 'bogus');
    $check($status !== 0);
    putenv('AXIOM_POLICY_AUDIT_FILE=' . $directory . '/cli-rotating.jsonl');
    putenv('AXIOM_POLICY_AUDIT_MAX_BYTES=5000');
    putenv('AXIOM_POLICY_AUDIT_MAX_ARCHIVES=2');
    for ($generation = 9; $generation < 17; $generation++) {
        [$status] = $cli('approve-read', 'plugin-a', 'site:1', (string) $generation, $image);
        $check($status === 0);
    }
    $rotated = array_values(array_filter(
        scandir($directory),
        static fn(string $name): bool => (bool) preg_match('/\Acli-rotating\.jsonl\.\d{14}\.\d{6}(\.\d+)?\z/', $name)
    ));
    sort($rotated);
    $check(count($rotated) === 1);
    $tail = array();
    foreach (array_merge($rotated, array('cli-rotating.jsonl')) as $name) {
        foreach (explode("\n", trim(file_get_contents($directory . '/' . $name))) as $line) {
            $tail[] = json_decode($line, true, 8, JSON_THROW_ON_ERROR)['result_generation'];
        }
    }
    $check($tail === range(10, 17));
    $check(count($tail) === count(array_unique($tail)));
    putenv('AXIOM_POLICY_AUDIT_MAX_BYTES');
    putenv('AXIOM_POLICY_AUDIT_MAX_ARCHIVES');
    putenv('AXIOM_POLICY_FILE');
    putenv('AXIOM_POLICY_AUDIT_FILE');
    echo "PASS: {$checks} package/policy checks\n";
} finally {
    unset($store, $writer, $reader, $reopened, $registry, $unknown, $legacy, $converted, $convertedReader);
    foreach (array('manifest.json', 'worker.php', 'Dockerfile') as $name) {
        if (file_exists($directory . '/package/' . $name)) {
            unlink($directory . '/package/' . $name);
        }
    }
    foreach (
        array('policy.sqlite', 'policy.sqlite-wal', 'policy.sqlite-shm', 'unknown.sqlite',
        'legacy.sqlite', 'legacy.sqlite-wal', 'legacy.sqlite-shm', 'cli-audit.jsonl',
        'cli-rotating.jsonl', 'publisher.pem') as $name
    ) {
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    foreach (scandir($directory) as $name) {
        if (str_starts_with($name, 'cli-rotating.jsonl.')) {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory . '/package');
    rmdir($directory);
    if (isset($auditDir) && is_dir($auditDir)) {
        foreach (array('audit.jsonl', 'capped.jsonl', 'rotating.jsonl') as $name) {
            if (is_file($auditDir . '/' . $name)) {
                unlink($auditDir . '/' . $name);
            }
        }
        foreach (scandir($auditDir) as $name) {
            if (str_starts_with($name, 'rotating.jsonl.')) {
                unlink($auditDir . '/' . $name);
            }
        }
        rmdir($auditDir);
    }
}
