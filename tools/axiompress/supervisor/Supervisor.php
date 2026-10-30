<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use AxiomPress\Broker\BrokerError;
use AxiomPress\Broker\ContentBroker;
use AxiomPress\Broker\InvocationRegistry;
use AxiomPress\Protocol\Protocol;
use AxiomPress\Policy\PolicyStore;
use Closure;
use RuntimeException;
use stdClass;

/** Linux-only trusted infrastructure; never expose runtime options to worker messages. */
final class Supervisor
{
    private const LABEL = 'dev.axiompress.prototype=worker-v1';
    private array $lastState = array();
    private ?string $lastBrokerDenial = null;
    private ?AdmissionGate $admission = null;
    private bool $cleanupConfirmed = true;

    public static function fromPolicy(
        PolicyStore $policy,
        string $installation,
        string $site,
        Closure $canRead,
        Closure $read,
        string $admissionDirectory = '/admission',
        string $runtime = 'runc',
        ?string $journalDirectory = null,
        ?string $pool = null,
        ?DirectRuntime $direct = null
    ): self {
        $record = $policy->resolve($installation, $site);
        $image = $record['image'];
        $registry = new InvocationRegistry(static fn(string $id, string $requestedSite): ?int =>
            $id === $installation && $requestedSite === $site ? $policy->generation($id, $site, $image) : null);
        $supervisor = new self(
            $image,
            $registry,
            new ContentBroker($registry, $canRead, $read),
            'reading',
            $runtime,
            $journalDirectory,
            $pool,
            $direct
        );
        $supervisor->admission = new AdmissionGate($admissionDirectory);
        return $supervisor;
    }

    public function workerImage(): string
    {
        return $this->image;
    }

