<?php

declare(strict_types=1);

use AxiomPress\Protocol\Protocol;

require __DIR__ . '/protocol/ProtocolError.php';
require __DIR__ . '/protocol/Protocol.php';
$dispatch = Protocol::read(STDIN);
if ($dispatch === null || $dispatch->kind !== 'dispatch') {
    exit(2);
}
$mode = getenv('AXIOM_WORKER_MODE');
if ($mode === 'hang') {
    while (true) {
    }
}
if ($mode === 'bad-frame') {
    fwrite(STDOUT, pack('N', 0));
    exit;
}
if ($mode === 'partial') {
    fwrite(STDOUT, "\0\0");
    exit;
}
if ($mode === 'stderr') {
    while (true) {
        fwrite(STDERR, str_repeat('x', 8192));
    }
}
if ($mode === 'memory') {
    ini_set('memory_limit', '-1');
    $tooLarge = str_repeat('x', 200 * 1024 * 1024);
    exit;
}
if ($mode === 'crash') {
    exit(17);
}
if ($mode === 'fork') {
    // Fixed hostile fixture: OS PID cgroup must bound shell children; supervisor kills the container.
    passthru('while true; do sleep 30 & done');
    exit;
}
$count = 0;
$seconds = 0;
if ($mode === 'probes') {
    $status = file_get_contents('/proc/self/status');
    $count += preg_match('/^Uid:\s+10001\s/m', $status) === 1 ? 1 : 0;
    $count += preg_match('/^CapEff:\s+0+\s*$/m', $status) === 1 ? 1 : 0;
    $count += preg_match('/^NoNewPrivs:\s+1\s*$/m', $status) === 1 ? 1 : 0;
    $count += preg_match('/^Seccomp:\s+2\s*$/m', $status) === 1 ? 1 : 0;
    $count += @file_put_contents('/app/escape', 'x') === false ? 1 : 0;
    $count += !file_exists('/var/run/docker.sock') ? 1 : 0;
    $count += !file_exists('/var/www/wp-config.php') ? 1 : 0;
    $count += getenv('AXIOMPRESS_CORE_SECRET') === false ? 1 : 0;
    $socket = @fsockopen('1.1.1.1', 53, $errno, $error, 0.2);
    $count += $socket === false ? 1 : 0;
    if (is_resource($socket)) {
        fclose($socket);
    }
} else {
    $request = (object) array(
        'api' => Protocol::API, 'kind' => 'request', 'id' => 'read1', 'operation' => 'content.read',
        'invocation' => $mode === 'forged' ? str_repeat('0', 64) : $dispatch->invocation,
        'params' => $dispatch->params,
    );
    $frame = Protocol::encode($request);
    if ($mode === 'stdout') {
        for ($i = 1; $i <= 8; ++$i) {
            $request->id = 'read' . $i;
            $body = json_encode($request, JSON_THROW_ON_ERROR);
            $frame = pack('N', Protocol::MAX_BYTES) . str_pad($body, Protocol::MAX_BYTES, ' ');
            for ($offset = 0; $offset < strlen($frame);) {
                $written = fwrite(STDOUT, substr($frame, $offset));
                if ($written === false || $written === 0) {
                    exit(3);
                }
                $offset += $written;
            }
            if (Protocol::read(STDIN) === null) {
                exit(3);
            }
        }
        exit(3);
    }

    for ($offset = 0; $offset < strlen($frame);) {
        $written = fwrite(STDOUT, substr($frame, $offset));
        if ($written === false || $written === 0) {
            exit(3);
        }
        $offset += $written;
    }
    $response = Protocol::read(STDIN);
    if ($response === null || isset($response->error)) {
        exit(4);
    }
    $count = preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x{0027}][\p{L}\p{N}]+)*/u', $response->result->text);
    $seconds = (int) ceil($count * 60 / 200);
}
$completion = (object) array(
    'api' => Protocol::API, 'kind' => 'completion', 'id' => $dispatch->id,
    'result' => (object) array('type' => 'reading_summary', 'word_count' => $count, 'reading_seconds' => $seconds),
);
$frame = Protocol::encode($completion);
for ($offset = 0; $offset < strlen($frame);) {
    $written = fwrite(STDOUT, substr($frame, $offset));
    if ($written === false || $written === 0) {
        exit(3);
    }
    $offset += $written;
}
if ($mode === 'extra') {
    fwrite(STDOUT, 'unexpected trailing output');
}
