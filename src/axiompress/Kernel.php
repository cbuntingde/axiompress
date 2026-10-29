<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use Closure;
use Throwable;

/** Anonymous reads only; cookies, headers and extension claims never establish actor authority. */
final readonly class Kernel
{
    public function __construct(private Closure $repository, private ?ExtensionClient $extension = null)
    {
    }

    public function handle(string $method, string $uri): Response
    {
        if (strlen($uri) > 256) {
            return new Response(414, array('error' => 'uri_too_long'));
        }
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return new Response(405, array('error' => 'method_not_allowed'), array('Allow' => 'GET, HEAD'));
        }
        if (!preg_match('/\A\/v1\/sites\/([1-9][0-9]{0,9})\/content\/([1-9][0-9]{0,17})\z/D', $uri, $parts)) {
            return new Response(404, array('error' => 'not_found'));
        }
        $repository = ($this->repository)();
        $content = $repository->read((int) $parts[1], (int) $parts[2]);
        if ($content === null) {
            return new Response(404, array('error' => 'not_found'));
        }
        $data = array('api' => 'axiompress.core/0.1', 'content' => $content);
        if ($this->extension !== null && $method !== 'HEAD') {
            try {
                $markup = $this->extension->render($content);
            } catch (Throwable $error) {
                $diagnostic = bin2hex(random_bytes(12));
                error_log('axiompress.core extension_unavailable diagnostic=' . $diagnostic);
                $data['extension_error'] = array('code' => 'extension_unavailable', 'diagnostic_id' => $diagnostic);
            }
            // Also reauthorize after failed/slow extensions; failure cannot publish withdrawn content.
            $current = $repository->read((int) $parts[1], (int) $parts[2]);
            if ($current === null) {
                return new Response(404, array('error' => 'not_found'));
            }
            if ($current['revision'] !== $content['revision']) {
                return new Response(409, array('error' => 'content_changed'));
            }
            if (isset($markup)) {
                $data['reading_summary'] = $markup;
            }
        }
        return new Response(200, $data);
    }
}
