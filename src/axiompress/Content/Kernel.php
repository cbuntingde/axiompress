<?php

declare(strict_types=1);

namespace AxiomPress\Core\Content;

use AxiomPress\Core\Auth\Kernel as AuthKernel;
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
    ): Response {
        if (strlen($uri) > 256 || strlen($body) > 819200 || strlen($key) > 64) {
            return new Response(413, array('error' => 'request_limit'));
        }
        if (!preg_match('~\A/v1/articles(?:/([1-9][0-9]{0,17})(?:/(publish|unpublish))?)?\z~D', $uri, $path)) {
            return new Response(404, array('error' => 'not_found'));
        }
        $id = $path[1] ?? '0';
        $operation = match (true) {
            $method === 'GET' && $id !== '0' && !isset($path[2]) => 'read',
            $method === 'POST' && $id === '0' => 'create',
            $method === 'PUT' && $id !== '0' && !isset($path[2]) => 'edit',
            $method === 'POST' && isset($path[2]) => $path[2],
            default => '',
        };
        if ($operation === '') {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        $write = $operation !== 'read';
        if ($write && $origin !== $this->origin) {
            return new Response(403, array('error' => 'origin_denied'));
        }
        $store = ($this->store)();
        $auth = new AuthKernel(static fn() => $store, $this->origin);
        $principal = $auth->handle('GET', '/v1/session', '', $cookie, $origin, '', '', '', $secure);
        if ($principal->status !== 200) {
            return $principal;
        }
        if ($write && (strlen($csrf) !== 64 || !hash_equals($principal->data['csrf_token'], $csrf))) {
            return new Response(403, array('error' => 'csrf_denied'));
        }
        $input = array();
        if ($write) {
            if (
                strtolower(trim(explode(';', $type)[0])) !== 'application/json'
                || !preg_match('/\A[a-f0-9]{64}\z/D', $key)
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            $expected = match ($operation) {
                'create' => array('text', 'title'),
                'edit' => array('expected_revision', 'expected_version', 'text', 'title'),
                default => array('expected_revision', 'expected_version'),
            };
            $input = JsonInput::object($body, $expected);
            if ($input === null) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            if (
                (isset($input['title']) && strlen($input['title']) > 4096)
                || (isset($input['text']) && strlen($input['text']) > 131072)
            ) {
                return new Response(413, array('error' => 'content_limit_exceeded'));
            }
            if (
                $operation !== 'create' && (!preg_match('/\A[a-f0-9]{64}\z/D', $input['expected_revision'])
                || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $input['expected_version']))
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
        } elseif ($body !== '') {
            return new Response(400, array('error' => 'invalid_request'));
        }
        // Cookie syntax/uniqueness was validated by the shared authentication kernel above.
        preg_match('/(?:\A|;)\s*' . preg_quote(AuthKernel::COOKIE, '/') . '=([a-f0-9]{64})(?:;|\z)/', $cookie, $match);
        $result = $store->content($match[1], $operation, $id, $input, $key);
        $status = match ($result['error'] ?? '') {
            '' => $operation === 'create' ? 201 : 200,
            'unauthenticated' => 401,
            'forbidden' => 403,
            'not_found' => 404,
            'conflict' => 409,
            'quota_exceeded' => 429,
            default => 400,
        };
        return new Response($status, $result);
    }
}
