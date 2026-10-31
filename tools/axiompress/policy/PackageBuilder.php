<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use RuntimeException;
use FilesystemIterator;

/** Trusted local builder: no publisher Dockerfile, build hook, network or runtime options. */
final class PackageBuilder
{
    public static function build(Package $package, string $sdkImage): ImageBinding
    {
        $sdk = json_decode(
            self::command(array('docker', 'image', 'inspect', 'axiompress-worker-sdk:prototype')),
            true,
            32,
            JSON_THROW_ON_ERROR
        )[0];
        if (($sdk['Id'] ?? null) !== $sdkImage || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sdkImage)) {
            throw new RuntimeException('Trusted SDK image changed.');
        }
        $directory = '/tmp/axiompress-build-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create build snapshot.');
        }
        $tag = 'axiompress-package:' . bin2hex(random_bytes(12));
        $clientDirectory = $directory . '-client';
        try {
            if (!mkdir($clientDirectory, 0700)) {
                throw new RuntimeException('Could not create private builder client state.');
            }
            $labels = array(
                'dev.axiompress.package.digest' => $package->digest,
                'dev.axiompress.manifest.digest' => $package->manifestDigest,
                'dev.axiompress.plugin.id' => $package->pluginId,
                'dev.axiompress.plugin.version' => $package->version,
                'dev.axiompress.sdk.image' => $sdkImage,
                'dev.axiompress.profile' => 'readonly-v1',
            );
            $dockerfile = "FROM axiompress-worker-sdk:prototype\nCOPY worker.php /app/worker.php\n"
                . "COPY manifest.json /app/manifest.json\n";
            foreach ($labels as $name => $value) {
                $dockerfile .= 'LABEL ' . $name . '="' . $value . '"' . "\n";
            }
            foreach (
                array('Dockerfile' => $dockerfile, 'manifest.json' => $package->manifest,
                'worker.php' => $package->worker) as $name => $bytes
            ) {
                if (file_put_contents($directory . '/' . $name, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Snapshot write failed.');
                }
            }
            self::command(array('docker', '--config', $clientDirectory, 'buildx', 'build',
                '--load', '--network=none', '--pull=false',
                '-t', $tag, $directory));
            $sdkAfter = json_decode(
                self::command(array('docker', 'image', 'inspect', 'axiompress-worker-sdk:prototype')),
                true,
                32,
                JSON_THROW_ON_ERROR
            )[0];
            if ($sdkAfter['Id'] !== $sdkImage) {
                throw new RuntimeException('SDK changed during package build.');
            }
            $image = json_decode(
                self::command(array('docker', 'image', 'inspect', $tag)),
                true,
                32,
                JSON_THROW_ON_ERROR
            )[0];
            return ImageBinding::inspect($package, $image, $sdkImage);
        } finally {
            if (is_dir($clientDirectory)) {
                self::removeClientState($clientDirectory);
            }
            foreach (array('Dockerfile', 'manifest.json', 'worker.php') as $name) {
                $path = $directory . '/' . $name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** Inspect an operator-built artifact without rebuilding it. */
    public static function inspectImage(string $artifact): array
    {
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $artifact)) {
            throw new RuntimeException('Approved artifact identifier required.');
        }
        $image = json_decode(
            self::command(array('docker', 'image', 'inspect', $artifact)),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($image) || !isset($image[0]) || !is_array($image[0])) {
            throw new RuntimeException('Artifact inspection failed.');
        }
        return $image[0];
    }

    /** Only the random operator-owned client directory created above; never follow symlinks. */
    private static function removeClientState(string $directory): void
    {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeClientState($entry->getPathname());
            } elseif (!unlink($entry->getPathname())) {
                throw new RuntimeException('Builder client cleanup failed.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Builder client directory cleanup failed.');
        }
    }

    private static function command(array $command): string
    {
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Package runtime command failed to start.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = hrtime(true) + 30_000_000_000;
        $output = '';
        $errors = '';
        try {
            do {
                $output .= stream_get_contents($pipes[1], 8192);
                $errors .= stream_get_contents($pipes[2], 8192);
                if (strlen($output) + strlen($errors) > 1048576 || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Package command deadline/output limit.');
                }
                $state = proc_get_status($process);
                if ($state['running']) {
                    usleep(1000);
                }
            } while ($state['running']);
            $output .= stream_get_contents($pipes[1], 1048577 - strlen($output));
            if ($state['exitcode'] !== 0 || strlen($output) + strlen($errors) > 1048576) {
                throw new RuntimeException('Package runtime command failed.');
            }
            return $output;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process, 9);
            proc_close($process);
        }
    }
}
