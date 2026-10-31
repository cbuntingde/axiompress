<?php

declare(strict_types=1);

use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Supervisor\DirectRuntime;

require dirname(__DIR__) . '/policy/bootstrap.php';

if (count($argv) !== 9) {
    throw new RuntimeException('Use CONFIG ARTIFACT PACKAGE_DIRECTORY POLICY_DATABASE '
        . 'INSTALLATION SITE create|register SIGNATURE_FILE.');
}
if (!in_array($argv[7], array('create', 'register'), true)) {
    throw new RuntimeException('Unknown local package registration operation.');
}
if (!is_file($argv[8]) || is_link($argv[8])) {
    throw new RuntimeException('Operator-owned publisher signature file required.');
}
try {
    $envelope = json_decode((string) file_get_contents($argv[8]), true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    throw new RuntimeException('Malformed publisher signature envelope.', 0, $error);
}
if (!is_array($envelope)) {
    throw new RuntimeException('Malformed publisher signature envelope.');
}
$runtime = new DirectRuntime($argv[1]);
$binding = ImageBinding::fromBundle(Package::capture($argv[3]), $argv[2], $runtime->describe($argv[2]));
$policy = new PolicyStore($argv[4]);
$policy->register($binding, $envelope);
if ($argv[7] === 'create') {
    $policy->create($argv[5], $argv[6], $argv[2]);
}
echo json_encode(array('artifact' => $argv[2], 'created' => $argv[7] === 'create'), JSON_THROW_ON_ERROR) . "\n";
