<?php

declare(strict_types=1);

use AxiomPress\Core\ComponentRenderer;

require '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Operator rendering adapter is CLI-only.');
}
$input = stream_get_contents(STDIN, 1025);
if ($input === false || strlen($input) > 1024) {
    throw new RuntimeException('Component input limit.');
}
$component = json_decode($input, false, 4, JSON_THROW_ON_ERROR);
if (!$component instanceof stdClass) {
    throw new RuntimeException('Component object required.');
}
echo ComponentRenderer::readingSummary($component) . "\n";
