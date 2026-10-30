<?php

declare(strict_types=1);

namespace AxiomPress\Core\Media;

use AxiomPress\Core\Auth\Guard;
use AxiomPress\Core\ImageResponse;
use AxiomPress\Core\JsonInput;
use AxiomPress\Core\Response;
use Closure;

final readonly class Kernel
{
    public function __construct(private Closure $store, private string $origin)
    {
    }

    public function handle(
        string $method,
        string $uri,
        string $body,
        string $cookie,
        string $origin,
        string $type,
        string $csrf,
        string $key,
        bool $secure
    ): Response|ImageResponse {
        if (strlen($uri) > 256 || strlen($body) > 3145728 || strlen($key) > 64) {
            return new Response(413, array('error' => 'request_limit'));
        }
        if (!preg_match('~\A/v1/media(?:/([a-f0-9]{32})(?:/(file|publish|withdraw))?)?\z~D', $uri, $path)) {
            return new Response(404, array('error' => 'not_found'));
        }
        $id = $path[1] ?? '';
        $operation = match (true) {
            $method === 'POST' && $id === '' => 'create',
            in_array($method, array('GET', 'HEAD'), true) && $id !== ''
                && (!isset($path[2]) || $path[2] === 'file') => $path[2] ?? 'read',
            $method === 'POST' && in_array($path[2] ?? '', array('publish', 'withdraw'), true) => $path[2],
            default => '',
        };
        if ($operation === '') {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        $write = !in_array($operation, array('read', 'file'), true);
        $context = (new Guard($this->store, $this->origin))->authorize($cookie, $origin, $csrf, $secure, $write);
        if ($context instanceof Response) {
            return $context;
        }
        $input = array();
        if ($write) {
            if (
                strtolower(trim(explode(';', $type)[0])) !== 'application/json'
                || !preg_match('/\A[a-f0-9]{64}\z/D', $key)
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            $input = JsonInput::object($body, $operation === 'create' ? array('data', 'mime', 'name')
                : array('expected_version'));
            if ($input === null) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            if ($operation === 'create') {
                $bytes = base64_decode($input['data'], true);
                if (
                    $bytes === false || strlen($bytes) > 2097152 || base64_encode($bytes) !== $input['data']
                    || strlen($input['name']) < 1 || strlen($input['name']) > 255
                    || !in_array($input['mime'], array('image/png', 'image/jpeg', 'image/webp'), true)
                ) {
                    return new Response(400, array('error' => 'invalid_request'));
                }
            } elseif (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $input['expected_version'])) {
                return new Response(400, array('error' => 'invalid_request'));
            }
        } elseif ($body !== '') {
            return new Response(400, array('error' => 'invalid_request'));
        }
        $result = $context['store']->media($context['token'], $operation, $id, $input, $key);
        if ($operation === 'file' && isset($result['data'])) {
            return new ImageResponse(base64_decode($result['data'], true));
        }
        $status = match ($result['error'] ?? '') {
            '' => $operation === 'create' ? 202 : 200,
            'unauthenticated' => 401, 'forbidden' => 403, 'not_found' => 404,
            'conflict' => 409, 'quota_exceeded' => 429, default => 400,
        };
        return new Response($status, $result);
    }
}
