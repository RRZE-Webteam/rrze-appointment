<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

class MailTemplatePost
{
    const POST_TYPE = 'rrze_appt_mail_tpl';

    const TYPES = [
        'booking'          => 'Buchungsbestätigung (an Admin / Person)',
        'reminder_admin'   => 'Erinnerungsmail (an Person / Admin)',
        'reminder_booker'  => 'Erinnerungsmail (an Buchenden)',
    ];

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'saveMetaBox']);

        register_post_type(self::POST_TYPE, [
            'label'           => __('Mail-Vorlagen', 'rrze-appointment'),
            'labels'          => [
                'name'          => __('Mail-Vorlagen', 'rrze-appointment'),
                'singular_name' => __('Mail-Vorlage', 'rrze-appointment'),
                'add_new_item'  => __('Neue Mail-Vorlage', 'rrze-appointment'),
                'edit_item'     => __('Mail-Vorlage bearbeiten', 'rrze-appointment'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'options-general.php',
            'show_in_rest'    => true,
            'rest_base'       => 'rrze-mail-templates',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        $restFields = ['type', 'booking_subject', 'booking_body', 'booking_body_html',
                       'reminder_admin_subject', 'reminder_admin_body', 'reminder_admin_body_html',
                       'reminder_booker_subject', 'reminder_booker_body', 'reminder_booker_body_html'];

        foreach ($restFields as $field) {
            register_post_meta(self::POST_TYPE, 'tpl_' . $field, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => fn() => current_user_can('edit_posts'),
            ]);
        }
    }

    public static function addMetaBox(): void
    {
        add_meta_box(
            'rrze_mail_tpl_fields',
            __('Vorlage', 'rrze-appointment'),
            [self::class, 'renderMetaBox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('rrze_mail_tpl_save', 'rrze_mail_tpl_nonce');

        $sections = [
            'booking'         => __('Buchungsbestätigung (an Admin / Person)', 'rrze-appointment'),
            'reminder_admin'  => __('Erinnerungsmail (an Person / Admin)', 'rrze-appointment'),
            'reminder_booker' => __('Erinnerungsmail (an Buchenden)', 'rrze-appointment'),
        ];

        foreach ($sections as $key => $label) {
            $subject  = (string) get_post_meta($post->ID, "tpl_{$key}_subject", true);
            $body     = (string) get_post_meta($post->ID, "tpl_{$key}_body", true);
            $bodyHtml = (string) get_post_meta($post->ID, "tpl_{$key}_body_html", true);
            $plainId  = "tpl_{$key}_body";
            $htmlId   = "tpl_{$key}_body_html";
            ?>
            <h3 style="border-top:1px solid #dcdcde;padding-top:1rem;margin-top:1.5rem;"><?php echo esc_html($label); ?></h3>
            <table class="form-table" style="margin-top:0">
                <tr>
                    <th><label for="tpl_<?php echo esc_attr($key); ?>_subject"><?php esc_html_e('Betreff', 'rrze-appointment'); ?></label></th>
                    <td>
                        <input type="text" id="tpl_<?php echo esc_attr($key); ?>_subject"
                               name="tpl_<?php echo esc_attr($key); ?>_subject"
                               value="<?php echo esc_attr($subject); ?>" class="large-text" />
                        <?php self::renderInsertButton("tpl_{$key}_subject"); ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Mailtext', 'rrze-appointment'); ?></th>
                    <td><?php self::renderMailTabs($plainId, $htmlId, $body, $bodyHtml); ?></td>
                </tr>
            </table>
            <?php
        }
    }

    private static function renderMailTabs(string $plainId, string $htmlId, string $plainValue, string $htmlValue): void
    {
        ?>
        <div class="rrze-appt-tabs" data-uid="<?php echo esc_attr($plainId); ?>">
            <div class="rrze-appt-tab-nav" style="display:flex;gap:0;margin-bottom:-1px;">
                <button type="button" class="rrze-appt-tab-btn button" data-tab="plain" style="border-bottom-color:#fff;z-index:1;">
                    <?php esc_html_e('Plaintext', 'rrze-appointment'); ?>
                </button>
                <button type="button" class="rrze-appt-tab-btn button" data-tab="html" style="background:#f6f7f7;border-bottom-color:#dcdcde;">
                    <?php esc_html_e('HTML', 'rrze-appointment'); ?>
                </button>
            </div>
            <div style="border:1px solid #dcdcde;padding:0.75rem;">
                <div class="rrze-appt-tab-panel" data-panel="plain">
                    <textarea id="<?php echo esc_attr($plainId); ?>" name="<?php echo esc_attr($plainId); ?>"
                              rows="6" class="large-text"><?php echo esc_textarea($plainValue); ?></textarea>
                    <?php self::renderInsertButton($plainId); ?>
                </div>
                <div class="rrze-appt-tab-panel" data-panel="html" style="display:none;">
                    <?php
                    wp_editor($htmlValue, $htmlId, [
                        'textarea_name' => $htmlId,
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'         => false,
                        'tinymce'       => true,
                        'quicktags'     => true,
                    ]);
                    self::renderInsertButton($htmlId, true);
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderInsertButton(string $targetId, bool $isTinymce = false): void
    {
        $placeholders = Settings::PLACEHOLDERS;
        echo '<div style="position:relative;display:inline-block;margin-top:0.4rem;">';
        printf(
            '<button type="button" class="button rrze-appt-insert-btn" data-target="%s" data-tinymce="%s">%s &#9660;</button>',
            esc_attr($targetId),
            $isTinymce ? '1' : '0',
            esc_html__('Platzhalter einfügen', 'rrze-appointment')
        );
        echo '<ul class="rrze-appt-insert-dropdown" style="display:none;position:absolute;z-index:100;background:#fff;border:1px solid #dcdcde;box-shadow:0 2px 6px rgba(0,0,0,.15);margin:0;padding:0;list-style:none;min-width:220px;">';
        foreach ($placeholders as $tag => $desc) {
            printf(
                '<li><button type="button" class="rrze-appt-insert-tag" data-tag="%s" style="display:block;width:100%%;text-align:left;padding:6px 12px;background:none;border:none;cursor:pointer;font-size:13px;"><code>%s</code> <span style="color:#50575e;">%s</span></button></li>',
                esc_attr($tag),
                esc_html($tag),
                esc_html__($desc, 'rrze-appointment')
            );
        }
        echo '</ul></div>';
    }

    public static function saveMetaBox(int $postId): void
    {
        if (!isset($_POST['rrze_mail_tpl_nonce']) || !wp_verify_nonce($_POST['rrze_mail_tpl_nonce'], 'rrze_mail_tpl_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        foreach (['booking', 'reminder_admin', 'reminder_booker'] as $key) {
            update_post_meta($postId, "tpl_{$key}_subject",  sanitize_text_field($_POST["tpl_{$key}_subject"] ?? ''));
            update_post_meta($postId, "tpl_{$key}_body",     sanitize_textarea_field($_POST["tpl_{$key}_body"] ?? ''));
            update_post_meta($postId, "tpl_{$key}_body_html", wp_kses_post($_POST["tpl_{$key}_body_html"] ?? ''));
        }
    }

    /**
     * Gibt alle publizierten Vorlagen als Array zurück (für REST/Block-Editor).
     */
    public static function getAll(): array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
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

    /**
     * Gibt die Felder einer Vorlage für einen bestimmten Typ zurück.
     * $type: 'booking' | 'reminder_admin' | 'reminder_booker'
     */
    public static function getTemplateForType(int $postId, string $type): ?array
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
            return null;
        }
        return [
            'subject'   => (string) get_post_meta($postId, "tpl_{$type}_subject", true),
            'body'      => (string) get_post_meta($postId, "tpl_{$type}_body", true),
            'body_html' => (string) get_post_meta($postId, "tpl_{$type}_body_html", true),
        ];
    }
}
