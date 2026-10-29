<?php

declare(strict_types=1);

namespace AxiomPress\Core;

final readonly class Response
{
    public function __construct(public int $status, public array $data, public array $headers = array())
    {
    }

    public function body(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public function send(bool $head): void
    {
        $body = $this->body();
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$head) {
            echo $body;
        }
    }
}
