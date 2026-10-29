<?php

declare(strict_types=1);

namespace AxiomPress\Protocol;

use RuntimeException;

/** A terminal protocol violation; callers must close the invocation channel. */
final class ProtocolError extends RuntimeException
{
}
