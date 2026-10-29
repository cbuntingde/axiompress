<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use AxiomPress\Protocol\Protocol;
use FilesystemIterator;
use RuntimeException;

/** Immutable source snapshot; validation never includes or executes package PHP. */
final readonly class Package
{
    private function __construct(
        public string $pluginId,
        public string $version,
        public string $digest,
        public string $manifestDigest,
        public string $manifest,
        public string $worker
    ) {
    }

    public static function capture(string $directory): self
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Invalid package directory.');
        }
        $names = array();
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (count($names) >= 2) {
                throw new RuntimeException('Package file count limit exceeded.');
            }
            $names[] = $entry->getFilename();
        }
        sort($names);
        if ($names !== array('manifest.json', 'worker.php')) {
            throw new RuntimeException('Package must contain exactly manifest.json and worker.php.');
        }
        $bytes = array();
        foreach ($names as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('Non-regular package file.');
            }
            $before = lstat($path);
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Package read failed.');
            }
            try {
                $stat = fstat($stream);
                if (
                    ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                    || $stat['ino'] !== $before['ino'] || $stat['dev'] !== $before['dev']
                ) {
                    throw new RuntimeException('Non-regular opened package file.');
                }
                $data = stream_get_contents($stream, 262145);
                if ($data === false || strlen($data) > 262144 || $data === '') {
                    throw new RuntimeException('Invalid package file size.');
                }
                $bytes[$name] = $data;
                clearstatcache(true, $path);
                $after = lstat($path);
                if (
                    $after['ino'] !== $stat['ino'] || $after['dev'] !== $stat['dev']
                    || ($after['mode'] & 0170000) !== 0100000 || $after['size'] !== strlen($data)
                ) {
                    throw new RuntimeException('Package changed during capture.');
                }
            } finally {
                fclose($stream);
            }
        }
        if (strlen($bytes['manifest.json']) > 16384) {
            throw new RuntimeException('Manifest size limit exceeded.');
        }
        $manifest = Protocol::parseObject($bytes['manifest.json']);
        $keys = array_keys(get_object_vars($manifest));
        sort($keys);
        if (
            $keys !== array(
                'api', 'capabilities', 'entrypoint', 'operations', 'plugin_id', 'resource_profile', 'version'
            )
            || $manifest->api !== Protocol::API || $manifest->entrypoint !== 'worker.php'
            || $manifest->capabilities !== array('content.read') || $manifest->operations !== array('component.render')
            || $manifest->resource_profile !== 'readonly-v1'
            || !is_string($manifest->plugin_id) || strlen($manifest->plugin_id) > 128
            || !preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\/[a-z0-9]+(?:[.-][a-z0-9]+)*\z/D', $manifest->plugin_id)
            || !is_string($manifest->version) || strlen($manifest->version) > 64
            || !preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?\z/D', $manifest->version)
        ) {
            throw new RuntimeException('Unsupported package manifest.');
        }
        $hash = hash_init('sha256');
        hash_update($hash, "axiompress.package/0.1\0");
        foreach ($bytes as $name => $data) {
            hash_update($hash, pack('N', strlen($name)) . $name . pack('N', strlen($data)) . $data);
        }
        return new self(
            $manifest->plugin_id,
            $manifest->version,
            hash_final($hash),
            hash('sha256', $bytes['manifest.json']),
            $bytes['manifest.json'],
            $bytes['worker.php']
        );
    }
}
