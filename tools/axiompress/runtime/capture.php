<?php

declare(strict_types=1);

use AxiomPress\Policy\Package;

require dirname(__DIR__) . '/protocol/ProtocolError.php';
require dirname(__DIR__) . '/protocol/Protocol.php';
require dirname(__DIR__) . '/policy/Package.php';

if (PHP_VERSION_ID < 80500 || PHP_INT_SIZE !== 8 || PHP_SAPI !== 'cli' || count($argv) !== 2) {
    throw new RuntimeException('Use PHP 8.5 package capture with one local source directory.');
}
$package = Package::capture($argv[1]);
echo json_encode(array('plugin_id' => $package->pluginId, 'version' => $package->version,
    'digest' => $package->digest, 'manifest_digest' => $package->manifestDigest,
    'manifest' => $package->manifest, 'worker' => $package->worker), JSON_THROW_ON_ERROR);
