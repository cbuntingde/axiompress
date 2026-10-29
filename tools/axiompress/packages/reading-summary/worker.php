<?php

declare(strict_types=1);

use AxiomPress\Protocol\Protocol;

require __DIR__ . '/protocol/ProtocolError.php';
require __DIR__ . '/protocol/Protocol.php';
$send = static function (stdClass $message): void {
    $frame = Protocol::encode($message);
    for ($offset = 0; $offset < strlen($frame);) {
        $written = fwrite(STDOUT, substr($frame, $offset));
        if ($written === false || $written === 0) {
            exit(3);
        }
        $offset += $written;
    }
};
$dispatch = Protocol::read(STDIN);
if ($dispatch === null || $dispatch->kind !== 'dispatch') {
    exit(2);
}
$send((object) array(
    'api' => Protocol::API, 'kind' => 'request', 'id' => 'read1', 'operation' => 'content.read',
    'invocation' => $dispatch->invocation, 'params' => $dispatch->params,
));
$response = Protocol::read(STDIN);
if (
    $response === null || $response->kind !== 'response' || $response->id !== 'read1'
    || isset($response->error) || $response->result->content_id !== $dispatch->params->content_id
) {
    exit(4);
}
$words = preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x{0027}][\p{L}\p{N}]+)*/u', $response->result->text);
$send((object) array(
    'api' => Protocol::API, 'kind' => 'completion', 'id' => $dispatch->id,
    'result' => (object) array('type' => 'reading_summary', 'word_count' => $words,
        'reading_seconds' => (int) ceil($words * 0.3)),
));
