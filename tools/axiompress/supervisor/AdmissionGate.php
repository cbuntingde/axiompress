<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use Closure;
use RuntimeException;
use Throwable;

/** Single-host, operator-owned local filesystem only. Never unlink the lock files. */
final class AdmissionGate
{
    public function __construct(private readonly string $directory)
    {
        if (!str_starts_with($directory, '/') || !is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Private absolute admission directory required.');
        }
    }

    /** Four global slots; one per site and installation. Overload fails immediately, without a queue. */
    public function acquire(string $installation, string $site): AdmissionLease
    {
        if ($installation === '' || $site === '' || strlen($installation) > 256 || strlen($site) > 256) {
            throw new RuntimeException('Invalid admission scope.');
        }
        $maintenance = $this->lock('maintenance', LOCK_SH);
        try {
            $allocator = $this->lock('allocator', LOCK_EX);
            try {
                $empty = null;
                for ($slot = 0; $slot < 4; $slot++) {
                    $path = $this->directory . '/slot-' . $slot;
                    clearstatcache(true, $path);
                    if (!file_exists($path)) {
                        $empty ??= $path;
                        continue;
                    }
                    if (is_link($path) || filesize($path) > 1024) {
                        throw new RuntimeException('Invalid admission reservation.');
                    }
                    $record = json_decode(file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
                    if (
                        !is_array($record) || array_keys($record) !== array('installation', 'site')
                        || !is_string($record['installation']) || !is_string($record['site'])
                    ) {
                        throw new RuntimeException('Invalid admission reservation.');
                    }
                    if ($record['installation'] === $installation || $record['site'] === $site) {
                        throw new RuntimeException('admission_busy');
                    }
                }
                if ($empty === null) {
                    throw new RuntimeException('admission_busy');
                }
                $record = json_encode(array('installation' => $installation, 'site' => $site), JSON_THROW_ON_ERROR);
                $file = fopen($empty, 'xe');
                if ($file === false) {
                    throw new RuntimeException('Reservation creation failed.');
                }
                try {
                    if (fwrite($file, $record) !== strlen($record) || !fflush($file) || !fsync($file)) {
                        throw new RuntimeException('Reservation persistence failed.');
                    }
                } finally {
                    fclose($file);
                }
                return new AdmissionLease($maintenance, $empty, $this->directory . '/allocator.lock');
            } finally {
                fclose($allocator);
            }
        } catch (Throwable $error) {
            fclose($maintenance);
            throw $error;
        }
    }

    /** Cleanup must succeed before stale reservations are cleared. Live owners block recovery. */
    public function recover(Closure $cleanup): void
    {
        $maintenance = $this->lock('maintenance', LOCK_EX);
        try {
            $allocator = $this->lock('allocator', LOCK_EX);
            try {
                $cleanup();
                for ($slot = 0; $slot < 4; $slot++) {
                    $path = $this->directory . '/slot-' . $slot;
                    if (file_exists($path) && !unlink($path)) {
                        throw new RuntimeException('Reservation recovery failed.');
                    }
                }
            } finally {
                fclose($allocator);
            }
        } finally {
            fclose($maintenance);
        }
    }

    private function lock(string $name, int $operation): mixed
    {
        $path = $this->directory . '/' . $name . '.lock';
        if (is_link($path)) {
            throw new RuntimeException('Invalid admission lock.');
        }
        // Runtime subprocesses must not keep the owner's lock alive after a crash.
        $file = fopen($path, 'ce');
        if ($file === false) {
            throw new RuntimeException('Admission storage unavailable.');
        }
        if (!flock($file, $operation | LOCK_NB)) {
            fclose($file);
            throw new RuntimeException('admission_busy');
        }
        return $file;
    }
}
