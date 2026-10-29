<?php

declare(strict_types=1);

use AxiomPress\Policy\Package;
use AxiomPress\Policy\PackageBuilder;
use AxiomPress\Policy\PolicyStore;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/PackageBuilder.php';
$id = (string) getenv('AXIOM_INSTALLATION');
$site = (string) getenv('AXIOM_SITE');
if (
    getenv('AXIOM_APPROVE_LIVE_TEST') !== '1' || !preg_match('/\Aprobe-[a-f0-9]{32}\z/D', $id)
    || !preg_match('/\Asite:[1-9][0-9]*\z/D', $site)
) {
    throw new RuntimeException('Explicit synthetic-installation approval required.');
}
$package = Package::capture('/app/packages/reading-summary');
$binding = PackageBuilder::build($package, (string) getenv('AXIOM_SDK_IMAGE'));
$store = new PolicyStore('/policy/installations.sqlite');
$store->register($binding);
$store->create($id, $site, $binding->image);
$generation = $store->change($id, 1, true, array('content.read'), $binding->image);
echo json_encode(array('installation' => $id, 'site' => $site, 'generation' => $generation,
    'image' => $binding->image, 'package_digest' => $package->digest), JSON_THROW_ON_ERROR) . "\n";
