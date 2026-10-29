<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use RuntimeException;

/** Test-only trusted operator control, restricted to the fresh nonce-named fixture. */
final class LiveFixtureControl
{
    public function __construct(private readonly string $cli, private readonly string $post, string $token)
    {
        if (
            !preg_match('/\A[a-f0-9]{64}\z/D', $cli) || !preg_match('/\A[1-9][0-9]*\z/D', $post)
            || !preg_match('/\A[a-f0-9]{32}\z/D', $token)
        ) {
            throw new RuntimeException('Invalid fixture control scope.');
        }
        $output = $this->command(array('get', $post, '--field=post_name'));
        $lines = preg_split('/\R/', trim($output));
        if (end($lines) !== 'axiompress-probe-' . $token) {
            throw new RuntimeException('Refusing to modify a non-fixture post.');
        }
    }

    public function visibility(string $status): void
    {
        if (!in_array($status, array('publish', 'private'), true)) {
            throw new RuntimeException('Unsupported fixture visibility.');
        }
        $this->command(array('update', $this->post, '--post_status=' . $status));
    }

    private function command(array $arguments): string
    {
        $command = array_merge(
            array('docker', 'exec', '--user', 'wp_php', $this->cli, 'wp', 'post'),
            $arguments,
            array('--path=/var/www/src')
        );
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Fixture control failed to start.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = hrtime(true) + 15_000_000_000;
        try {
            do {
                $output .= stream_get_contents($pipes[1], 8192);
                $errors .= stream_get_contents($pipes[2], 8192);
                if (strlen($output) + strlen($errors) > 65536 || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Fixture control deadline/output limit.');
                }
                $state = proc_get_status($process);
                if ($state['running']) {
                    usleep(1000);
                }
            } while ($state['running']);
            $output .= stream_get_contents($pipes[1], 65537 - strlen($output));
            if ($state['exitcode'] !== 0 || strlen($output) + strlen($errors) > 65536) {
                throw new RuntimeException('Fixture control failed.');
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
