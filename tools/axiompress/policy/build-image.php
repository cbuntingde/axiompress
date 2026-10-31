<?php

declare(strict_types=1);

use AxiomPress\Policy\Package;
use AxiomPress\Policy\PackageBuilder;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/PackageBuilder.php';

$sdkImage = getenv('AXIOM_SDK_IMAGE');
if (!is_string($sdkImage) || $sdkImage === '') {
    throw new RuntimeException('Approved SDK image required.');
}
$package = Package::capture('/app/packages/reading-summary');
$binding = PackageBuilder::build($package, $sdkImage);
echo json_encode(array(
    'artifact' => $binding->image,
    'plugin_id' => $binding->package->pluginId,
    'version' => $binding->package->version,
    'package_digest' => $binding->package->digest,
    'manifest_digest' => $binding->package->manifestDigest,
), JSON_THROW_ON_ERROR) . "\n";
