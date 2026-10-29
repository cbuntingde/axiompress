<?php

declare(strict_types=1);

// Trusted local probe: wp eval-file tools/axiompress/broker/cli-read.php POST_ID USER_ID.

use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\BrokerError;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Broker\WordPressContentAdapter;
use AxiomPress\Protocol\Protocol;

if (!defined('WP_CLI') || !WP_CLI || !isset($args) || count($args) !== 2) {
    throw new RuntimeException('Run with WP-CLI eval-file and a post ID and user ID (0 for anonymous).');
}
require_once __DIR__ . '/bootstrap.php';
foreach ($args as $arg) {
    if (
        !is_string($arg) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $arg)
        || filter_var($arg, FILTER_VALIDATE_INT) === false
    ) {
        throw new RuntimeException('Invalid probe identifier.');
    }
}
// This local operator probe grants exactly one read. It is not a production grant store.
$adapter = new WordPressContentAdapter();
$site = 'site:' . get_current_blog_id();
$actor = $args[1] === '0' ? 'anonymous' : 'user:' . $args[1];
$content = 'post:' . $args[0];
$registry = new InvocationRegistry(static fn(string $installation, string $requestedSite): ?int =>
    $installation === 'local-probe' && $requestedSite === $site ? 1 : null);
$handle = $registry->issue('local-cli', 'local-probe', $site, $actor, array($content));
try {
    $broker = new ContentBroker($registry, $adapter->canRead(...), $adapter->read(...));
    $response = $broker->handle('local-cli', (object) array(
        'api' => Protocol::API, 'kind' => 'request', 'id' => 'probe', 'operation' => 'content.read',
        'invocation' => $handle, 'params' => (object) array('content_id' => $content),
    ));
    WP_CLI::line(json_encode($response, JSON_THROW_ON_ERROR));
} catch (BrokerError $error) {
    $failure = $error;
} finally {
    $registry->closeConnection('local-cli');
}
if (isset($failure)) {
    WP_CLI::error($failure->reason);
}
