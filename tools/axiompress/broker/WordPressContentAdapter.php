<?php

declare(strict_types=1);

namespace AxiomPress\Broker;

use stdClass;
use WP_Post;
use WP_User;

/** Trusted transitional adapter; call only after the core CLI/test bootstrap. */
final class WordPressContentAdapter
{
    public function canRead(string $actor, string $site, string $contentId): bool
    {
        $post = $this->post($site, $contentId);
        if ($post === null) {
            return false;
        }
        if (!in_array($post->post_status, array('publish', 'private', 'draft', 'pending', 'future'), true)) {
            return false;
        }
        $user = null;
        if ($actor !== 'anonymous') {
            $userId = self::id($actor, 'user');
            if ($userId === null) {
                return false;
            }
            $user = new WP_User($userId, '', get_current_blog_id());
            if (!$user->exists()) {
                return false;
            }
        }
        // A post password is not a broker capability or a browser cookie exemption.
        if ($post->post_password !== '') {
            return $user !== null && user_can($user, 'edit_post', $post->ID);
        }
        if (is_post_publicly_viewable($post)) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if ($post->post_status === 'private') {
            return user_can($user, 'read_post', $post->ID);
        }
        return in_array($post->post_status, array('draft', 'pending', 'future'), true)
            && user_can($user, 'edit_post', $post->ID);
    }

    /** Resource reads are reached only through ContentBroker's policy checks. */
    public function read(string $site, string $contentId): ?stdClass
    {
        $post = $this->post($site, $contentId);
        if ($post === null) {
            return null;
        }
        if (strlen($post->post_title) > 4096 || strlen($post->post_content) > 131072) {
            throw new BrokerError('quota_exceeded');
        }
        // Do not render blocks, execute shortcodes, or apply plugin content filters.
        $title = html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode(wp_strip_all_tags($post->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $revision = hash('sha256', wp_json_encode(array(
            $site, $post->ID, $post->post_modified_gmt, $post->post_title, $post->post_content, $post->post_status,
        ), JSON_THROW_ON_ERROR));
        return (object) array('content_id' => $contentId, 'revision' => $revision, 'title' => $title, 'text' => $text);
    }

    private function post(string $site, string $contentId): ?WP_Post
    {
        if (self::id($site, 'site') !== get_current_blog_id()) {
            return null;
        }
        $postId = self::id($contentId, 'post');
        if ($postId === null) {
            return null;
        }
        $post = get_post($postId);
        if (!$post instanceof WP_Post || !in_array($post->post_type, array('post', 'page'), true)) {
            return null;
        }
        return $post;
    }

    private static function id(string $value, string $prefix): ?int
    {
        if (!preg_match('/\A' . $prefix . ':([1-9][0-9]*)\z/D', $value, $match)) {
            return null;
        }
        $id = filter_var($match[1], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return $id === false ? null : $id;
    }
}
