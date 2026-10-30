<?php

declare(strict_types=1);

use AxiomPress\Core\Configuration;
use AxiomPress\Core\ContentLimitExceeded;
use AxiomPress\Core\ExtensionClient;
use AxiomPress\Core\Kernel;
use AxiomPress\Core\PublicContentRepository;
use AxiomPress\Core\PublishedContentRepository;
use AxiomPress\Core\Response;

require dirname(__DIR__) . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $socket = getenv('AXIOMPRESS_BROKER_SOCKET');
    $extension = $socket === false ? null : new ExtensionClient(
        $socket,
        (string) getenv('AXIOMPRESS_READING_INSTALLATION')
    );
    $kernel = new Kernel(static function (): PublicContentRepository|PublishedContentRepository {
        $backend = getenv('AXIOM_PUBLIC_BACKEND');
        return match ($backend) {
            false, 'mysql' => PublicContentRepository::connect(Configuration::fromEnvironment()),
            'postgres' => PublishedContentRepository::fromEnvironment(),
            default => throw new RuntimeException('Invalid public content backend.'),
        };
    }, $extension);
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (in_array($uri, array('/healthz', '/readyz'), true)) {
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            $response = new Response(405, array('error' => 'method_not_allowed'));
        } else {
            if ($uri === '/readyz') {
                if (getenv('AXIOM_PUBLIC_BACKEND') !== 'postgres') {
                    throw new RuntimeException('Native readiness requires PostgreSQL.');
                }
                $auth = AxiomPress\Core\Auth\Store::fromEnvironment();
                if (AxiomPress\Core\Platform\PublicRepository::fromEnvironment()->settings($auth->site) === null) {
                    throw new RuntimeException('Native readiness scope differs.');
                }
            }
            $response = new Response(200, array('status' => $uri === '/readyz' ? 'ready' : 'alive'));
        }
    } elseif (AxiomPress\Core\AssetResponse::exists($uri)) {
        $response = in_array($method, array('GET', 'HEAD'), true)
            ? new AxiomPress\Core\AssetResponse($uri)
            : new Response(405, array('error' => 'method_not_allowed'));
    } elseif (str_starts_with($uri, '/site/') || str_starts_with($uri, '/preview/')) {
        $response = (new AxiomPress\Core\Themes\Kernel($kernel))->handle(
            $method,
            $uri,
            $_SERVER['HTTP_COOKIE'] ?? '',
            ($_SERVER['HTTPS'] ?? '') === 'on' || getenv('AXIOM_AUTH_TLS_TERMINATED') === '1'
        );
    } elseif (preg_match('~\A/v1/sites/([1-9][0-9]{0,9})/media/([a-f0-9]{32})\z~D', $uri, $mediaPath)) {
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            $response = new Response(405, array('error' => 'method_not_allowed'));
        } elseif (getenv('AXIOM_PUBLIC_BACKEND') !== 'postgres') {
            $response = new Response(404, array('error' => 'not_found'));
        } else {
            $response = AxiomPress\Core\Media\PublicRepository::fromEnvironment()
                ->read((int) $mediaPath[1], $mediaPath[2]);
        }
    } elseif (str_starts_with($uri, '/v1/media')) {
        $media = new AxiomPress\Core\Media\Kernel(static fn(): AxiomPress\Core\Auth\Store =>
            AxiomPress\Core\Auth\Store::fromEnvironment(), (string) getenv('AXIOM_AUTH_ORIGIN'));
        $body = file_get_contents('php://input', false, null, 0, 3145729);
        $response = $media->handle(
            $method,
            $uri,
            $body === false ? '' : $body,
            $_SERVER['HTTP_COOKIE'] ?? '',
            $_SERVER['HTTP_ORIGIN'] ?? '',
            $_SERVER['CONTENT_TYPE'] ?? '',
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
            ($_SERVER['HTTPS'] ?? '') === 'on' || getenv('AXIOM_AUTH_TLS_TERMINATED') === '1'
        );
    } elseif (str_starts_with($uri, '/v1/platform')) {
        $platform = new AxiomPress\Core\Platform\Kernel(static fn(): AxiomPress\Core\Auth\Store =>
            AxiomPress\Core\Auth\Store::fromEnvironment(), (string) getenv('AXIOM_AUTH_ORIGIN'));
        $body = file_get_contents('php://input', false, null, 0, 65537);
        $response = $platform->handle(
            $method,
            $uri,
            $body === false ? '' : $body,
            $_SERVER['HTTP_COOKIE'] ?? '',
            $_SERVER['HTTP_ORIGIN'] ?? '',
            $_SERVER['CONTENT_TYPE'] ?? '',
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
            ($_SERVER['HTTPS'] ?? '') === 'on' || getenv('AXIOM_AUTH_TLS_TERMINATED') === '1'
        );
    } elseif (str_starts_with($uri, '/v1/articles')) {
        $content = new AxiomPress\Core\Content\Kernel(static fn(): AxiomPress\Core\Auth\Store =>
            AxiomPress\Core\Auth\Store::fromEnvironment(), (string) getenv('AXIOM_AUTH_ORIGIN'));
        $body = file_get_contents('php://input', false, null, 0, 819201);
        $response = $content->handle(
            $method,
            $uri,
            $body === false ? '' : $body,
            $_SERVER['HTTP_COOKIE'] ?? '',
            $_SERVER['HTTP_ORIGIN'] ?? '',
            $_SERVER['CONTENT_TYPE'] ?? '',
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
            ($_SERVER['HTTPS'] ?? '') === 'on' || getenv('AXIOM_AUTH_TLS_TERMINATED') === '1'
        );
    } elseif (str_starts_with($uri, '/v1/session')) {
        $auth = new AxiomPress\Core\Auth\Kernel(static fn(): AxiomPress\Core\Auth\Store =>
            AxiomPress\Core\Auth\Store::fromEnvironment(), (string) getenv('AXIOM_AUTH_ORIGIN'));
        $body = file_get_contents('php://input', false, null, 0, 8193);
        $response = $auth->handle(
            $method,
            $uri,
            $body === false ? '' : $body,
            $_SERVER['HTTP_COOKIE'] ?? '',
            $_SERVER['HTTP_ORIGIN'] ?? '',
            $_SERVER['CONTENT_TYPE'] ?? '',
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            ($_SERVER['HTTPS'] ?? '') === 'on'
            || getenv('AXIOM_AUTH_TLS_TERMINATED') === '1'
        );
    } else {
        $response = $kernel->handle($method, $uri);
    }
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
