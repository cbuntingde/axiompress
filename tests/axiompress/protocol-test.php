<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/axiompress/protocol/ProtocolError.php';
require_once __DIR__ . '/../../tools/axiompress/protocol/Protocol.php';

use AxiomPress\Protocol\Protocol;
use AxiomPress\Protocol\ProtocolError;
use AxiomPress\Tests\FragmentedStream;

require_once __DIR__ . '/protocol-test-helpers.php';

$checks = 0;
$request = (object) array(
    'api' => Protocol::API, 'kind' => 'request', 'id' => 'r1', 'operation' => 'content.read',
    'invocation' => str_repeat('a', 64), 'params' => (object) array('content_id' => 'article:4')
);
$frame = Protocol::encode($request);
$stream = streamFor($frame . $frame);
check(Protocol::read($stream) == $request);
check(Protocol::read($stream) == $request);
check(Protocol::read($stream) === null);
fclose($stream);
// Every truncation boundary, including partial headers and partial bodies.
for ($i = 1; $i < strlen($frame); ++$i) {
    $stream = streamFor(substr($frame, 0, $i));
    try {
        rejects(fn() => Protocol::read($stream));
    } finally {
        fclose($stream);
    }
}
foreach (array(0, Protocol::MAX_BYTES + 1, 4294967295) as $length) {
    $stream = streamFor(pack('N', $length));
    rejects(fn() => Protocol::read($stream));
    fclose($stream);
}
$json = json_encode($request, JSON_THROW_ON_ERROR);
$maxBody = $json . str_repeat(' ', Protocol::MAX_BYTES - strlen($json));
$stream = streamFor(pack('N', strlen($maxBody)) . $maxBody);
check(Protocol::read($stream) == $request);
fclose($stream);
foreach (
    array(
    '', 'null', '[]', '1', '{', $json . 'x', "{\"bad\":\"\xff\"}",
    str_replace('"id":"r1"', '"id":"r1","id":"r2"', $json),
    str_replace('"id":"r1"', '"id":"r1","\\u0069d":"r2"', $json),
    str_replace('"content_id":"article:4"', '"content_id":"article:4","content_id":"other"', $json),
    str_repeat('[', 17) . '0' . str_repeat(']', 17),
    str_repeat(' ', Protocol::MAX_BYTES + 1),
    ) as $invalid
) {
    try {
        rejects(fn() => Protocol::decode($invalid));
    } catch (RuntimeException $e) {
        throw new RuntimeException("Rejected-case failure: " . $invalid, 0, $e);
    }
}
foreach (array('storage.put', 'content.propose_revision', 'content.publish', 'http.fetch') as $operation) {
    $bad = clone $request;
    $bad->operation = $operation;
    rejects(fn() => Protocol::encode($bad));
}
$bad = clone $request;
$bad->actor = 'admin';
rejects(fn() => Protocol::encode($bad));
$bad = clone $request;
$bad->params = array('content_id' => 'article:4');
rejects(fn() => Protocol::encode($bad));
$bad = clone $request;
$bad->invocation = 'forged';
rejects(fn() => Protocol::encode($bad));
rejects(fn() => Protocol::read(null));
$dispatch = clone $request;
$dispatch->kind = 'dispatch';
$dispatch->operation = 'component.render';
check(Protocol::decode(substr(Protocol::encode($dispatch), 4)) == $dispatch);
$completion = (object) array(
    'api' => Protocol::API, 'kind' => 'completion', 'id' => 'r1',
    'result' => (object) array('type' => 'reading_summary', 'word_count' => 10, 'reading_seconds' => 3)
);
check(Protocol::decode(substr(Protocol::encode($completion), 4)) == $completion);
$completion->result->word_count = 1.5;
rejects(fn() => Protocol::encode($completion));
$content = (object) array(
    'api' => Protocol::API, 'kind' => 'response', 'id' => 'r1',
    'result' => (object) array(
        'content_id' => 'article:4', 'revision' => 'v1', 'title' => 'Unicode café',
        'text' => 'Literal {"key":"value"}, backslash \\ and bracket [ are content.'
    )
);
check(Protocol::decode(substr(Protocol::encode($content), 4)) == $content);
$fixtures = json_decode(file_get_contents(__DIR__ . '/protocol-fixtures.json'), false, 32, JSON_THROW_ON_ERROR);
foreach ($fixtures->messages as $case) {
    if ($case->valid) {
        check(Protocol::decode(substr(Protocol::encode($case->message), 4)) == $case->message);
    } else {
        rejects(fn() => Protocol::encode($case->message));
    }
}
require_once __DIR__ . '/FragmentedStream.php';
check(stream_wrapper_register('axiomfragment', FragmentedStream::class));
try {
    FragmentedStream::$data = $frame . $frame;
    $stream = fopen('axiomfragment://test', 'rb');
    try {
        check(Protocol::read($stream) == $request);
        check(Protocol::read($stream) == $request);
        check(Protocol::read($stream) === null);
    } finally {
        fclose($stream);
    }
} finally {
    stream_wrapper_unregister('axiomfragment');
}
echo "PASS: {$checks} protocol checks\n";
