<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class MailTemplatePost
{
    const POST_TYPE = 'rrze_appt_mail_tpl';

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'public'          => false,
            'show_ui'         => false,
            'show_in_menu'    => false,
            'show_in_rest'    => true,
            'rest_base'       => 'rrze-mail-templates',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        foreach (['booking_subject', 'booking_body', 'booking_body_html',
                  'reminder_admin_subject', 'reminder_admin_body', 'reminder_admin_body_html',
                  'reminder_booker_subject', 'reminder_booker_body', 'reminder_booker_body_html',
                  'cancellation_subject', 'cancellation_body', 'cancellation_body_html'] as $field) {
            register_post_meta(self::POST_TYPE, 'tpl_' . $field, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => fn() => current_user_can('edit_posts'),
            ]);
        }
    }

    public static function getAll(): array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        return array_map(fn(\WP_Post $p) => [
            'id'    => $p->ID,
            'title' => $p->post_title,
        ], $posts);
    }

    public static function getTemplateForType(int $postId, string $type): ?array
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }
        return [
            'subject'   => (string) get_post_meta($postId, "tpl_{$type}_subject", true),
            'body'      => (string) get_post_meta($postId, "tpl_{$type}_body", true),
            'body_html' => (string) get_post_meta($postId, "tpl_{$type}_body_html", true),
        ];
    }

    public static function save(array $data): int|\WP_Error
    {
        $postId = (int) ($data['id'] ?? 0);
        $title  = sanitize_text_field($data['title'] ?? '');

        if ($postId > 0) {
            $result = wp_update_post(['ID' => $postId, 'post_title' => $title, 'post_status' => 'publish'], true);
        } else {
            $result = wp_insert_post(['post_title' => $title, 'post_type' => self::POST_TYPE, 'post_status' => 'publish'], true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        foreach (['booking', 'reminder_admin', 'reminder_booker', 'cancellation'] as $key) {
            update_post_meta($result, "tpl_{$key}_subject",   sanitize_text_field($data["{$key}_subject"] ?? ''));
            update_post_meta($result, "tpl_{$key}_body",      sanitize_textarea_field($data["{$key}_body"] ?? ''));
            update_post_meta($result, "tpl_{$key}_body_html", wp_kses_post($data["{$key}_body_html"] ?? ''));
        }

        return $result;
    }

    public static function isInUse(int $postId): array
    {
        $query = new \WP_Query([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'private', 'pending'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            's'              => '"tplId":' . $postId,
        ]);

        return array_map(fn(\WP_Post $p) => [
            'id'    => $p->ID,
            'title' => $p->post_title ?: __('(kein Titel)', 'rrze-appointment'),
            'edit'  => get_edit_post_link($p->ID),
        ], $query->posts);
    }

    public static function delete(int $postId): void
    {
        $post = get_post($postId);
        if ($post && $post->post_type === self::POST_TYPE) {
            wp_delete_post($postId, true);
        }
    }
}
