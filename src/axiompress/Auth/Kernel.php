<?php

declare(strict_types=1);

namespace AxiomPress\Core\Auth;

use AxiomPress\Core\Response;
use AxiomPress\Core\JsonInput;
use Closure;
use RuntimeException;

final readonly class Kernel
{
    public const COOKIE = '__Host-axiom_session';

    public function __construct(private Closure $store, private string $origin)
    {
        if (
            !preg_match('~\Ahttps://[a-z0-9.-]+(?::([1-9][0-9]{0,4}))?\z~D', $origin, $parts)
            || (isset($parts[1]) && (int) $parts[1] > 65535)
        ) {
            throw new RuntimeException('An explicit HTTPS authentication origin is required.');
        }
    }

    public function handle(
        string $method,
        string $uri,
        string $body,
        string $cookie,
        string $origin,
        string $contentType,
        string $csrf,
        string $peer,
        bool $secure
    ): Response {
        if (!$secure) {
            return new Response(400, array('error' => 'https_required'));
        }
        if (
            strlen($uri) > 256 || strlen($body) > 8192 || strlen($cookie) > 4096
            || strlen($origin) > 256 || strlen($csrf) > 128
        ) {
            return new Response(413, array('error' => 'request_limit'));
        }
        if (!in_array($uri, array('/v1/session', '/v1/session/login'), true)) {
            return new Response(404, array('error' => 'not_found'));
        }
        $login = $uri === '/v1/session/login' && $method === 'POST';
        if (!$login && !($uri === '/v1/session' && in_array($method, array('GET', 'DELETE'), true))) {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        if (($method !== 'GET' || $origin !== '') && $origin !== $this->origin) {
            return new Response(403, array('error' => 'origin_denied'));
        }
        preg_match_all('/(?:\A|;)\s*' . preg_quote(self::COOKIE, '/') . '=([^;]*)/', $cookie, $matches);
        if (count($matches[1]) > 1) {
            return new Response(400, array('error' => 'duplicate_session_cookie'));
        }
        $token = $matches[1][0] ?? '';
        if ($token !== '' && !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return new Response(401, array('error' => 'unauthenticated'));
        }
        if ($login) {
            if (
                strtolower(trim(explode(';', $contentType)[0])) !== 'application/json'
                || filter_var($peer, FILTER_VALIDATE_IP) === false
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            $input = JsonInput::object($body, array('password', 'username'));
            if (
                $input === null || !preg_match('/\A[a-z0-9._-]{1,64}\z/D', $input['username'])
                || strlen($input['password']) < 1 || strlen($input['password']) > 1024
            ) {
                return new Response(400, array('error' => 'invalid_request'));
            }
            $store = ($this->store)();
            $account = $store->beginLogin($input['username'], $peer);
            if ($account['throttled'] ?? true) {
                return new Response(429, array('error' => 'login_throttled'), array('Retry-After' => '900'));
            }
            $hash = $account['password_hash'] ?? self::DUMMY_HASH;
            $info = password_get_info($hash);
            $usable = $info['algoName'] === 'argon2id' && $info['options'] === array(
                'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
            );
            $valid = password_verify($input['password'], $usable ? $hash : self::DUMMY_HASH);
            if (!$valid || !$usable || !isset($account['user_id'])) {
                return new Response(401, array('error' => 'invalid_credentials'));
            }
            $fresh = bin2hex(random_bytes(32));
            $actor = $store->create((int) $account['user_id'], $hash, $fresh, $token);
            if ($actor === array()) {
                return new Response(401, array('error' => 'invalid_credentials'));
            }
            $actor = $store->resolve($fresh);
            if ($actor === array()) {
                $store->revoke($fresh);
                return new Response(401, array('error' => 'invalid_credentials'));
            }
            return new Response(
                200,
                self::actor($actor, $fresh),
                array('Set-Cookie' => self::cookie($fresh, 28800))
            );
        }
        if ($body !== '') {
            return new Response(400, array('error' => 'invalid_request'));
        }
        if ($token === '') {
            return new Response(401, array('error' => 'unauthenticated'));
        }
        if ($method === 'DELETE' && !hash_equals(self::csrf($token), $csrf)) {
            return new Response(403, array('error' => 'csrf_denied'));
        }
        $store = ($this->store)();
        $actor = $store->resolve($token);
        if ($actor === array()) {
            return new Response(401, array('error' => 'unauthenticated'), array('Set-Cookie' => self::cookie('', 0)));
        }
        if ($method === 'DELETE') {
            $store->revoke($token);
            return new Response(200, array('outcome' => 'logged_out'), array('Set-Cookie' => self::cookie('', 0)));
        }
        return new Response(200, self::actor($actor, $token));
    }

    private static function csrf(string $token): string
    {
        return hash_hmac('sha256', 'axiompress.csrf.v1', $token);
    }

    private static function cookie(string $token, int $age): string
    {
        return self::COOKIE . '=' . $token . '; Path=/; Max-Age=' . $age . '; Secure; HttpOnly; SameSite=Strict';
    }

    private static function actor(array $actor, string $token): array
    {
        return array('user_id' => (string) $actor['user_id'], 'site_id' => (string) $actor['site_id'],
            'role' => $actor['role'], 'expires_at' => $actor['expires_at'], 'csrf_token' => self::csrf($token));
    }

    // A throwaway Argon2id hash, never a provisioned account. Missing accounts still verify a hash.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$UEljWXBxcUYvRUVYMHB1dA$'
        . 'wNhBQojD+JH4pXmw2OeGgIzVTDYYWY56eQXNE7aKX0U';
}
