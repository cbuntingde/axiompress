<?php

declare(strict_types=1);

namespace AxiomPress\Broker;

use Closure;
use Throwable;

/** Trusted supervisor state. No method accepts identity assertions from plugin JSON. */
final class InvocationRegistry
{
    private array $records = array();
    private Closure $clock;

    /** $generation returns an authorized content.read generation, or null when disabled/denied. */
    public function __construct(private readonly Closure $generation, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function issue(
        string $connection,
        string $installation,
        string $site,
        string $actor,
        array $contentIds
    ): string {
        $this->prune();
        foreach (array($connection, $installation, $site, $actor) as $id) {
            self::identifier($id);
        }
        if ($contentIds === array() || count($contentIds) > 100) {
            throw new BrokerError('invalid_request');
        }
        foreach ($contentIds as $id) {
            if (!is_string($id)) {
                throw new BrokerError('invalid_request');
            }
            self::identifier($id);
        }
        $generation = $this->currentGeneration($installation, $site);
        if (!is_int($generation) || $generation < 0) {
            throw new BrokerError('forbidden');
        }
        foreach ($this->records as $record) {
            if (
                $record['connection'] === $connection
                || ($record['installation'] === $installation && $record['site'] === $site)
            ) {
                throw new BrokerError('quota_exceeded');
            }
        }
        if (count($this->records) >= 4) {
            throw new BrokerError('quota_exceeded');
        }
        $handle = bin2hex(random_bytes(32));
        $this->records[hash('sha256', $handle)] = array(
            'connection' => $connection,
            'installation' => $installation,
            'site' => $site,
            'actor' => $actor,
            'contents' => array_fill_keys($contentIds, true),
            'generation' => $generation,
            'deadline' => ($this->clock)() + 5_000_000_000,
            'requests' => array(),
            'in_flight' => false,
        );
        return $handle;
    }

    /** Consume one request before resource access; all attempts count toward the quota. */
    public function begin(string $connection, string $handle, string $requestId, string $contentId): array
    {
        self::identifier($requestId);
        $record = $this->context($connection, $handle);
        $key = hash('sha256', $handle);
        if ($record['in_flight']) {
            throw new BrokerError('quota_exceeded');
        }
        if (isset($record['requests'][$requestId])) {
            throw new BrokerError('conflict');
        }
        if (count($record['requests']) >= 8) {
            throw new BrokerError('quota_exceeded');
        }
        $this->records[$key]['requests'][$requestId] = true;
        if (!isset($record['contents'][$contentId])) {
            throw new BrokerError('not_found');
        }
        $this->records[$key]['in_flight'] = true;
        return $record;
    }

    public function finish(string $connection, string $handle): void
    {
        $key = hash('sha256', $handle);
        if (isset($this->records[$key]) && $this->records[$key]['connection'] === $connection) {
            $this->records[$key]['in_flight'] = false;
        }
    }

    /** Recheck expiry, channel binding and grants immediately before returning private data. */
    public function context(string $connection, string $handle): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $handle)) {
            throw new BrokerError('unauthenticated');
        }
        $key = hash('sha256', $handle);
        $record = $this->records[$key] ?? null;
        if ($record === null || !hash_equals($record['connection'], $connection)) {
            throw new BrokerError('unauthenticated');
        }
        if (($this->clock)() >= $record['deadline']) {
            unset($this->records[$key]);
            throw new BrokerError('deadline_exceeded');
        }
        if ($this->currentGeneration($record['installation'], $record['site']) !== $record['generation']) {
            unset($this->records[$key]);
            throw new BrokerError('forbidden');
        }
        return $record;
    }

    public function closeConnection(string $connection): void
    {
        foreach ($this->records as $key => $record) {
            if ($record['connection'] === $connection) {
                unset($this->records[$key]);
            }
        }
    }

    public function revoke(string $installation, string $site): void
    {
        foreach ($this->records as $key => $record) {
            if ($record['installation'] === $installation && $record['site'] === $site) {
                unset($this->records[$key]);
            }
        }
    }

    private function prune(): void
    {
        foreach ($this->records as $key => $record) {
            if (
                ($this->clock)() >= $record['deadline']
                || $this->currentGeneration($record['installation'], $record['site']) !== $record['generation']
            ) {
                unset($this->records[$key]);
            }
        }
    }

    private static function identifier(string $id): void
    {
        if (strlen($id) > 128 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $id)) {
            throw new BrokerError('invalid_request');
        }
    }
    private function currentGeneration(string $installation, string $site): mixed
    {
        try {
            return ($this->generation)($installation, $site);
        } catch (Throwable $error) {
            throw new BrokerError('internal_error', $error);
        }
    }
}
