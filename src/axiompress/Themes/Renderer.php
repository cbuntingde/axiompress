<?php

declare(strict_types=1);

namespace AxiomPress\Core\Themes;

use AxiomPress\Core\Blocks\Document;
use AxiomPress\Core\HtmlResponse;
use Closure;

/** A theme is a fixed core template plus typed settings, never executable site code. */
final class Renderer
{
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function article(
        array $settings,
        array $article,
        array $terms,
        Closure $media,
        bool $preview = false
    ): HtmlResponse {
        $body = $preview ? '<p class="preview-banner">Private draft preview — this revision is not published.</p>' : '';
        $body .= '<article><h1>' . self::escape($article['title']) . '</h1><div class="article-body">';
        foreach (Document::parse($article['text']) as $block) {
            if ($block['type'] === 'image') {
                $image = $media($block['media']);
                if ($image === null) {
                    $body .= '<p class="unavailable-image">Image unavailable</p>';
                    continue;
                }
                $path = $preview ? '/v1/media/' : '/v1/sites/' . $article['site_id'] . '/media/';
                $body .= '<figure><img src="' . $path . $block['media'] . ($preview ? '/file' : '')
                    . '" alt="' . self::escape($block['alt']) . '" width="' . (int) $image['width']
                    . '" height="' . (int) $image['height'] . '" loading="lazy"></figure>';
                continue;
            }
            $tag = in_array($block['type'], array('heading', 'rich-heading'), true) ? 'h' . $block['level'] : 'p';
            $content = isset($block['html']) ? Document::inline($block['html']) : Document::text($block['text']);
            $body .= '<' . $tag . '>' . $content . '</' . $tag . '>';
        }
        $body .= '</div>';
        if ($terms !== array()) {
            $body .= '<ul class="article-terms" aria-label="Categories and tags">';
            foreach ($terms as $term) {
                $body .= '<li>' . self::escape($term['name']) . '</li>';
            }
            $body .= '</ul>';
        }
        return self::page($settings, $article['site_id'], $article['title'], $body . '</article>');
    }

    public static function listing(array $settings, int $site, array $articles): HtmlResponse
    {
        $body = '<h1>' . self::escape($settings['title']) . '</h1>';
        if ($articles === array()) {
            $body .= '<p>No articles have been published yet.</p>';
        }
        foreach ($articles as $article) {
            $body .= '<article class="article-entry"><h2><a href="/site/' . $site . '/articles/'
                . $article['id'] . '">' . self::escape($article['title']) . '</a></h2><p>Published '
                . self::escape(substr($article['published_at'], 0, 10)) . '</p></article>';
        }
        if (count($articles) === (int) $settings['page_size']) {
            $body .= '<a class="older" href="/site/' . $site . '?before=' . $articles[array_key_last($articles)]['id']
                . '">Older articles</a>';
        }
        return self::page($settings, (string) $site, $settings['title'], $body);
    }

    private static function page(array $settings, string $site, string $title, string $body): HtmlResponse
    {
        $theme = $settings['theme'] === 'ink' ? 'ink' : 'paper';
        return new HtmlResponse('<!doctype html><html lang="en" class="theme-' . $theme . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::escape($title) . ' — ' . self::escape($settings['title']) . '</title>'
            . '<link rel="stylesheet" href="/assets/public.css"></head><body>'
            . '<a class="skip-link" href="#main">Skip to content</a><header class="site-header"><a href="/site/'
            . $site . '">' . self::escape($settings['title']) . '</a><p>'
            . self::escape($settings['tagline']) . '</p></header><main id="main">' . $body
            . '</main><footer>Published with AxiomPress</footer></body></html>');
    }
}
