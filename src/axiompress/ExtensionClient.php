<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use RuntimeException;
use stdClass;

/** One anonymous public snapshot per connection. Core never receives runtime/policy credentials. */
final readonly class ExtensionClient
{
    public function __construct(private string $socketPath, private string $installation)
    {
        if (
            !preg_match('~\A/[A-Za-z0-9/_.-]{1,100}\.sock\z~D', $socketPath)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $installation)
        ) {
            throw new RuntimeException('Invalid extension service configuration.');
        }
    }

    public function render(array $content): string
    {
        // Socket disappearance, overload and disconnects are controlled optional failures.
        // Do not emit raw stream warnings or copy their messages into the HTTP response.
        set_error_handler(static function (): never {
            throw new RuntimeException('Extension service I/O failed.');
        });
        try {
            return $this->exchange($content);
        } finally {
            restore_error_handler();
        }
    }

    private function exchange(array $content): string
    {
        // Deployment owns the directory; core gets a read-only mount and cannot replace the server.
        clearstatcache(true, $this->socketPath);
        $directory = dirname($this->socketPath);
        if (
            !file_exists($this->socketPath) || is_link($this->socketPath) || is_link($directory)
            || filetype($this->socketPath) !== 'socket'
            || fileowner($directory) !== 0
            || fileowner($directory) !== fileowner($this->socketPath)
            || (fileperms($directory) & 0022) !== 0 || (fileperms($this->socketPath) & 0007) !== 0
        ) {
            throw new RuntimeException('Untrusted extension service socket.');
        }
        $body = json_encode(
            array('api' => 'axiompress.core-broker/0.1', 'installation' => $this->installation,
            'site' => 'site:' . $content['site_id'], 'content' => array(
                'content_id' => 'post:' . $content['content_id'],
                'revision' => $content['revision'], 'title' => $content['title'], 'text' => $content['text'])),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        if (strlen($body) > 200000) {
            throw new RuntimeException('Extension request limit.');
        }
        $stream = stream_socket_client('unix://' . $this->socketPath, $errno, $error, 1);
        if ($stream === false) {
            throw new RuntimeException('Extension service unavailable.');
        }
        $deadline = hrtime(true) + 15_000_000_000;
        try {
            $frame = pack('N', strlen($body)) . $body;
            for ($offset = 0; $offset < strlen($frame);) {
                self::timeout($stream, $deadline);
                $written = fwrite($stream, substr($frame, $offset, 8192));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Extension service write failed.');
                }
                $offset += $written;
            }
            if (!stream_socket_shutdown($stream, STREAM_SHUT_WR)) {
                throw new RuntimeException('Extension request termination failed.');
            }
            $length = unpack('Nlength', self::read($stream, 4, $deadline))['length'];
            if ($length < 1 || $length > 1024) {
                throw new RuntimeException('Extension response limit.');
            }
            $response = json_decode(self::read($stream, $length, $deadline), false, 8, JSON_THROW_ON_ERROR);
            self::timeout($stream, $deadline);
            if (
                fread($stream, 1) !== '' || !feof($stream)
                || !$response instanceof stdClass || array_keys(get_object_vars($response)) !== array('component')
                || !$response->component instanceof stdClass
            ) {
                throw new RuntimeException('Invalid extension response.');
            }
            return ComponentRenderer::readingSummary($response->component);
        } finally {
            fclose($stream);
        }
    }

    private static function read(mixed $stream, int $length, int $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            self::timeout($stream, $deadline);
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Incomplete extension response.');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    private static function timeout(mixed $stream, int $deadline): void
    {
        $remaining = $deadline - hrtime(true);
        if (
            $remaining <= 0 || !stream_set_timeout(
                $stream,
                intdiv($remaining, 1_000_000_000),
                intdiv($remaining % 1_000_000_000, 1000)
            )
        ) {
            throw new RuntimeException('Extension service deadline.');
        }
    }
}
