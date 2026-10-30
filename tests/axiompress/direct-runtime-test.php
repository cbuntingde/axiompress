<?php

declare(strict_types=1);

use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\Package;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Supervisor\DirectRuntime;
use AxiomPress\Supervisor\Supervisor;

require $argv[1] . '/policy/bootstrap.php';
require $argv[1] . '/supervisor/Supervisor.php';

if (PHP_VERSION_ID < 80500 || PHP_INT_SIZE !== 8 || !extension_loaded('pcntl')) {
    throw new RuntimeException('64-bit PHP 8.5 with pcntl required.');
}
$configuration = json_decode(file_get_contents($argv[2]), true, 8, JSON_THROW_ON_ERROR);
$direct = new DirectRuntime($configuration['config']);
$package = Package::capture($configuration['package']);
$binding = ImageBinding::fromBundle(
    $package,
    $configuration['artifact'],
    $direct->describe($configuration['artifact'])
);
$policy = new PolicyStore($configuration['policy']);
$policy->register($binding);
$policy->create('direct-check', 'site:1', $binding->image);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$allowed = static fn(string $actor, string $site, string $id): bool =>
    $actor === 'anonymous' && $site === 'site:1' && $id === 'post:4';
$read = static fn(string $site, string $id): stdClass => (object) array(
    'content_id' => 'post:4', 'revision' => 'revision:1', 'title' => 'Direct runtime', 'text' => 'one two three',
);
try {
    Supervisor::fromPolicy(
        $policy,
        'direct-check',
        'site:1',
        $allowed,
        $read,
        $configuration['admission'],
        'runsc',
        direct: $direct
    );
    throw new LogicException('Disabled package was allowed.');
} catch (RuntimeException $error) {
    $check($error->getMessage() === 'Installation is not authorized.', 'New package requires explicit approval');
}
$policy->change('direct-check', 1, true, array('content.read'), $binding->image);
$supervisor = Supervisor::fromPolicy(
    $policy,
    'direct-check',
    'site:1',
    $allowed,
    $read,
    $configuration['admission'],
    'runsc',
    direct: $direct
);
$result = $supervisor->run('direct-check', 'site:1', 'anonymous', 'post:4');
$check($result->component->word_count === 3, 'Actual direct PHP worker uses broker data');
$check($result->profile['backend'] === 'direct-runsc', 'Direct runtime selected explicitly');
$check(($supervisor->lastState()['cleanup_confirmed'] ?? false) === true, 'Direct process cleanup confirmed');
$policy->change('direct-check', 2, false, array(), $binding->image);
try {
    $supervisor->run('direct-check', 'site:1', 'anonymous', 'post:4');
    throw new LogicException('Revoked package was allowed.');
} catch (RuntimeException $error) {
    $check(true, 'Current grant revocation denies invocation');
}
$direct->recover();
$cases = array();
foreach (
    array('reading', 'probes', 'bad-frame', 'partial', 'stderr', 'memory', 'crash',
    'forged', 'extra', 'hang', 'fork', 'stdout', 'blocked-adapter') as $mode
) {
    $registry = new InvocationRegistry(static fn(string $installation, string $site): ?int =>
        $installation === 'hostile-check' && $site === 'site:1' ? 1 : null);
    $broker = new ContentBroker($registry, $allowed, static function (string $site, string $id) use ($mode): stdClass {
        if ($mode === 'blocked-adapter') {
            sleep(30);
        }
        return (object) array('content_id' => $id, 'revision' => 'revision:1',
            'title' => 'Direct runtime', 'text' => 'one two three');
    });
    $worker = new Supervisor(
        $configuration['hostile_artifact'],
        $registry,
        $broker,
        $mode === 'blocked-adapter' ? 'reading' : $mode,
        'runsc',
        direct: $direct
    );
    $started = hrtime(true);
    try {
        $result = $worker->run('hostile-check', 'site:1', 'anonymous', 'post:4');
        $check(in_array($mode, array('reading', 'probes'), true), 'Hostile worker did not complete normally');
        $check(
            $result->component->word_count === ($mode === 'probes' ? 14 : 3),
            'Direct filesystem/network controls: ' . $result->component->word_count
        );
    } catch (RuntimeException $error) {
        if (in_array($mode, array('reading', 'probes'), true)) {
            throw $error;
        }
        $expected = array('bad-frame' => 'invalid_frame', 'partial' => 'incomplete_worker',
            'stderr' => 'stderr_limit', 'memory' => 'incomplete_worker', 'crash' => 'incomplete_worker',
            'forged' => 'incomplete_worker', 'extra' => 'invalid_frame', 'hang' => 'deadline_exceeded',
            'fork' => 'deadline_exceeded', 'stdout' => 'stdout_limit', 'blocked-adapter' => 'deadline_exceeded');
        $check(
            $error->getMessage() === $expected[$mode],
            'Expected direct rejection: ' . $mode . ': ' . $error->getMessage()
        );
    }
    $check(($worker->lastState()['cleanup_confirmed'] ?? false) === true, 'Hostile worker cleanup confirmed');
    if ($mode === 'memory') {
        $check(($worker->lastState()['OOMKilled'] ?? false) === true, 'Direct host memory limit enforced');
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $check($elapsed < 10000, 'Direct worker deadline includes bounded cleanup');
    $cases[$mode] = array('milliseconds' => round($elapsed, 2), 'cleanup_confirmed' => true);
}
echo json_encode(array('outcome' => 'passed', 'checks' => $checks, 'cases' => $cases), JSON_THROW_ON_ERROR) . "\n";
