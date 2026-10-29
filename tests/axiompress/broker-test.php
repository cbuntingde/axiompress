<?php

declare(strict_types=1);

use AxiomPress\Broker\BrokerError;
use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Protocol\Protocol;

foreach (
    array('protocol/ProtocolError', 'protocol/Protocol', 'broker/BrokerError',
    'broker/InvocationRegistry', 'broker/ContentBroker') as $file
) {
    require_once __DIR__ . '/../../tools/axiompress/' . $file . '.php';
}
require_once __DIR__ . '/protocol-test-helpers.php';
$checks = 0;
$denied = static function (string $reason, callable $action): void {
    try {
        $action();
    } catch (BrokerError $error) {
        check($error->reason === $reason);
        return;
    }
    throw new RuntimeException('Expected denial: ' . $reason);
};
$now = 1_000_000;
$epoch = 1;
$enabled = true;
$access = true;
$reads = 0;
$generation = static function (string $installation, string $site) use (&$epoch, &$enabled): ?int {
    return $enabled ? $epoch : null;
};
$registry = new InvocationRegistry($generation, static function () use (&$now): int {
    return $now;
});
$allowed = static function (string $actor, string $site, string $id) use (&$access): bool {
    return $access && $actor === 'editor' && $site === 'site-a' && $id === 'article:4';
};
$reader = static function (string $site, string $id) use (&$reads): stdClass {
    ++$reads;
    check($site === 'site-a' && $id === 'article:4');
    return (object) array('content_id' => $id, 'revision' => 'v1', 'title' => 'Private',
        'text' => 'Allowed text', 'secret' => 'must-not-leak');
};
$broker = new ContentBroker($registry, $allowed, $reader);
$make = static fn(string $handle, string $id = 'r1', string $content = 'article:4'): stdClass => (object) array(
    'api' => Protocol::API, 'kind' => 'request', 'id' => $id, 'operation' => 'content.read',
    'invocation' => $handle, 'params' => (object) array('content_id' => $content)
);
$handle = $registry->issue('channel-a', 'plugin-a', 'site-a', 'editor', array('article:4'));
check(strlen($handle) === 64);
check($registry->context('channel-a', $handle)['actor'] === 'editor');
$denied('unauthenticated', fn() => $broker->handle('channel-b', $make($handle)));
$denied('unauthenticated', fn() => $broker->handle('channel-a', $make(str_repeat('0', 64))));
check($reads === 0);
$response = $broker->handle('channel-a', $make($handle));
check($response->result->text === 'Allowed text');
check(!property_exists($response->result, 'secret'));
$denied('conflict', fn() => $broker->handle('channel-a', $make($handle)));
$denied('not_found', fn() => $broker->handle('channel-a', $make($handle, 'r2', 'other-site:4')));
$access = false;
$denied('not_found', fn() => $broker->handle('channel-a', $make($handle, 'r3')));
check($reads === 1);
$access = true;
for ($i = 4; $i <= 8; ++$i) {
    $broker->handle('channel-a', $make($handle, 'r' . $i));
}
$denied('quota_exceeded', fn() => $broker->handle('channel-a', $make($handle, 'r9')));
$registry->closeConnection('channel-a');
$denied('unauthenticated', fn() => $registry->context('channel-a', $handle));

// Generation change and explicit disable invalidate already-issued authority.
$handle = $registry->issue('channel-a', 'plugin-a', 'site-a', 'editor', array('article:4'));
++$epoch;
$denied('forbidden', fn() => $registry->context('channel-a', $handle));
$denied('unauthenticated', fn() => $registry->context('channel-a', $handle));
$handle = $registry->issue('channel-a', 'plugin-a', 'site-a', 'editor', array('article:4'));
$enabled = false;
$denied('forbidden', fn() => $registry->context('channel-a', $handle));
$denied('forbidden', fn() => $registry->issue('channel-b', 'plugin-b', 'site-a', 'editor', array('article:4')));
$enabled = true;
$handle = $registry->issue('channel-a', 'plugin-a', 'site-a', 'editor', array('article:4'));
$now += 5_000_000_000;
$denied('deadline_exceeded', fn() => $registry->context('channel-a', $handle));

