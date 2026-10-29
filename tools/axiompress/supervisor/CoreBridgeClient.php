<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use AxiomPress\Broker\BrokerError;
use AxiomPress\Protocol\Protocol;
use RuntimeException;
use stdClass;
use Throwable;

/** Trusted attached-channel client; core credentials remain in the separate core container. */
final class CoreBridgeClient
{
    private $process;
    private array $pipes = array();
    private string $handle;
    private int $sequence = 0;
    private int $stderrBytes = 0;

    public function __construct(
        string $container,
        string $coreImage,
        private readonly string $actor,
        private readonly string $site,
        private readonly string $contentId
    ) {
        if (PHP_OS_FAMILY !== 'Linux' || !preg_match('/\Aaxiompress-core-[a-f0-9]{24}\z/D', $container)) {
            throw new RuntimeException('Invalid trusted core container identity.');
        }
        if (
            !preg_match('/\Asite:[1-9][0-9]*\z/D', $site)
            || !preg_match('/\Apost:[1-9][0-9]*\z/D', $contentId)
            || ($actor !== 'anonymous' && !preg_match('/\Auser:[1-9][0-9]*\z/D', $actor))
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $coreImage)
        ) {
            throw new RuntimeException('Invalid trusted core scope.');
        }
        $inspection = Supervisor::inspectCoreContainer($container);
        $expectedCommand = array('-d', 'display_errors=stderr', '/var/www/tools/axiompress/broker/core-bridge.php',
            substr($site, 5), substr($contentId, 5), $actor === 'anonymous' ? '0' : substr($actor, 5));
        if (
            $inspection['Image'] !== $coreImage || $inspection['Config']['Cmd'] !== $expectedCommand
            || $inspection['Config']['Entrypoint'] !== array('php') || !$inspection['HostConfig']['ReadonlyRootfs']
            || $inspection['Config']['User'] !== '1000:1000'
        ) {
            throw new RuntimeException('Core container scope/profile mismatch.');
        }
        foreach ($inspection['Mounts'] as $mount) {
            if (
                $mount['RW'] || !in_array($mount['Destination'], array('/var/www',
                '/usr/local/etc/php/conf.d/php-config.ini'), true)
            ) {
                throw new RuntimeException('Unexpected core container mount.');
            }
        }
        $this->process = proc_open(array('docker', 'start', '-ai', $container), array(
            0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
        ), $this->pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Core bridge attachment failed.');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            $ready = $this->exchange('', 15);
            if (
                $ready->kind !== 'dispatch' || $ready->id !== 'core-ready'
                || $ready->params->content_id !== $contentId
            ) {
                throw new RuntimeException('Unexpected core bridge handshake.');
            }
            $this->handle = $ready->invocation;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function canRead(string $actor, string $site, string $contentId): bool
    {
        return $actor === $this->actor && $site === $this->site && $contentId === $this->contentId
            && $this->read($site, $contentId) !== null;
    }

    public function read(string $site, string $contentId): ?stdClass
    {
        if ($site !== $this->site || $contentId !== $this->contentId) {
            return null;
        }
        $id = 'core-' . ++$this->sequence;
        $response = $this->exchange(Protocol::encode((object) array(
            'api' => Protocol::API, 'kind' => 'request', 'id' => $id, 'operation' => 'content.read',
            'invocation' => $this->handle, 'params' => (object) array('content_id' => $contentId),
        )), 2);
        if ($response->kind !== 'response' || $response->id !== $id) {
            throw new RuntimeException('Unexpected core response identity.');
        }
        if (isset($response->error)) {
            if ($response->error->code === 'not_found') {
                return null;
            }
            throw new BrokerError($response->error->code);
        }
        if ($response->result->content_id !== $contentId) {
            throw new RuntimeException('Unexpected core content identity.');
        }
        return $response->result;
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = array();
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }

    private function exchange(string $outgoing, int $seconds): stdClass
    {
        if ($this->pipes === array()) {
            throw new RuntimeException('Core bridge is closed.');
        }
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        $buffer = '';
        while (true) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Core bridge deadline exceeded.');
            }
            if (feof($this->pipes[1])) {
                throw new RuntimeException('Core bridge closed unexpectedly.');
            }
            $read = array_filter(array($this->pipes[1], $this->pipes[2]), static fn($pipe) => !feof($pipe));
            $write = $outgoing === '' ? array() : array($this->pipes[0]);
            $except = null;
            $interrupted = false;
            set_error_handler(static function (int $severity, string $message) use (&$interrupted): bool {
                if (str_contains($message, 'Interrupted system call')) {
                    $interrupted = true;
                    return true;
                }
                return false;
            });
            try {
                $selected = stream_select($read, $write, $except, 0, 20_000);
            } finally {
                restore_error_handler();
            }
            if ($selected === false && $interrupted) {
                pcntl_signal_dispatch();
                continue;
            }
            if ($selected === false) {
                throw new RuntimeException('Core bridge I/O failed.');
            }
            foreach ($write as $pipe) {
                $count = fwrite($pipe, substr($outgoing, 0, 8192));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Core bridge write failed.');
                }
                $outgoing = substr($outgoing, $count);
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Core bridge read failed.');
                }
                if ($pipe === $this->pipes[2]) {
                    $this->stderrBytes += strlen($chunk);
                    if ($this->stderrBytes > 65536) {
                        throw new RuntimeException('Core bridge diagnostics limit.');
                    }
                    continue;
                }
                if ($chunk === '' && feof($pipe)) {
                    throw new RuntimeException('Core bridge closed unexpectedly.');
                }
                $buffer .= $chunk;
                if (strlen($buffer) > Protocol::MAX_BYTES + 4) {
                    throw new RuntimeException('Core bridge output limit.');
                }
            }
            if (strlen($buffer) < 4) {
                continue;
            }
            $length = unpack('Nlength', substr($buffer, 0, 4))['length'];
            if ($length < 1 || $length > Protocol::MAX_BYTES || strlen($buffer) > $length + 4) {
                throw new RuntimeException('Invalid core frame length.');
            }
            if (strlen($buffer) === $length + 4 && $outgoing === '') {
                return Protocol::decode(substr($buffer, 4));
            }
        }
    }
}
