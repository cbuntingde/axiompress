<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use JsonException;
use stdClass;

/** Bounded callers accept a flat object with exactly these string fields. */
final class JsonInput
{
    public static function object(string $body, array $expected): ?array
    {
        try {
            $value = json_decode($body, false, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return null;
        }
        if (!$value instanceof stdClass) {
            return null;
        }
        $input = get_object_vars($value);
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== $expected) {
            return null;
        }
        foreach ($input as $field) {
            if (!is_string($field) || str_contains($field, "\0")) {
                return null;
            }
        }
        // JSON is already valid: each unescaped quote bounds a string. Duplicate keys
        // add string tokens even though json_decode overwrites them. Scan linearly,
        // including heavily escaped strings, without regex recursion/backtracking limits.
        $quotes = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '\\') {
                $i++;
            } elseif ($body[$i] === '"') {
                $quotes++;
            }
        }
        return $quotes === count($expected) * 4 ? $input : null;
    }
}