// One active per installation/site; four globally; pruning releases expired slots.
$handles = array();
for ($i = 0; $i < 4; ++$i) {
    $handles[] = $registry->issue('c' . $i, 'p' . $i, 'site-a', 'editor', array('article:4'));
}
$denied('quota_exceeded', fn() => $registry->issue('c5', 'p5', 'site-a', 'editor', array('article:4')));
$denied('quota_exceeded', fn() => $registry->issue('other', 'p0', 'site-a', 'editor', array('article:4')));
$registry->revoke('p0', 'site-a');
$denied('unauthenticated', fn() => $registry->context('c0', $handles[0]));
$now += 5_000_000_000;
$handle = $registry->issue('new', 'new-plugin', 'site-a', 'editor', array('article:4'));
$registry->begin('new', $handle, 'busy', 'article:4');
$denied('quota_exceeded', fn() => $registry->begin('new', $handle, 'nested', 'article:4'));
$registry->finish('new', $handle);
$registry->closeConnection('new');

// Revoke during adapter access: no private result can escape the final recheck.
$handle = $registry->issue('race', 'plugin-a', 'site-a', 'editor', array('article:4'));
$revokingRead = static function (string $site, string $id) use ($reader, &$epoch): stdClass {
    $content = $reader($site, $id);
    ++$epoch;
    return $content;
};
$racingBroker = new ContentBroker($registry, $allowed, $revokingRead);
$denied('forbidden', fn() => $racingBroker->handle('race', $make($handle)));
$handle = $registry->issue('race2', 'plugin-a', 'site-a', 'editor', array('article:4'));
$revokingActor = static function (string $site, string $id) use ($reader, &$access): stdClass {
    $content = $reader($site, $id);
    $access = false;
    return $content;
};
$racingBroker = new ContentBroker($registry, $allowed, $revokingActor);
$denied('not_found', fn() => $racingBroker->handle('race2', $make($handle)));
$registry->closeConnection('race2');
$restarted = new InvocationRegistry($generation);
$denied('unauthenticated', fn() => $restarted->context('race2', $handle));

$access = true;
$cross = $registry->issue('cross', 'other-plugin', 'site-b', 'editor', array('article:4'));
$before = $reads;
$denied('not_found', fn() => $broker->handle('cross', $make($cross)));
check($reads === $before);
$registry->closeConnection('cross');
$cross = $registry->issue('cross', 'other-plugin', 'site-a', 'visitor', array('article:4'));
$denied('not_found', fn() => $broker->handle('cross', $make($cross)));
check($reads === $before);
$registry->closeConnection('cross');
$handle = $registry->issue('failure', 'plugin-a', 'site-a', 'editor', array('article:4'));
$failing = new ContentBroker($registry, $allowed, static function (): never {
    throw new RuntimeException('Internal credentials must not become a wire message.');
});
$denied('internal_error', fn() => $failing->handle('failure', $make($handle)));
// The failed adapter still consumes its request ID and releases the in-flight slot.
$denied('conflict', fn() => $broker->handle('failure', $make($handle)));
check($broker->handle('failure', $make($handle, 'retry'))->result->content_id === 'article:4');
$registry->closeConnection('failure');
$denied('invalid_request', fn() => $registry->issue('bad/channel', 'p', 's', 'a', array('x')));
$denied('invalid_request', fn() => $registry->issue('c', 'p', 's', 'a', array()));
$denied('invalid_request', fn() => $registry->issue('c', 'p', 's', 'a', array(4)));
$brokenRegistry = new InvocationRegistry(static function (): never {
    throw new RuntimeException('Grant store unavailable.');
});
$denied('internal_error', fn() => $brokenRegistry->issue('c', 'p', 's', 'a', array('x')));
echo "PASS: {$checks} broker checks\n";
