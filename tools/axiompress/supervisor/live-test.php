<?php

declare(strict_types=1);

use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Supervisor\CoreBridgeClient;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../policy/bootstrap.php';
require __DIR__ . '/Supervisor.php';
require __DIR__ . '/CoreBridgeClient.php';
require __DIR__ . '/LiveFixtureControl.php';
$values = array();
foreach (
    array('AXIOM_CORE_CONTAINER', 'AXIOM_CORE_IMAGE', 'AXIOM_INSTALLATION', 'AXIOM_SITE',
    'AXIOM_ACTOR', 'AXIOM_CONTENT') as $name
) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing trusted live-test configuration.');
    }
    $values[$name] = $value;
}
$actor = $values['AXIOM_ACTOR'];
$site = $values['AXIOM_SITE'];
$contentId = $values['AXIOM_CONTENT'];
(new \AxiomPress\Supervisor\AdmissionGate('/admission'))->recover(Supervisor::reconcile(...));
$client = new CoreBridgeClient($values['AXIOM_CORE_CONTAINER'], $values['AXIOM_CORE_IMAGE'], $actor, $site, $contentId);
$control = null;
try {
    if (getenv('AXIOM_TEST_REVOCATION') === '1') {
        $control = new \AxiomPress\Supervisor\LiveFixtureControl(
            (string) getenv('AXIOM_FIXTURE_CLI'),
            substr($contentId, 5),
            (string) getenv('AXIOM_FIXTURE_TOKEN')
        );
        if ($actor !== 'anonymous' || $client->read($site, $contentId) !== null) {
            throw new RuntimeException('Expected initially private anonymous fixture.');
        }
        $control->visibility('publish');
    }
    $expected = $client->read($site, $contentId);
    $policy = new \AxiomPress\Policy\PolicyStore('/policy/installations.sqlite', true);
    $installation = $values['AXIOM_INSTALLATION'];
    $supervisor = Supervisor::fromPolicy($policy, $installation, $site, $client->canRead(...), $client->read(...));
    $denied = false;
    try {
        $result = $supervisor->run($installation, $site, $actor, $contentId);
    } catch (RuntimeException $error) {
        if (
            $expected !== null || $error->getMessage() !== 'incomplete_worker'
            || $supervisor->lastBrokerDenial() !== 'not_found'
        ) {
            throw $error;
        }
        $denied = true;
    }
    if ($expected !== null) {
        $words = preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x{0027}][\p{L}\p{N}]+)*/u', $expected->text);
        if (
            $result->component->word_count !== $words
            || $result->component->reading_seconds !== (int) ceil($words * 0.3)
        ) {
            throw new RuntimeException('Live component does not match core content.');
        }
    } elseif (!$denied) {
        throw new RuntimeException('Unauthorized content produced a component.');
    }
    if ($control !== null) {
        $control->visibility('private');
        if ($client->read($site, $contentId) !== null) {
            throw new RuntimeException('Core bridge reused stale visibility.');
        }
        try {
            $supervisor->run($installation, $site, $actor, $contentId);
            throw new LogicException('Revoked content produced a component.');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'incomplete_worker' || $supervisor->lastBrokerDenial() !== 'not_found') {
                throw $error;
            }
        }
    }
    if (Supervisor::remainingWorkers() !== array()) {
        throw new RuntimeException('Live worker cleanup failed.');
    }
    echo json_encode(array('site' => $site, 'actor' => $actor, 'content_id' => $contentId,
        'outcome' => $control !== null ? 'revocation-passed' : ($denied ? 'denied' : 'passed'),
        'component' => $denied ? null : $result->component,
        'worker_image' => $supervisor->workerImage(), 'installation' => $installation), JSON_THROW_ON_ERROR) . "\n";
} finally {
    try {
        if ($control !== null) {
            $control->visibility('private');
        }
    } finally {
        $client->close();
    }
}
