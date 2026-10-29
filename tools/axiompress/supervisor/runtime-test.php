<?php

declare(strict_types=1);

use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/../broker/bootstrap.php';
require __DIR__ . '/Supervisor.php';
$image = getenv('AXIOM_WORKER_IMAGE');
if (!is_string($image)) {
    throw new RuntimeException('Immutable worker image ID required.');
}
Supervisor::reconcile();
$checks = 0;
$results = array();
foreach (
    array('reading', 'probes', 'bad-frame', 'partial', 'stderr', 'memory', 'crash', 'forged', 'extra',
    'hang', 'fork', 'stdout', 'blocked-adapter') as $mode
) {
    $registry = new InvocationRegistry(static fn(string $installation, string $site): ?int =>
        $installation === 'test-plugin' && $site === 'site-a' ? 1 : null);
    $broker = new ContentBroker(
        $registry,
        static fn(string $actor, string $site, string $id): bool =>
            $actor === 'editor' && $site === 'site-a' && $id === 'post:1',
        static function (string $site, string $id) use ($mode): stdClass {
            if ($mode === 'blocked-adapter') {
                sleep(30);
            }
            return (object) array(
                'content_id' => $id, 'revision' => 'v1', 'title' => 'Fixture', 'text' => 'One two three four five.',
            );
        }
    );
    $supervisor = new Supervisor($image, $registry, $broker, $mode === 'blocked-adapter' ? 'reading' : $mode);
    $start = hrtime(true);
    try {
        $result = $supervisor->run('test-plugin', 'site-a', 'editor', 'post:1');
        $expectedCount = $mode === 'probes' ? 14 : 5;
        if (!in_array($mode, array('reading', 'probes'), true) || $result->component->word_count !== $expectedCount) {
            throw new LogicException('Unexpected successful worker result.');
        }
        $results[$mode] = array('outcome' => 'passed', 'profile' => $result->profile);
    } catch (LogicException $error) {
        throw $error;
    } catch (Throwable $error) {
        if (in_array($mode, array('reading', 'probes'), true)) {
            throw $error;
        }
        $expected = array(
            'bad-frame' => 'invalid_frame', 'partial' => 'incomplete_worker', 'stderr' => 'stderr_limit',
            'memory' => 'incomplete_worker', 'crash' => 'incomplete_worker', 'forged' => 'incomplete_worker',
            'extra' => 'invalid_frame', 'hang' => 'deadline_exceeded', 'fork' => 'deadline_exceeded',
            'stdout' => 'stdout_limit', 'blocked-adapter' => 'deadline_exceeded',
        );
        if ($error->getMessage() !== $expected[$mode]) {
            throw new RuntimeException('Wrong rejection for ' . $mode, 0, $error);
        }
        $results[$mode] = array('outcome' => 'rejected', 'reason' => $error->getMessage());
    }
    ++$checks;
    $results[$mode]['state'] = $supervisor->lastState();
    if ($mode === 'memory' && !($results[$mode]['state']['OOMKilled'] ?? false)) {
        throw new RuntimeException('Memory probe did not trigger the OS memory limit.');
    }
    $results[$mode]['milliseconds'] = round((hrtime(true) - $start) / 1_000_000, 2);
    // Do not hide cleanup failures with reconciliation: inventory must already be empty.
    if (Supervisor::remainingWorkers() !== array()) {
        throw new RuntimeException('Worker cleanup inventory is not empty.');
    }
    ++$checks;
    echo json_encode(array('mode' => $mode, 'outcome' => $results[$mode]['outcome'],
        'milliseconds' => $results[$mode]['milliseconds']), JSON_THROW_ON_ERROR) . "\n";
}
echo json_encode(array('checks' => $checks, 'image' => $image, 'results' => $results), JSON_THROW_ON_ERROR) . "\n";
