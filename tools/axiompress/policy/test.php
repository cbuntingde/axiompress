<?php

declare(strict_types=1);

use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PolicyStore;
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
    $store->register($binding);
    $store->register($binding);
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
    $reject(fn() => $reader->register($binding));
    $other = $inspection;
    $other['Id'] = 'sha256:' . str_repeat('c', 64);
    $otherBinding = ImageBinding::inspect($package, $other, $sdk);
    $store->register($otherBinding);
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
    $reject(fn() => $store->register($conflictingBinding));
    $store->register($binding);
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
    $store->register($differentBinding);
    $reject(fn() => $store->change('plugin-a', 6, true, array('content.read'), $differentBinding->image));
    $check((int) $reader->describe('plugin-a', 'site:1')['generation'] === 6);
    $check($store->change('plugin-a', 6, true, array('content.read'), $image) === 7);
    $check($reader->resolve('plugin-a', 'site:1')['image'] === $image);
    $check($reader->generation('plugin-a', 'site:1', $otherBinding->image) === null);
    $reject(fn() => $registry->context('test', $handle));
    $legacyStore = new PolicyStore($directory . '/legacy.sqlite');
    $legacyStore->register($binding);
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
        'legacy.sqlite', 'legacy.sqlite-wal', 'legacy.sqlite-shm') as $name
    ) {
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory . '/package');
    rmdir($directory);
}
