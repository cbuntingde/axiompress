<?php

declare(strict_types=1);

use AxiomPress\Policy\Package;
use AxiomPress\Policy\PackageBuilder;
use AxiomPress\Policy\PolicyStore;

require dirname(__DIR__) . '/policy/bootstrap.php';
require dirname(__DIR__) . '/policy/PackageBuilder.php';

if (
    getenv('AXIOM_SERVICE_TEST') !== '1'
    || (new FilesystemIterator('/policy', FilesystemIterator::SKIP_DOTS))->valid()
) {
    throw new RuntimeException('Explicit disposable service-test approval and an empty policy directory required.');
}

$package = Package::capture('/app/packages/reading-summary');
$binding = PackageBuilder::build($package, (string) getenv('AXIOM_SDK_IMAGE'));
$store = new PolicyStore('/policy/installations.sqlite');
$store->register($binding);
foreach (array('service-reading' => 'site:1', 'service-other' => 'site:2') as $installation => $site) {
    $store->create($installation, $site, $binding->image);
    $store->change($installation, 1, true, array('content.read'), $binding->image);
}
for ($number = 0; $number < 32; $number++) {
    $installation = 'scale-' . $number;
    $store->create($installation, 'site:' . (100 + $number), $binding->image);
    $store->change($installation, 1, true, array('content.read'), $binding->image);
}
mkdir('/tmp/service-hostile', 0700);
copy('/app/packages/reading-summary/manifest.json', '/tmp/service-hostile/manifest.json');
copy(__DIR__ . '/hostile-worker.php', '/tmp/service-hostile/worker.php');
$hostile = PackageBuilder::build(Package::capture('/tmp/service-hostile'), (string) getenv('AXIOM_SDK_IMAGE'));
$store->register($hostile);
$store->create('service-hostile', 'site:1', $hostile->image);
$store->change('service-hostile', 1, true, array('content.read'), $hostile->image);
$manifest = json_decode($package->manifest, true, 8, JSON_THROW_ON_ERROR);
$manifest['plugin_id'] = 'axiompress/hostile-fixture';
mkdir('/tmp/service-foreign', 0700);
file_put_contents('/tmp/service-foreign/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
copy(__DIR__ . '/hostile-worker.php', '/tmp/service-foreign/worker.php');
$foreign = PackageBuilder::build(Package::capture('/tmp/service-foreign'), (string) getenv('AXIOM_SDK_IMAGE'));
$store->register($foreign);
$store->create('service-foreign', 'site:1', $foreign->image);
$store->change('service-foreign', 1, true, array('content.read'), $foreign->image);
echo $binding->image;