    public function __construct(
        private readonly string $image,
        private readonly InvocationRegistry $registry,
        private readonly ContentBroker $broker,
        private readonly string $mode = 'reading',
        private readonly string $runtime = 'runc',
        private readonly ?string $journalDirectory = null,
        private readonly ?string $pool = null,
        private readonly ?DirectRuntime $direct = null
    ) {
        if (
            PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pcntl')
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)
        ) {
            throw new RuntimeException('Linux, pcntl and immutable local image ID required.');
        }
        if (
            !in_array($mode, array('reading', 'probes', 'hang', 'bad-frame', 'partial', 'stderr',
            'memory', 'crash', 'fork', 'extra', 'forged', 'stdout'), true)
        ) {
            throw new RuntimeException('Unknown trusted worker fixture.');
        }
        if (!in_array($runtime, array('runc', 'runsc'), true)) {
            throw new RuntimeException('Unsupported trusted runtime.');
        }
        if ($direct !== null && $runtime !== 'runsc') {
            throw new RuntimeException('Direct workers require gVisor.');
        }
        if (
            $journalDirectory !== null && (!is_dir($journalDirectory) || is_link($journalDirectory)
            || !str_starts_with($journalDirectory, '/'))
        ) {
            throw new RuntimeException('Private worker journal required.');
        }
        if (
            ($journalDirectory === null) !== ($pool === null)
            || ($pool !== null && !preg_match('/\A[a-f0-9]{32}\z/D', $pool))
        ) {
            throw new RuntimeException('Dedicated service pool identity required.');
        }
    }

    /** Operator-only recovery, before admission. Run this under a single-owner supervisor deployment. */
    public function lastState(): array
    {
        return $this->lastState;
    }

    public function lastBrokerDenial(): ?string
    {
        return $this->lastBrokerDenial;
    }

    public static function inspectCoreContainer(string $name): array
    {
        if (!preg_match('/\Aaxiompress-core-[a-f0-9]{24}\z/D', $name)) {
            throw new RuntimeException('Invalid core container identity.');
        }
        return json_decode(self::command(array('docker', 'inspect', $name), 3), true, 32, JSON_THROW_ON_ERROR)[0];
    }

    public static function inspectImage(string $image): array
    {
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)) {
            throw new RuntimeException('Immutable image ID required.');
        }
        return json_decode(
            self::command(array('docker', 'image', 'inspect', $image), 3),
            true,
            32,
            JSON_THROW_ON_ERROR
        )[0];
    }

    public static function reconcile(?string $pool = null): void
    {
        foreach (self::remainingWorkers($pool) as $id) {
            self::command(array('docker', 'rm', '-f', $id), 3);
        }
    }

    public static function remainingWorkers(?string $pool = null): array
    {
        if ($pool !== null && !preg_match('/\A[a-f0-9]{32}\z/D', $pool)) {
            throw new RuntimeException('Invalid service pool.');
        }
        $label = $pool === null ? self::LABEL : 'dev.axiompress.service=' . $pool;
        $ids = trim(self::command(array('docker', 'ps', '-aq', '--filter', 'label=' . $label), 3));
        $result = preg_split('/\s+/', $ids, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($result as $id) {
            if (!preg_match('/\A[a-f0-9]{12,64}\z/D', $id)) {
                throw new RuntimeException('Invalid runtime inventory.');
            }
        }
        return $result;
    }

    public function run(string $installation, string $site, string $actor, string $contentId): stdClass
    {
        $lease = $this->admission?->acquire($installation, $site);
        $this->cleanupConfirmed = true;
        try {
            return $this->runWorker($installation, $site, $actor, $contentId);
        } finally {
            $lease?->finish($this->cleanupConfirmed);
        }
    }

    private function runWorker(string $installation, string $site, string $actor, string $contentId): stdClass
    {
        $name = 'axiompress-worker-' . bin2hex(random_bytes(12));
        $label = $this->pool === null ? self::LABEL : 'dev.axiompress.service=' . $this->pool;
        $journal = $this->journalDirectory === null ? null : $this->journalDirectory . '/' . $name;
        $handle = $this->registry->issue($name, $installation, $site, $actor, array($contentId));
        $deadline = hrtime(true) + 5_000_000_000;
        $process = null;
        $pipes = array();
        $created = false;
        $createAttempted = false;
        $this->lastState = array();
        $this->lastBrokerDenial = null;
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsync = pcntl_async_signals(true);
        $previousAlarm = pcntl_alarm(0);
        if ($previousAlarm !== 0) {
            pcntl_alarm($previousAlarm);
            pcntl_async_signals($previousAsync);
            $this->registry->closeConnection($name);
            throw new RuntimeException('Supervisor requires exclusive alarm ownership.');
        }
        pcntl_signal(SIGALRM, static function (): never {
            throw new RuntimeException('deadline_exceeded');
        });
        pcntl_alarm(5);
        try {
            // No mounts, network, daemon socket, caller environment or package-supplied runtime flags.
            $createAttempted = true;
            $this->cleanupConfirmed = false;
            if ($journal !== null) {
                self::journal($journal, 'creating');
            }
            if ($this->direct !== null) {
                $host = $this->direct->prepare($name, $this->image, $this->mode);
                $created = true;
                if ($journal !== null) {
                    self::journal($journal, 'created');
                }
            } else {
                // Bound host tasks for gVisor separately from the guest's hard nproc limit.
                $pidsLimit = $this->runtime === 'runsc' ? 64 : 16;
                self::command(array(
                    'docker', 'create', '--pull=never', '--name', $name, '--label', $label,
                    '--runtime=' . $this->runtime, '--read-only', '--network=none', '--ipc=none', '--cap-drop=ALL',
                    '--security-opt=no-new-privileges:true', '--user=10001:10001',
                    '--memory=128m', '--memory-swap=128m', '--cpus=0.5', '--pids-limit=' . $pidsLimit,
                    '--ulimit=nofile=64:64', '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=64m',
                    ...($this->runtime === 'runsc' ? array('--ulimit=nproc=16:16') : array()),
                    '--log-driver=none', '--stop-timeout=1', '-i',
                    '--env', 'AXIOM_WORKER_MODE=' . $this->mode, $this->image,
                ), 3);
                $created = true;
                if ($journal !== null) {
                    self::journal($journal, 'created');
                }
                $inspection = json_decode(
                    self::command(array('docker', 'inspect', $name), 1),
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );
                $host = $inspection[0]['HostConfig'];
                $softLimits = array_column($host['Ulimits'], 'Soft', 'Name');
                $hardLimits = array_column($host['Ulimits'], 'Hard', 'Name');
                if (
                    $inspection[0]['Image'] !== $this->image
                    || !$host['ReadonlyRootfs'] || $host['NetworkMode'] !== 'none' || $host['Memory'] !== 134217728
                    || $host['MemorySwap'] !== 134217728 || $host['PidsLimit'] !== $pidsLimit
                    || $host['NanoCpus'] !== 500000000 || $host['CapDrop'] !== array('ALL')
                    || !in_array('no-new-privileges:true', $host['SecurityOpt'], true)
                    || $host['Privileged'] || $host['Runtime'] !== $this->runtime || $host['IpcMode'] !== 'none'
                    || $inspection[0]['Mounts'] !== array() || $inspection[0]['Config']['User'] !== '10001:10001'
                    || ($softLimits['nofile'] ?? null) !== 64 || ($hardLimits['nofile'] ?? null) !== 64
                    || ($this->runtime === 'runsc' && (($softLimits['nproc'] ?? null) !== 16
                    || ($hardLimits['nproc'] ?? null) !== 16))
                ) {
                    throw new RuntimeException('Runtime profile mismatch.');
                }
            }
            $attachment = $this->direct?->attachment($name) ?? array('docker', 'start', '-ai', $name);
            $process = proc_open($attachment, array(
                0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
            ), $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Worker attachment failed.');
            }
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $dispatchId = bin2hex(random_bytes(12));
            $outgoing = Protocol::encode((object) array(
                'api' => Protocol::API, 'kind' => 'dispatch', 'id' => $dispatchId,
                'operation' => 'component.render', 'invocation' => $handle,
                'params' => (object) array('content_id' => $contentId),
            ));
            $buffer = '';
            $stdout = 0;
            $stderr = 0;
            $result = null;
            $exitCode = null;
            while (true) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('deadline_exceeded');
                }
                $this->registry->context($name, $handle);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                }
                $read = array_filter(array($pipes[1], $pipes[2]), static fn($pipe) => !feof($pipe));
                $write = $outgoing !== '' ? array($pipes[0]) : array();
                if (!$status['running'] && $read === array()) {
                    break;
                }
                $except = null;
                if ($read === array() && $write === array()) {
                    usleep(1000);
                    continue;
                }
                $interrupted = false;
                // Alarm interruption is expected; unrelated stream warnings remain visible.
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
                    throw new RuntimeException('Worker I/O selection failed.');
                }
                foreach ($write as $pipe) {
                    $written = fwrite($pipe, substr($outgoing, 0, 8192));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Worker write failed.');
                    }
                    $outgoing = substr($outgoing, $written);
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Worker read failed.');
                    }
                    if ($pipe === $pipes[2]) {
                        $stderr += strlen($chunk);
                        if ($stderr > 65536) {
                            throw new RuntimeException('stderr_limit');
                        }
                        continue;
                    }
                    $stdout += strlen($chunk);
                    if ($stdout > 1048576 || strlen($buffer) + strlen($chunk) > Protocol::MAX_BYTES + 8196) {
                        throw new RuntimeException('stdout_limit');
                    }
                    $buffer .= $chunk;
                    while (strlen($buffer) >= 4) {
                        $length = unpack('Nlength', substr($buffer, 0, 4))['length'];
                        if ($length < 1 || $length > Protocol::MAX_BYTES) {
                            throw new RuntimeException('invalid_frame');
                        }
                        if (strlen($buffer) < $length + 4) {
                            break;
                        }
                        if ($result !== null || $outgoing !== '') {
                            throw new RuntimeException('Unexpected worker message order.');
                        }
                        $message = Protocol::decode(substr($buffer, 4, $length));
                        $buffer = substr($buffer, $length + 4);
                        if (
                            $message->kind === 'completion' && $message->id === $dispatchId
                            && isset($message->result)
                        ) {
                            $result = $message->result;
                        } elseif ($message->kind === 'request') {
                            try {
                                $response = $this->broker->handle($name, $message);
                            } catch (BrokerError $error) {
                                $this->lastBrokerDenial = $error->reason;
                                $response = (object) array(
                                    'api' => Protocol::API, 'kind' => 'response', 'id' => $message->id,
                                    'error' => (object) array('code' => $error->reason, 'message' => 'Request denied.',
                                        'retryable' => false, 'diagnostic_id' => bin2hex(random_bytes(12))),
                                );
                            }
                            $outgoing = Protocol::encode($response);
                        } else {
                            throw new RuntimeException('Unexpected worker message.');
                        }
                    }
                }
            }
            if ($exitCode !== 0 || $result === null || $buffer !== '' || $outgoing !== '') {
                throw new RuntimeException('incomplete_worker');
            }
            $this->registry->context($name, $handle);
            return (object) array('component' => $result, 'profile' => $host, 'image' => $this->image);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            $this->registry->closeConnection($name);
            // Stop the attachment first: undrained client pipes can delay daemon removal.
            try {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process, 9);
                    proc_close($process);
                }
            } finally {
                if ($this->direct !== null && $createAttempted) {
                    $this->lastState = $this->direct->remove($name);
                    $created = true;
                } elseif ($createAttempted && !$created) {
                    // A failed/timeout client command may still have created a daemon-side object.
                    $found = self::command(array('docker', 'ps', '-aq', '--filter',
                        'label=' . $label, '--filter', 'name=^/' . $name . '$'), 1);
                    $created = trim($found) !== '';
                }
                if ($created && $this->direct === null) {
                    try {
                        $this->lastState = json_decode(self::command(array(
                            'docker', 'inspect', '--format', '{{json .State}}', $name,
                        ), 1), true, 16, JSON_THROW_ON_ERROR);
                    } finally {
                        self::command(array('docker', 'rm', '-f', $name), 3);
                    }
                }
                $this->cleanupConfirmed = $created;
                // A timed-out create can complete later in the daemon. Retain its intent even
                // when an immediate inventory is empty; restart must not silently free capacity.
                if ($journal !== null && $created && !unlink($journal)) {
                    throw new RuntimeException('Worker journal cleanup failed.');
                }
            }
        }
    }

    private static function journal(string $path, string $stage): void
    {
        $file = fopen($path, $stage === 'creating' ? 'xe' : 'r+e');
        if ($file === false) {
            throw new RuntimeException('Worker journal unavailable.');
        }
        try {
            if (!ftruncate($file, 0) || fwrite($file, $stage) !== strlen($stage) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Worker journal persistence failed.');
            }
        } finally {
            fclose($file);
        }
    }

    private static function command(array $command, int $seconds): string
    {
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Runtime command failed to start.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        $output = '';
        $errors = '';
        try {
            do {
                $output .= stream_get_contents($pipes[1], 8192);
                $errors .= stream_get_contents($pipes[2], 8192);
                if (strlen($output) + strlen($errors) > 524288 || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Runtime command deadline/output limit.');
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    usleep(1000);
                }
            } while ($status['running']);
            $output .= stream_get_contents($pipes[1], 524289 - strlen($output));
            if (strlen($output) + strlen($errors) > 524288) {
                throw new RuntimeException('Runtime command output limit.');
            }
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Runtime command failed.');
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
