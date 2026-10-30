<?php

declare(strict_types=1);

use AxiomPress\Policy\PolicyStore;

require __DIR__ . '/bootstrap.php';
$command = $argv[1] ?? '';
$policyFile = getenv('AXIOM_POLICY_FILE') ?: '/policy/installations.sqlite';
$expectedCount = array('describe' => 4, 'disable' => 5, 'approve-read' => 6);
if (
    !isset($expectedCount[$command]) || count($argv) !== $expectedCount[$command]
    || !str_starts_with($policyFile, '/') || !is_file($policyFile)
) {
    throw new RuntimeException(
        'Use describe ID SITE, disable ID SITE GENERATION, or approve-read ID SITE GENERATION IMAGE.'
    );
}
$store = new PolicyStore($policyFile, $command === 'describe');
$record = $store->describe($argv[2], $argv[3]);
if ($record === null) {
    throw new RuntimeException('Unknown installation/site.');
}
if ($command !== 'describe') {
    $expected = filter_var($argv[4], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    if ($expected === false || !preg_match('/\A[1-9][0-9]*\z/D', $argv[4])) {
        throw new RuntimeException('Canonical expected generation required.');
    }
    $approve = $command === 'approve-read';
    $store->change(
        $argv[2],
        $expected,
        $approve,
        $approve ? array('content.read') : array(),
        $approve ? $argv[5] : $record['image']
    );
    $record = $store->describe($argv[2], $argv[3]);
}
echo json_encode($record, JSON_THROW_ON_ERROR) . "\n";
