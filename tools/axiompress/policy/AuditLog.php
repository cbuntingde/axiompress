<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use PDOException;
use RuntimeException;
use Throwable;

/** Append-only operator audit trail for installation approvals, updates and revocations.
 * A full spool rotates to a timestamped archive within a count budget. */
final class AuditLog
{
    public const MAX_RECORD_BYTES = 4096;

    private function __construct(private string $path, private int $maxBytes, private int $maxArchives)
    {
    }

    public static function open(string $path, int $maxBytes = 1073741824, int $maxArchives = 30): self
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_dir(dirname($path))) {
            throw new RuntimeException('Trusted absolute audit path required.');
        }
        if ($maxBytes <= self::MAX_RECORD_BYTES || $maxArchives < 0 || $maxArchives > 10000) {
            throw new RuntimeException('Audit spool capacity must hold one record.');
        }
        return new self($path, $maxBytes, $maxArchives);
    }

    /**
     * Run an operator mutation under the audit lock and append exactly one record.
     * Mutations are refused before they run when the spool cannot hold another
     * maximum-size record. Crash recovery compares the spool against describe output.
     */
    public function record(callable $mutation, array $context): mixed
    {
        $this->validateContext($context);
        $handle = $this->acquire();
        try {
            try {
                $result = $mutation();
            } catch (Throwable $error) {
                $this->write($handle, $this->entry($context, 'rejected', null, $error));
                throw $error;
            }
            $this->write($handle, $this->entry(
                $context,
                'applied',
                is_int($result) ? $result : null,
                null
            ));
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Append a caller-built record; operator mutations use record() instead. */
    public function append(array $record): void
    {
        $handle = $this->acquire();
        try {
            $this->write($handle, $record);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource */
    private function acquire()
    {
        $oldMask = umask(0077);
        try {
            // Append mode keeps every locked write at the end of the file.
            $handle = fopen($this->path, 'ab');
        } finally {
            umask($oldMask);
        }
        if ($handle === false) {
            throw new RuntimeException('Audit spool unavailable.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Audit spool lock unavailable.');
        }
        try {
            $size = fstat($handle);
            if (
                $size === false || ($size['mode'] & 0170000) !== 0100000
                || ($size['mode'] & 077) !== 0 || $size['nlink'] !== 1
            ) {
                throw new RuntimeException('Audit spool ownership required.');
            }
            if ($size['size'] + self::MAX_RECORD_BYTES + 1 > $this->maxBytes) {
                if ($this->maxArchives === 0) {
                    throw new RuntimeException('Audit spool capacity reached; archive the spool to resume.');
                }
                flock($handle, LOCK_UN);
                fclose($handle);
                $this->rotate();
                return $this->acquire();
            }
        } catch (Throwable $error) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw $error;
        }
        return $handle;
    }

    /**
     * Move the full spool to a timestamped archive and prune archives beyond
     * the count budget. Archive names carry a fixed-width timestamp so name
     * order is chronological and a pruned base name is never reused for newer
     * records. Records are never edited; a concurrent write swept into the
     * archive stays in the trail.
     */
    private function rotate(): void
    {
        $clock = gettimeofday();
        $target = $this->path . '.' . gmdate('YmdHis', $clock['sec'])
            . '.' . sprintf('%06d', $clock['usec']);
        for ($suffix = 1; file_exists($target) || is_link($target); $suffix++) {
            if ($suffix > 100) {
                throw new RuntimeException('Audit rotation target unavailable.');
            }
            $target = $this->path . '.' . gmdate('YmdHis', $clock['sec'])
                . '.' . sprintf('%06d', $clock['usec']) . '.' . $suffix;
        }
        if (!rename($this->path, $target)) {
            throw new RuntimeException('Audit rotation failed.');
        }
        $prefix = basename($this->path);
        $entries = scandir(dirname($this->path));
        if ($entries === false) {
            throw new RuntimeException('Audit archive inventory failed.');
        }
        $archives = array();
        foreach ($entries as $name) {
            if (!str_starts_with($name, $prefix . '.')) {
                continue;
            }
            if (preg_match('/\A\.\d{14}\.\d{6}(\.\d+)?\z/', substr($name, strlen($prefix))) !== 1) {
                continue;
            }
            $archives[] = $name;
        }
        sort($archives);
        while (count($archives) > $this->maxArchives) {
            $oldest = array_shift($archives);
            if (!unlink(dirname($this->path) . '/' . $oldest)) {
                throw new RuntimeException('Audit archive pruning failed.');
            }
        }
    }

    /** @param resource $handle */
    private function write($handle, array $record): void
    {
        foreach ($record as $value) {
            if (
                (!is_int($value) && !is_string($value) && $value !== null)
                || (is_string($value) && (str_contains($value, "\0")
                || preg_match('//u', $value) !== 1))
            ) {
                throw new RuntimeException('Invalid audit record value.');
            }
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($line) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('Audit record size limit.');
        }
        if (fwrite($handle, $line . "\n") !== strlen($line) + 1 || !fflush($handle)) {
            throw new RuntimeException('Audit spool write failed.');
        }
    }

    private function validateContext(array $context): void
    {
        if (
            in_array($context['action'] ?? null, array('publisher-add', 'publisher-revoke'), true)
        ) {
            if (
                !isset($context['key_id']) || !is_string($context['key_id'])
                || strlen($context['key_id']) > 128
            ) {
                throw new RuntimeException('Invalid audit context.');
            }
            return;
        }
        $keys = array('action', 'installation', 'site', 'expected_generation', 'image', 'grants');
        foreach ($keys as $key) {
            if (!array_key_exists($key, $context)) {
                throw new RuntimeException('Incomplete audit context.');
            }
        }
        if (
            !in_array($context['action'], array('approve-read', 'disable'), true)
            || !is_string($context['installation']) || strlen($context['installation']) > 128
            || !is_string($context['site']) || strlen($context['site']) > 128
            || !is_int($context['expected_generation']) || $context['expected_generation'] < 1
            || !is_string($context['image']) || strlen($context['image']) > 128
            || !is_string($context['grants']) || strlen($context['grants']) > 32
        ) {
            throw new RuntimeException('Invalid audit context.');
        }
    }

    private function entry(array $context, string $outcome, ?int $generation, ?Throwable $error): array
    {
        $this->validateContext($context);
        return array(
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
            'action' => $context['action'],
            'installation' => $context['installation'] ?? '',
            'site' => $context['site'] ?? '',
            'expected_generation' => $context['expected_generation'] ?? 0,
            'result_generation' => $generation,
            'image' => $context['image'] ?? '',
            'grants' => $context['grants'] ?? '',
            'key_id' => $context['key_id'] ?? '',
            'outcome' => $outcome,
            'error' => $error === null ? '' : self::diagnostic($error),
        );
    }

    private static function diagnostic(Throwable $error): string
    {
        if ($error instanceof PDOException) {
            return 'database_unavailable';
        }
        if (!$error instanceof RuntimeException) {
            return 'internal_failure';
        }
        $message = $error->getMessage();
        if (preg_match('//u', $message) !== 1 || str_contains($message, "\0")) {
            return 'unreadable_diagnostic';
        }
        return substr($message, 0, 160);
    }
}
