<?php

declare(strict_types=1);

namespace AxiomPress\Core\Auth;

use AxiomPress\Core\Response;
use Closure;

/** Shared HTTP authentication only; each domain command still authorizes inside its transaction. */
final readonly class Guard
{
    public function __construct(private Closure $factory, private string $origin)
    {
    }

    public function authorize(string $cookie, string $origin, string $csrf, bool $secure, bool $write): array|Response
    {
        if ($write && $origin !== $this->origin) {
            return new Response(403, array('error' => 'origin_denied'));
        }
        $store = null;
        $factory = $this->factory;
        $auth = new Kernel(static function () use (&$store, $factory): Store {
            return $store = $factory();
        }, $this->origin);
        $principal = $auth->handle('GET', '/v1/session', '', $cookie, $origin, '', '', '', $secure);
        if ($principal->status !== 200) {
            return $principal;
        }
        if ($write && (strlen($csrf) !== 64 || !hash_equals($principal->data['csrf_token'], $csrf))) {
            return new Response(403, array('error' => 'csrf_denied'));
        }
        preg_match('/(?:\A|;)\s*' . preg_quote(Kernel::COOKIE, '/') . '=([a-f0-9]{64})(?:;|\z)/', $cookie, $match);
        return array('store' => $store, 'token' => $match[1], 'principal' => $principal->data);
    }
}
