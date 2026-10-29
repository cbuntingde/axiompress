<?php

declare(strict_types=1);

namespace AxiomPress\Supervisor;

use RuntimeException;

/** The reservation survives process death; the shared maintenance lock does not. */
final class AdmissionLease
{
    public function __construct(
        private mixed $maintenance,
        private readonly string $path,
        private readonly string $allocatorPath
    ) {
    }

    public function finish(bool $cleanupConfirmed): void
    {
        try {
            if ($cleanupConfirmed) {
                $allocator = fopen($this->allocatorPath, 'ce');
                if ($allocator === false) {
                    throw new RuntimeException('Admission release storage unavailable.');
                }
                try {
                    if (!flock($allocator, LOCK_EX | LOCK_NB) || !unlink($this->path)) {
                        throw new RuntimeException('Admission release deferred; recovery required.');
                    }
                } finally {
                    fclose($allocator);
                }
            }
        } finally {
            fclose($this->maintenance);
        }
    }
}
