<?php

declare(strict_types=1);

namespace AxiomPress\Core\Platform;

use AxiomPress\Core\Auth\Guard;
use AxiomPress\Core\JsonInput;
use AxiomPress\Core\Response;
use Closure;
use JsonException;

final readonly class Kernel
{
    public const FIELDS = array(
        'articles.list' => array('cursor', 'q', 'status'), 'media.list' => array('cursor'),
        'overview.read' => array(),
        'revisions.list' => array('id'), 'settings.read' => array(), 'terms.list' => array(),
        'settings.write' => array('expected_version', 'page_size', 'tagline', 'theme', 'title'),
        'terms.write' => array('expected_version', 'id', 'kind', 'name', 'slug'),
        'terms.archive' => array('expected_version', 'id'),
        'relationships.write' => array('expected_revision', 'expected_version', 'id', 'terms'),
        'articles.restore' => array('expected_revision', 'expected_version', 'id', 'revision'),
    );

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
        if (strlen($body) > 65536 || strlen($uri) > 256 || strlen($key) > 64) {
            return new Response(413, array('error' => 'request_limit'));
        }
        if (
            !preg_match('~\A/v1/platform/([a-z]+\.[a-z]+)\z~D', $uri, $match)
            || !isset(self::FIELDS[$match[1]])
        ) {
            return new Response(404, array('error' => 'not_found'));
        }
        if ($method !== 'POST') {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        $context = (new Guard($this->store, $this->origin))->authorize($cookie, $origin, $csrf, $secure, true);
        if ($context instanceof Response) {
            return $context;
        }
        if (strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
            return new Response(400, array('error' => 'invalid_request'));
        }
        $operation = $match[1];
        $input = JsonInput::object($body, self::FIELDS[$operation]);
        $write = !str_ends_with($operation, '.list') && !str_ends_with($operation, '.read');
        if ($input === null || ($write && !preg_match('/\A[a-f0-9]{64}\z/D', $key))) {
            return new Response(400, array('error' => 'invalid_request'));
        }
        if ($operation === 'relationships.write') {
            try {
                $terms = json_decode($input['terms'], true, 3, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            if (!is_array($terms) || !array_is_list($terms) || count($terms) > 16) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            foreach ($terms as $term) {
                if (!is_string($term) || !preg_match('/\A[a-f0-9]{32}\z/D', $term)) {
                    return new Response(400, array('error' => 'invalid_request'));
                }
            }
        }
        $result = $context['store']->platform($context['token'], $operation, $input, $key);
        $status = match ($result['error'] ?? '') {
            '' => 200, 'unauthenticated' => 401, 'forbidden' => 403, 'not_found' => 404,
            'conflict' => 409, 'quota_exceeded' => 429, default => 400,
        };
        return new Response($status, $result);
    }
}
