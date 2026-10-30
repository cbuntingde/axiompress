<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use RuntimeException;

/** Only regenerated PNG bytes, never original uploads, filenames or arbitrary response headers. */
final readonly class ImageResponse
{
    public int $status;

    public function __construct(private string $bytes)
    {
        if (strlen($bytes) > 2097152 || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Invalid regenerated image.');
        }
        $this->status = 200;
    }

    public function body(): string
    {
        return $this->bytes;
    }

    public function send(bool $head): void
    {
        http_response_code(200);
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($this->bytes));
        header('Content-Disposition: inline; filename="image.png"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        if (!$head) {
            echo $this->bytes;
        }
    }
}
