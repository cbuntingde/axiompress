<?php

declare(strict_types=1);

namespace AxiomPress\Core\Blocks;

use AxiomPress\Core\JsonInput;
use RuntimeException;

/** A deliberately bounded Gutenberg-grammar subset. No callback registry or HTML execution. */
final class Document
{
    public static function serialize(array $blocks): string
    {
        if (!array_is_list($blocks) || count($blocks) > 64) {
            throw new RuntimeException('At most 64 declarative blocks are supported.');
        }
        $pieces = array();
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                throw new RuntimeException('Invalid block structure.');
            }
            $keys = array_keys($block);
            sort($keys);
            if (in_array($block['type'], array('rich-paragraph', 'rich-heading'), true)) {
                $heading = $block['type'] === 'rich-heading';
                if (
                    $keys !== ($heading ? array('html', 'level', 'type') : array('html', 'type'))
                    || !is_string($block['html'])
                    || ($heading && (!is_int($block['level']) || $block['level'] < 2 || $block['level'] > 4))
                ) {
                    throw new RuntimeException('Invalid versioned rich-text block.');
                }
                self::inline($block['html']);
                $name = $heading ? 'heading' : 'paragraph';
                $attributes = $heading ? '{"level":' . $block['level'] . ',"axiomVersion":1}' : '{"axiomVersion":1}';
                $tag = $heading ? 'h' . $block['level'] : 'p';
                $pieces[] = '<!-- wp:' . $name . ' ' . $attributes . " -->\n<" . $tag . '>' . $block['html']
                    . '</' . $tag . ">\n<!-- /wp:" . $name . ' -->';
                continue;
            }
            if ($block['type'] === 'image') {
                if (
                    $keys !== array('alt', 'media', 'type') || !is_string($block['media'])
                    || !preg_match('/\A[a-f0-9]{32}\z/D', $block['media']) || !is_string($block['alt'])
                    || strlen($block['alt']) > 512 || str_contains($block['alt'], "\0")
                    || preg_match('//u', $block['alt']) !== 1
                ) {
                    throw new RuntimeException('Invalid image block.');
                }
                $attributes = json_encode(
                    array('media' => $block['media'], 'alt' => $block['alt']),
                    JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                );
                $pieces[] = '<!-- wp:axiom/image ' . $attributes . ' /-->';
                continue;
            }
            $heading = $block['type'] === 'heading';
            if (
                (!$heading && $block['type'] !== 'paragraph')
                || $keys !== ($heading ? array('level', 'text', 'type') : array('text', 'type'))
                || !is_string($block['text']) || str_contains($block['text'], "\0")
                || preg_match('//u', $block['text']) !== 1
                || ($heading && (!is_int($block['level']) || $block['level'] < 2 || $block['level'] > 4))
            ) {
                throw new RuntimeException('Unsupported declarative block.');
            }
            $text = self::text($block['text']);
            $name = $heading ? 'heading {"level":' . $block['level'] . '}' : 'paragraph';
            $tag = $heading ? 'h' . $block['level'] : 'p';
            $pieces[] = '<!-- wp:' . $name . " -->\n<" . $tag . '>' . $text . '</' . $tag
                . ">\n<!-- /wp:" . ($heading ? 'heading' : 'paragraph') . ' -->';
        }
        $source = implode("\n\n", $pieces);
        if (strlen($source) > 131072 || preg_match('//u', $source) !== 1) {
            throw new RuntimeException('Document byte/encoding limit.');
        }
        return $source;
    }

    public static function parse(string $source): array
    {
        if (strlen($source) > 131072 || preg_match('//u', $source) !== 1 || str_contains($source, "\0")) {
            throw new RuntimeException('Invalid document bytes.');
        }
        if ($source === '') {
            return array();
        }
        if (!str_starts_with($source, '<!-- wp:')) {
            return array(array('type' => 'paragraph', 'text' => $source));
        }
        $blocks = array();
        $offset = 0;
        while ($offset < strlen($source) && count($blocks) < 64) {
            $paragraph = '~\G<!-- wp:paragraph -->\n<p>(.*?)</p>\n<!-- /wp:paragraph -->~su';
            $rich = '~\G<!-- wp:paragraph \{"axiomVersion":1\} -->\n<p>(.*?)</p>\n<!-- /wp:paragraph -->~su';
            $richHeading = '~\G<!-- wp:heading \{"level":([2-4]),"axiomVersion":1\} -->\n<h\1>(.*?)</h\1>\n'
                . '<!-- /wp:heading -->~su';
            if (preg_match($rich, $source, $match, 0, $offset)) {
                $blocks[] = array('type' => 'rich-paragraph', 'html' => $match[1]);
            } elseif (preg_match($richHeading, $source, $match, 0, $offset)) {
                $blocks[] = array('type' => 'rich-heading', 'level' => (int) $match[1], 'html' => $match[2]);
            } elseif (preg_match($paragraph, $source, $match, 0, $offset)) {
                $blocks[] = array('type' => 'paragraph', 'text' => html_entity_decode($match[1], ENT_HTML5, 'UTF-8'));
            } elseif (
                preg_match(
                    '~\G<!-- wp:heading \{"level":([2-4])\} -->\n<h\1>(.*?)</h\1>\n<!-- /wp:heading -->~su',
                    $source,
                    $match,
                    0,
                    $offset
                )
            ) {
                $blocks[] = array('type' => 'heading', 'level' => (int) $match[1],
                    'text' => html_entity_decode($match[2], ENT_HTML5, 'UTF-8'));
            } elseif (preg_match('~\G<!-- wp:axiom/image (.*?) /-->~su', $source, $match, 0, $offset)) {
                $attributes = JsonInput::object($match[1], array('alt', 'media'));
                if ($attributes === null) {
                    throw new RuntimeException('Invalid image attributes.');
                }
                $blocks[] = array('type' => 'image') + $attributes;
            } else {
                throw new RuntimeException('Unsupported or noncanonical block document.');
            }
            $offset += strlen($match[0]);
            if ($offset < strlen($source)) {
                if (substr($source, $offset, 2) !== "\n\n") {
                    throw new RuntimeException('Invalid block separation.');
                }
                $offset += 2;
            }
        }
        if (self::serialize($blocks) !== $source) {
            throw new RuntimeException('Noncanonical or unsupported markup.');
        }
        return $blocks;
    }

    public static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Version 1: canonical text plus attribute-free strong/em/code/br, depth at most eight. */
    public static function inline(string $html): string
    {
        if (strlen($html) > 131072 || str_contains($html, "\0") || preg_match('//u', $html) !== 1) {
            throw new RuntimeException('Invalid rich-text bytes.');
        }
        $stack = array();
        $offset = 0;
        $tokens = 0;
        while ($offset < strlen($html)) {
            if (++$tokens > 4096) {
                throw new RuntimeException('Rich-text complexity limit.');
            }
            if (preg_match('~\G</?(strong|em|code)>|\G<br>~', $html, $match, 0, $offset)) {
                $tag = $match[0];
                if ($tag !== '<br>') {
                    if (str_starts_with($tag, '</')) {
                        if (array_pop($stack) !== $match[1]) {
                            throw new RuntimeException('Unbalanced rich-text tags.');
                        }
                    } else {
                        $stack[] = $match[1];
                        if (count($stack) > 8) {
                            throw new RuntimeException('Rich-text nesting limit.');
                        }
                    }
                }
            } elseif (preg_match('~\G[^<]+~u', $html, $match, 0, $offset)) {
                if (self::text(html_entity_decode($match[0], ENT_HTML5, 'UTF-8')) !== $match[0]) {
                    throw new RuntimeException('Noncanonical rich-text content.');
                }
            } else {
                throw new RuntimeException('Unsupported rich-text markup.');
            }
            $offset += strlen($match[0]);
        }
        if ($stack !== array()) {
            throw new RuntimeException('Unclosed rich-text tag.');
        }
        return $html;
    }
}
