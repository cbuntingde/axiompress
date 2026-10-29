<?php

declare(strict_types=1);

// Private stdin/stdout bridge in a core-only container; never a network endpoint.
use AxiomPress\Broker\BrokerError;
use AxiomPress\Broker\WordPressContentAdapter;
use AxiomPress\Protocol\Protocol;

require __DIR__ . '/bootstrap.php';
if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    throw new RuntimeException('Expected site, post and actor numeric IDs.');
}
foreach (array_slice($argv, 1) as $id) {
    if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Invalid trusted bridge scope.');
    }
}
$site = 'site:' . $argv[1];
$contentId = 'post:' . $argv[2];
$actor = $argv[3] === '0' ? 'anonymous' : 'user:' . $argv[3];
$_SERVER['HTTP_HOST'] = 'localhost:8889';
$_SERVER['REQUEST_URI'] = '/';
ob_start();
try {
    require '/var/www/src/wp-load.php';
} finally {
    $bootstrapOutput = ob_get_clean();
}
if ($bootstrapOutput !== '') {
    throw new RuntimeException('Core bootstrap emitted unexpected output.');
}
$adapter = new WordPressContentAdapter();
$refreshPolicy = static function () use ($argv): void {
    // This CLI process spans several RPCs. Do not reuse request-local permission/content snapshots.
    if (wp_cache_supports('flush_runtime')) {
        wp_cache_flush_runtime();
    } else {
        wp_cache_delete((int) $argv[2], 'posts');
        wp_cache_delete((int) $argv[3], 'users');
        wp_cache_delete((int) $argv[3], 'user_meta');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete($GLOBALS['wpdb']->get_blog_prefix() . 'user_roles', 'options');
    }
    wp_roles()->for_site(get_current_blog_id());
};
$handle = bin2hex(random_bytes(32));
$send = static function (stdClass $message): void {
    $frame = Protocol::encode($message);
    for ($offset = 0; $offset < strlen($frame);) {
        $written = fwrite(STDOUT, substr($frame, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Core bridge write failed.');
        }
        $offset += $written;
    }
};
$send((object) array(
    'api' => Protocol::API, 'kind' => 'dispatch', 'id' => 'core-ready', 'operation' => 'component.render',
    'invocation' => $handle, 'params' => (object) array('content_id' => $contentId),
));
$seen = array();
while (($request = Protocol::read(STDIN)) !== null) {
    if (
        $request->kind !== 'request' || !hash_equals($handle, $request->invocation)
        || isset($seen[$request->id]) || count($seen) >= 32
    ) {
        throw new RuntimeException('Invalid core bridge request.');
    }
    $seen[$request->id] = true;
    try {
        $refreshPolicy();
        if ($request->params->content_id !== $contentId || !$adapter->canRead($actor, $site, $contentId)) {
            throw new BrokerError('not_found');
        }
        $result = $adapter->read($site, $contentId);
        $refreshPolicy();
        if ($result === null || !$adapter->canRead($actor, $site, $contentId)) {
            throw new BrokerError('not_found');
        }
        $response = (object) array('api' => Protocol::API, 'kind' => 'response',
            'id' => $request->id, 'result' => $result);
    } catch (BrokerError $error) {
        $response = (object) array('api' => Protocol::API, 'kind' => 'response', 'id' => $request->id,
            'error' => (object) array('code' => $error->reason, 'message' => 'Request denied.',
                'retryable' => false, 'diagnostic_id' => bin2hex(random_bytes(12))));
    }
    $send($response);
}
