<?php

declare(strict_types=1);

namespace AxiomPress\Core\Content;

use AxiomPress\Core\Auth\Guard;
use AxiomPress\Core\Blocks\Document;
use AxiomPress\Core\JsonInput;
use AxiomPress\Core\Response;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

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
        if (!preg_match('~\A/v1/articles(?:/([1-9][0-9]{0,17})(?:/(publish|unpublish|schedule))?)?\z~D', $uri, $path)) {
            return new Response(404, array('error' => 'not_found'));
        }
        $id = $path[1] ?? '0';
        $operation = match (true) {
            $method === 'GET' && $id !== '0' && !isset($path[2]) => 'read',
            $method === 'POST' && $id === '0' => 'create',
            $method === 'PUT' && $id !== '0' && !isset($path[2]) => 'edit',
            $method === 'POST' && isset($path[2]) => $path[2],
            $method === 'DELETE' && ($path[2] ?? '') === 'schedule' => 'cancel_schedule',
            default => '',
        };
        if ($operation === '') {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        $write = $operation !== 'read';
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
            $expected = match ($operation) {
                'create' => array('text', 'title'),
                'edit' => array('expected_revision', 'expected_version', 'text', 'title'),
                'schedule' => array('due_at', 'expected_revision', 'expected_version'),
                'cancel_schedule' => array(),
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
                !in_array($operation, array('create', 'cancel_schedule'), true)
                && (!preg_match('/\A[a-f0-9]{64}\z/D', $input['expected_revision'])
                || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $input['expected_version']))
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            if ($operation === 'schedule') {
                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i:s\Z',
                    $input['due_at'],
                    new DateTimeZone('UTC')
                );
                if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $input['due_at']) {
                    return new Response(400, array('error' => 'invalid_request'));
                }
            }
            if (isset($input['text']) && str_starts_with($input['text'], '<!-- wp:')) {
                try {
                    Document::parse($input['text']);
                } catch (RuntimeException $error) {
                    return new Response(400, array('error' => 'unsupported_document'));
                }
            }
        } elseif ($body !== '') {
            return new Response(400, array('error' => 'invalid_request'));
        }
        $result = $context['store']->content($context['token'], $operation, $id, $input, $key);
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
