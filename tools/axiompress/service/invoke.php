<?php

declare(strict_types=1);

use AxiomPress\Policy\PolicyStore;
use AxiomPress\Service\Invocation;
use AxiomPress\Supervisor\Supervisor;

require dirname(__DIR__) . '/policy/bootstrap.php';
require dirname(__DIR__) . '/supervisor/Supervisor.php';
require __DIR__ . '/Invocation.php';

$stage = 'validate';
try {
    $bytes = stream_get_contents(STDIN, 200001);
    if ($bytes === false || strlen($bytes) > 200000) {
        throw new RuntimeException('Input limit.');
    }
    $input = json_decode($bytes, false, 8, JSON_THROW_ON_ERROR);
    if (!$input instanceof stdClass) {
        throw new RuntimeException('Object required.');
    }
    $invocation = new Invocation($input);
    $content = $invocation->content;
    $allowed = static fn(string $actor, string $site, string $id): bool =>
        $actor === 'anonymous' && $site === $invocation->site && $id === $content->content_id;
    $read = static fn(string $site, string $id): ?stdClass =>
        $site === $invocation->site && $id === $content->content_id ? $content : null;
    $runtime = getenv('AXIOM_SERVICE_RUNTIME') ?: 'runsc';
    $stage = 'policy';
    $supervisor = Supervisor::fromPolicy(
        new PolicyStore('/policy/installations.sqlite', true),
        $invocation->installation,
        $invocation->site,
        $allowed,
        $read,
        '/state/admission',
        $runtime,
        '/state/workers',
        (string) getenv('AXIOM_SERVICE_POOL')
    );
    $stage = 'invoke';
    $result = $supervisor->run($invocation->installation, $invocation->site, 'anonymous', $content->content_id);
    echo json_encode(array('component' => $result->component), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Do not copy runtime output, exceptions, input content or handles to core or diagnostics.
    fwrite(STDERR, 'axiompress.service invocation_failed stage=' . $stage
        . ' class=' . get_debug_type($error) . ' diagnostic=' . bin2hex(random_bytes(12)) . "\n");
    exit(1);
}
