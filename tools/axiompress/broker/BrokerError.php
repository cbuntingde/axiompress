<?php

declare(strict_types=1);

namespace AxiomPress\Broker;

use RuntimeException;
use Throwable;

/** Safe policy failure code, with no invocation or content data. */
final class BrokerError extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
