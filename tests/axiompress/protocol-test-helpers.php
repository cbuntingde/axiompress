<?php

declare(strict_types=1);

use AxiomPress\Protocol\ProtocolError;

function check(bool $condition): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('Assertion failed.');
    }
}
function rejects(callable $action): void
{
    try {
        $action();
    } catch (ProtocolError $error) {
        check(true);
        return;
    }
    throw new RuntimeException('Expected protocol rejection.');
}
function streamFor(string $data)
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $data);
    rewind($stream);
    return $stream;
}
