<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use RuntimeException;

final readonly class AssetResponse
{
    private const FILES = array(
        '/admin' => array('admin.html', 'text/html; charset=utf-8'),
        '/assets/admin.css' => array('assets/admin.css', 'text/css; charset=utf-8'),
        '/assets/admin.js' => array('assets/admin.js', 'text/javascript; charset=utf-8'),
        '/assets/block-parser.js' => array('assets/block-parser.js', 'text/javascript; charset=utf-8'),
        '/assets/public.css' => array('assets/public.css', 'text/css; charset=utf-8'),
        '/assets/LICENSE.txt' => array('assets/LICENSE.txt', 'text/plain; charset=utf-8'),
    );
    public int $status;
    private string $bytes;
    private string $type;

    public function __construct(string $uri)
    {
        if (!isset(self::FILES[$uri])) {
            throw new RuntimeException('Unknown fixed asset.');
        }
        [$name, $type] = self::FILES[$uri];
        $file = __DIR__ . '/public/' . $name;
        if (is_link($file) || !is_file($file) || filesize($file) > 262144) {
            throw new RuntimeException('Fixed asset unavailable.');
        }
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            throw new RuntimeException('Fixed asset unavailable.');
        }
        $this->bytes = $bytes;
        $this->type = $type;
        $this->status = 200;
    }

    public static function exists(string $uri): bool
    {
        return isset(self::FILES[$uri]);
    }

    public function body(): string
    {
        return $this->bytes;
    }

    public function send(bool $head): void
    {
        http_response_code(200);
        header('Content-Type: ' . $this->type);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; "
            . "img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        if (!$head) {
            echo $this->bytes;
        }
    }
}
