<?php

declare(strict_types=1);

use AxiomPress\Policy\AuditLog;
use AxiomPress\Policy\PolicyStore;

require __DIR__ . '/bootstrap.php';
$command = $argv[1] ?? '';
$policyFile = getenv('AXIOM_POLICY_FILE') ?: '/policy/installations.sqlite';
$auditPath = getenv('AXIOM_POLICY_AUDIT_FILE') ?: '';
$auditMaxBytes = 1073741824;
if (($rawMax = getenv('AXIOM_POLICY_AUDIT_MAX_BYTES')) !== false && $rawMax !== '') {
    $auditMaxBytes = filter_var($rawMax, FILTER_VALIDATE_INT, array('options' => array('min_range' => 4097)));
    if ($auditMaxBytes === false) {
        throw new RuntimeException('Canonical audit spool capacity required.');
    }
}
$auditMaxArchives = 30;
if (($rawArchives = getenv('AXIOM_POLICY_AUDIT_MAX_ARCHIVES')) !== false && $rawArchives !== '') {
    $auditMaxArchives = filter_var(
        $rawArchives,
        FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 0, 'max_range' => 10000))
    );
    if ($auditMaxArchives === false) {
        throw new RuntimeException('Canonical audit archive budget required.');
    }
}
$audit = $auditPath === '' ? null : AuditLog::open($auditPath, $auditMaxBytes, $auditMaxArchives);
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
    $image = $approve ? $argv[5] : $record['image'];
    $mutation = static fn() => $store->change(
        $argv[2],
        $expected,
        $approve,
        $approve ? array('content.read') : array(),
        $image
    );
    if ($audit === null) {
        $mutation();
    } else {
        $audit->record($mutation, array('action' => $command, 'installation' => $argv[2],
            'site' => $argv[3], 'expected_generation' => $expected, 'image' => $image,
            'grants' => $approve ? 'content.read' : ''));
    }
    $record = $store->describe($argv[2], $argv[3]);
}
echo json_encode($record, JSON_THROW_ON_ERROR) . "\n";
