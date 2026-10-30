<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use RuntimeException;

/** Trusted host control only. Worker messages cannot select paths, arguments or configuration. */
final readonly class DirectRuntime
{
    private string $helper;

    public function __construct(private string $configuration)
    {
        $helper = dirname(__DIR__) . '/runtime/direct.py';
        if (
            PHP_OS_FAMILY !== 'Linux' || !str_starts_with($configuration, '/')
            || is_link($helper) || !is_file($helper) || fileowner($helper) !== 0 || (fileperms($helper) & 0022) !== 0
        ) {
            throw new RuntimeException('Operator-controlled Linux direct runtime required.');
        }
        for ($parent = dirname($helper); $parent !== '/'; $parent = dirname($parent)) {
            $permissions = fileperms($parent);
            if (
                is_link($parent) || fileowner($parent) !== 0
                || (($permissions & 0022) !== 0 && ($permissions & 01000) === 0)
            ) {
                throw new RuntimeException('Direct runtime code directories must be operator-controlled.');
            }
        }
        $this->helper = $helper;
    }

    public function attachment(string $name): array
    {
        return array('/usr/bin/python3', $this->helper, '--config', $this->configuration, 'start', $name);
    }

    public function prepare(string $name, string $artifact, string $mode): array
    {
        return $this->control('prepare', array($name, $artifact, $mode));
    }

    public function describe(string $artifact): array
    {
        return $this->control('describe', array($artifact));
    }

    public function remove(string $name): array
    {
        $result = $this->control('remove', array($name));
        if (($result['cleanup_confirmed'] ?? false) !== true) {
            throw new RuntimeException('Direct worker cleanup was not confirmed.');
        }
        return $result;
    }

    public function recover(): void
    {
        $this->control('recover', array());
    }

    private function control(string $operation, array $arguments): array
    {
        $command = array('/usr/bin/python3', $this->helper, '--config', $this->configuration,
            $operation, ...$arguments);
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Direct runtime control unavailable.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errorBytes = 0;
        $seconds = match ($operation) {
            'remove' => 12,
            'recover' => 48,
            default => 4,
        };
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        try {
            do {
                $output .= stream_get_contents($pipes[1], 8192);
                $errorBytes += strlen(stream_get_contents($pipes[2], 8192));
                if (strlen($output) > 8192 || $errorBytes > 8192 || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Direct runtime control limit.');
                }
                $state = proc_get_status($process);
                if ($state['running']) {
                    usleep(1000);
                }
            } while ($state['running']);
            $output .= stream_get_contents($pipes[1], 8193);
            if ($state['exitcode'] !== 0 || strlen($output) > 8192) {
                throw new RuntimeException('Direct runtime control failed; inspect operator state.');
            }
            return json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
