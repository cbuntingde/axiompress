<?php

declare(strict_types=1);

namespace AxiomPress\Core;

/** Only core-owned escaped templates. No plugin HTML is accepted by this response path. */
final readonly class HtmlResponse
{
    public int $status;

    public function __construct(private string $html)
    {
        $this->status = 200;
    }

    public function body(): string
    {
        return $this->html;
    }

    public function send(bool $head): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; "
            . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        if (!$head) {
            echo $this->html;
        }
    }
}
