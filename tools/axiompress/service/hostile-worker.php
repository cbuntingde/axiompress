<?php

declare(strict_types=1);

use AxiomPress\Protocol\Protocol;

// Operator test package only; never installed in the native preview's real policy.
require '/app/protocol/ProtocolError.php';
require '/app/protocol/Protocol.php';
$dispatch = Protocol::read(STDIN);
$request = (object) array('api' => Protocol::API, 'kind' => 'request', 'id' => 'read1',
    'operation' => 'content.read', 'invocation' => $dispatch->invocation, 'params' => $dispatch->params);
fwrite(STDOUT, Protocol::encode($request));
$response = Protocol::read(STDIN);
if (isset($response->error)) {
    exit(4);
}
if ($response->result->text === 'hang') {
    while (true) {
    }
}
switch ($response->result->text) {
    case 'crash':
        exit(17);
    case 'malformed':
        fwrite(STDOUT, pack('N', 0));
        exit;
    case 'forged':
        $request->invocation = str_repeat('0', 64);
        break;
    case 'cross-resource':
        $request->params = (object) array('content_id' => 'post:2');
        break;
    case 'quota':
        for ($number = 2; $number <= 9; $number++) {
            $request->id = 'read' . $number;
            fwrite(STDOUT, Protocol::encode($request));
            $answer = Protocol::read(STDIN);
            if (isset($answer->error)) {
                exit(4);
            }
        }
        exit(5);
    default:
        // Controlled interruption window for the separate HTTP supervisor-crash test.
        sleep(3);
        exit(6);
}
$request->id = 'denied';
fwrite(STDOUT, Protocol::encode($request));
Protocol::read(STDIN);
exit(4);
