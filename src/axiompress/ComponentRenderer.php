<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use InvalidArgumentException;
use stdClass;

/** Core owns markup; plugin data cannot select a tag, attribute, URL, script or HTML fragment. */
final class ComponentRenderer
{
    public static function readingSummary(stdClass $component): string
    {
        $fields = array_keys(get_object_vars($component));
        sort($fields);
        if (
            $fields !== array('reading_seconds', 'type', 'word_count')
            || $component->type !== 'reading_summary'
            || !is_int($component->word_count) || $component->word_count < 0 || $component->word_count > 131072
            || !is_int($component->reading_seconds)
            || $component->reading_seconds < 0 || $component->reading_seconds > 39322
        ) {
            throw new InvalidArgumentException('Invalid reading summary component.');
        }
        return sprintf(
            '<span class="axiompress-reading-summary">%d words · %d seconds reading time</span>',
            $component->word_count,
            $component->reading_seconds
        );
    }
}
