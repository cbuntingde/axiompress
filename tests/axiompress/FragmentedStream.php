<?php

declare(strict_types=1);

namespace AxiomPress\Tests;

// PHP stream-wrapper callbacks require these exact underscore-separated names.
// phpcs:disable PSR1.Methods.CamelCapsMethodName

/** Test transport delivering one byte per underlying read. */
final class FragmentedStream
{
    public $context;
    public static string $data = '';
    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read($count): string
    {
        return substr(self::$data, $this->position++, 1);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_stat(): array
    {
        return array();
    }
}
