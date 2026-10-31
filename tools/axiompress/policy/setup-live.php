<?php

declare(strict_types=1);

use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PackageBuilder;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Policy\PublisherApproval;

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
$signature = getenv('AXIOM_SIGNATURE_JSON');
$artifactId = getenv('AXIOM_ARTIFACT');
$package = Package::capture('/app/packages/reading-summary');
$sdkImage = (string) getenv('AXIOM_SDK_IMAGE');
if (is_string($artifactId) && $artifactId !== '') {
    // Operator-built artifacts are inspected, never rebuilt: image identifiers
    // vary per build, so a rebuild would invalidate a pre-made signature.
    $binding = ImageBinding::inspect(
        $package,
        PackageBuilder::inspectImage($artifactId),
        $sdkImage
    );
} else {
    if (is_string($signature) && $signature !== '') {
        throw new RuntimeException('Explicit signatures require a prebuilt artifact.');
    }
    $binding = PackageBuilder::build($package, $sdkImage);
}
$store = new PolicyStore('/policy/installations.sqlite');
if (is_string($signature) && $signature !== '') {
    $envelope = json_decode($signature, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) {
        throw new RuntimeException('Malformed publisher signature envelope.');
    }
    if ($store->listPublishers() === array()) {
        $store->addPublisher(PublisherApproval::fromEnvelope($envelope)->publicKey());
    }
} else {
    if ($store->listPublishers() !== array()) {
        throw new RuntimeException('Operator publisher signature required.');
    }
    $operatorKey = openssl_pkey_new(
        array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA)
    );
    $operatorDetails = $operatorKey === false ? false : openssl_pkey_get_details($operatorKey);
    if (!is_array($operatorDetails) || !isset($operatorDetails['key'])) {
        throw new RuntimeException('Prototype publisher key generation failed.');
    }
    if (
        !openssl_sign(
            PublisherApproval::canonicalBytes($binding),
            $operatorSignature,
            $operatorKey,
            OPENSSL_ALGO_SHA256
        )
    ) {
        throw new RuntimeException('Prototype publisher signing failed.');
    }
    $envelope = array(
        'algorithm' => 'rsa2048-sha256',
        'public_key' => $operatorDetails['key'],
        'signature' => base64_encode($operatorSignature),
    );
    $store->addPublisher($operatorDetails['key']);
}
$store->register($binding, $envelope);
$store->create($id, $site, $binding->image);
$generation = $store->change($id, 1, true, array('content.read'), $binding->image);
echo json_encode(array('installation' => $id, 'site' => $site, 'generation' => $generation,
    'image' => $binding->image, 'package_digest' => $package->digest), JSON_THROW_ON_ERROR) . "\n";
