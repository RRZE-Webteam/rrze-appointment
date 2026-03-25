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

        foreach (['booking_pending_subject', 'booking_pending_body', 'booking_pending_body_html',
                  'booking_booker_subject', 'booking_booker_body', 'booking_booker_body_html',
                  'booking_host_subject', 'booking_host_body', 'booking_host_body_html',
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

    /**
     * Eingebautes Standard-Template (nicht editierbar).
     * Wird verwendet wenn tplId = 0.
     */
    public static function getDefault(string $type): array
    {
        $defaults = [
            'booking_pending' => [
                'subject'   => 'Terminanfrage bestätigen: [titel] am [datum]',
                'body'      => "Bitte bestätigen Sie Ihren Terminwunsch:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\n\nBestätigung: [bestaetigungs_link]\nStornieren: [storno_link]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Bitte bestätigen Sie Ihren Terminwunsch:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table><p><a href="[bestaetigungs_link]">Termin jetzt bestätigen</a> &nbsp;|&nbsp; <a href="[storno_link]">Anfrage stornieren</a></p><p><a href="[impressum_link]">Impressum</a></p>',
            ],
            'booking_booker' => [
                'subject'   => 'Buchungsbestätigung: [titel] am [datum]',
                'body'      => "Ihr Termin wurde bestätigt:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\n\nStornieren: [storno_link]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Ihr Termin wurde bestätigt:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table><p><a href="[storno_link]">Termin stornieren</a></p><p><a href="[impressum_link]">Impressum</a></p>',
            ],
            'booking_host' => [
                'subject'   => 'Neue Buchung: [titel] am [datum]',
                'body'      => "Neue Buchung eingegangen:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\nGebucht von: [name] ([email])\nNachricht: [nachricht]\n\nStornieren: [storno_link]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Neue Buchung eingegangen:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr><tr><th>Gebucht von</th><td>[name] ([email])</td></tr><tr><th>Nachricht</th><td>[nachricht]</td></tr></table><p><a href="[storno_link]">Termin stornieren</a></p><p><a href="[impressum_link]">Impressum</a></p>',
            ],
            'reminder_admin' => [
                'subject'   => 'Erinnerung: [titel] am [datum]',
                'body'      => "Erinnerung an folgenden Termin:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\nGebucht von: [name] ([email])\n\nStornieren: [storno_link]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Erinnerung an folgenden Termin:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr><tr><th>Gebucht von</th><td>[name] ([email])</td></tr></table><p><a href="[storno_link]">Termin stornieren</a></p><p><a href="[impressum_link]">Impressum</a></p>',
            ],
            'reminder_booker' => [
                'subject'   => 'Erinnerung: [titel] am [datum]',
                'body'      => "Erinnerung an Ihren Termin:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\n\nStornieren: [storno_link]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Erinnerung an Ihren Termin:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table><p><a href="[storno_link]">Termin stornieren</a></p><p><a href="[impressum_link]">Impressum</a></p>',
            ],
            'cancellation' => [
                'subject'   => 'Stornierung: [titel] am [datum]',
                'body'      => "Ihr Termin wurde storniert:\n\nTermin: [titel]\nDatum: [datum]\nZeit: [uhrzeit]\nOrt: [ort]\n\nImpressum: [impressum_link]",
                'body_html' => '<p>Ihr Termin wurde storniert:</p><table><tr><th>Termin</th><td>[titel]</td></tr><tr><th>Datum</th><td>[datum]</td></tr><tr><th>Zeit</th><td>[uhrzeit]</td></tr><tr><th>Ort</th><td>[ort]</td></tr></table><p><a href="[impressum_link]">Impressum</a></p>',
            ],
        ];

        return $defaults[$type] ?? ['subject' => '', 'body' => '', 'body_html' => ''];
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

        foreach (['booking_pending', 'booking_booker', 'booking_host', 'reminder_admin', 'reminder_booker', 'cancellation'] as $key) {
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
