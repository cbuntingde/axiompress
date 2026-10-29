<?php

declare(strict_types=1);

use AxiomPress\Core\Configuration;
use AxiomPress\Core\ContentLimitExceeded;
use AxiomPress\Core\ExtensionClient;
use AxiomPress\Core\Kernel;
use AxiomPress\Core\PublicContentRepository;
use AxiomPress\Core\Response;

require dirname(__DIR__) . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $socket = getenv('AXIOMPRESS_BROKER_SOCKET');
    $extension = $socket === false ? null : new ExtensionClient(
        $socket,
        (string) getenv('AXIOMPRESS_READING_INSTALLATION')
    );
    $kernel = new Kernel(static fn(): PublicContentRepository =>
        PublicContentRepository::connect(Configuration::fromEnvironment()), $extension);
    $response = $kernel->handle($method, $_SERVER['REQUEST_URI'] ?? '');
    // Encoding is part of the operation: invalid stored UTF-8 cannot produce a half-written success.
    $response->body();
} catch (ContentLimitExceeded $error) {
    $response = new Response(413, array('error' => 'content_limit_exceeded'));
} catch (Throwable $error) {
    $diagnostic = bin2hex(random_bytes(12));
    // No exception message, query, content, environment, credential or request URI enters diagnostics.
    error_log('axiompress.core unavailable diagnostic=' . $diagnostic . ' class=' . get_class($error));
    $response = new Response(503, array('error' => 'unavailable', 'diagnostic_id' => $diagnostic));
}
$response->send($method === 'HEAD');
