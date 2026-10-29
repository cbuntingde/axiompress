<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use Closure;

/** Anonymous reads only; cookies, headers and extension claims never establish actor authority. */
final readonly class Kernel
{
    public function __construct(private Closure $repository)
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
        return $content === null ? new Response(404, array('error' => 'not_found'))
            : new Response(200, array('api' => 'axiompress.core/0.1', 'content' => $content));
    }
}
