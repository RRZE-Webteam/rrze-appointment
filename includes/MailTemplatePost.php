<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

class MailTemplatePost
{
    const POST_TYPE = 'rrze_appt_mail_tpl';

    public static function register(): void
    {
        try {
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
        } catch (CustomException $e) {
            return;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getDefault(string $type): array
    {
        $appointment = __('Appointment', 'rrze-appointment');
        $date        = __('Date', 'rrze-appointment');
        $time        = __('Time', 'rrze-appointment');
        $location    = __('Location', 'rrze-appointment');
        $bookedBy    = __('Booked by', 'rrze-appointment');
        $message     = __('Message', 'rrze-appointment');
        $confirm     = __('Confirm appointment', 'rrze-appointment');
        $cancelReq   = __('Cancel request', 'rrze-appointment');
        $cancel      = __('Cancel appointment', 'rrze-appointment');
        $legal       = __('Legal notice', 'rrze-appointment');

        $baseTable = '<table>'
            . "<tr><th>{$appointment}</th><td>[title]</td></tr>"
            . "<tr><th>{$date}</th><td>[date]</td></tr>"
            . "<tr><th>{$time}</th><td>[time]</td></tr>"
            . "<tr><th>{$location}</th><td>[location]</td></tr>"
            . '</table>';

        $hostTable = '<table>'
            . "<tr><th>{$appointment}</th><td>[title]</td></tr>"
            . "<tr><th>{$date}</th><td>[date]</td></tr>"
            . "<tr><th>{$time}</th><td>[time]</td></tr>"
            . "<tr><th>{$location}</th><td>[location]</td></tr>"
            . "<tr><th>{$bookedBy}</th><td>[name] ([email])</td></tr>"
            . "<tr><th>{$message}</th><td>[message]</td></tr>"
            . '</table>';

        $reminderHostTable = '<table>'
            . "<tr><th>{$appointment}</th><td>[title]</td></tr>"
            . "<tr><th>{$date}</th><td>[date]</td></tr>"
            . "<tr><th>{$time}</th><td>[time]</td></tr>"
            . "<tr><th>{$location}</th><td>[location]</td></tr>"
            . "<tr><th>{$bookedBy}</th><td>[name] ([email])</td></tr>"
            . '</table>';

        $defaults = [
            'booking_pending' => [
                'subject'   => __('Confirm appointment request: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n\n%s: [confirmation_link]\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Please confirm your appointment request:', 'rrze-appointment'),
                    $appointment, $date, $time, $location,
                    __('Confirm', 'rrze-appointment'),
                    __('Cancel', 'rrze-appointment'),
                    $legal
                ),
                'body_html'  =>
                    '<p>' . __('Please confirm your appointment request:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p><a href="[confirmation_link]">' . $confirm . '</a>'
                    . ' &nbsp;|&nbsp; <a href="[cancel_link]">' . $cancelReq . '</a></p>'
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
            'booking_booker' => [
                'subject'   => __('Booking confirmation: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Your appointment has been confirmed:', 'rrze-appointment'),
                    $appointment, $date, $time, $location, $cancel, $legal
                ),
                'body_html'  =>
                    '<p>' . __('Your appointment has been confirmed:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p><a href="[cancel_link]">' . $cancel . '</a></p>'
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
            'booking_host' => [
                'subject'   => __('New booking: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n%s: [name] ([email])\n%s: [message]\n\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('New booking received:', 'rrze-appointment'),
                    $appointment, $date, $time, $location, $bookedBy, $message, $cancel, $legal
                ),
                'body_html'  =>
                    '<p>' . __('New booking received:', 'rrze-appointment') . '</p>'
                    . $hostTable
                    . '<p><a href="[cancel_link]">' . $cancel . '</a></p>'
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
            'reminder_admin' => [
                'subject'   => __('Reminder: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n%s: [name] ([email])\n\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Reminder for the following appointment:', 'rrze-appointment'),
                    $appointment, $date, $time, $location, $bookedBy, $cancel, $legal
                ),
                'body_html'  =>
                    '<p>' . __('Reminder for the following appointment:', 'rrze-appointment') . '</p>'
                    . $reminderHostTable
                    . '<p><a href="[cancel_link]">' . $cancel . '</a></p>'
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
            'reminder_booker' => [
                'subject'   => __('Reminder: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Reminder for your appointment:', 'rrze-appointment'),
                    $appointment, $date, $time, $location, $cancel, $legal
                ),
                'body_html'  =>
                    '<p>' . __('Reminder for your appointment:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p><a href="[cancel_link]">' . $cancel . '</a></p>'
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
            'cancellation' => [
                'subject'   => __('Cancellation: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Your appointment has been cancelled:', 'rrze-appointment'),
                    $appointment, $date, $time, $location, $legal
                ),
                'body_html'  =>
                    '<p>' . __('Your appointment has been cancelled:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p><a href="[imprint_link]">' . $legal . '</a></p>',
            ],
        ];

        return $defaults[$type] ?? ['subject' => '', 'body' => '', 'body_html' => ''];
    }

    public static function getAll(): array
    {
        try {
            $posts = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => ['publish', 'draft'],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            return array_map(fn(\WP_Post $p) => [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'status' => $p->post_status,
            ], $posts);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    /**
     * Nur veröffentlichte Vorlagen — für den Block-Editor (REST).
     */
    public static function getPublished(): array
    {
        try {
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
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function getTemplateForType(int $postId, string $type): ?array
    {
        try {
            $post = get_post($postId);
            if (!$post || $post->post_type !== self::POST_TYPE) return null;

            return [
                'subject'   => (string) get_post_meta($postId, "tpl_{$type}_subject", true),
                'body'      => (string) get_post_meta($postId, "tpl_{$type}_body", true),
                'body_html' => (string) get_post_meta($postId, "tpl_{$type}_body_html", true),
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function save(array $data, bool $isDraft = false): int|\WP_Error
    {
        try {
            $postId = (int) ($data['id'] ?? 0);
            $title  = sanitize_text_field($data['title'] ?? '');
            $status = $isDraft ? 'draft' : 'publish';

            if ($postId > 0) {
                $result = wp_update_post(['ID' => $postId, 'post_title' => $title, 'post_status' => $status], true);
            } else {
                $result = wp_insert_post(['post_title' => $title, 'post_type' => self::POST_TYPE, 'post_status' => $status], true);
            }

            if (is_wp_error($result)) return $result;

            foreach (['booking_pending', 'booking_booker', 'booking_host', 'reminder_admin', 'reminder_booker', 'cancellation'] as $key) {
                update_post_meta($result, "tpl_{$key}_subject",   sanitize_text_field($data["{$key}_subject"] ?? ''));
                update_post_meta($result, "tpl_{$key}_body",      sanitize_textarea_field($data["{$key}_body"] ?? ''));
                update_post_meta($result, "tpl_{$key}_body_html", wp_kses_post($data["{$key}_body_html"] ?? ''));
            }

            return $result;
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function isInUse(int $postId): array
    {
        try {
            $query = new \WP_Query([
                'post_type'      => 'any',
                'post_status'    => ['publish', 'draft', 'private', 'pending'],
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                's'              => '"tplId":' . $postId,
            ]);

            return array_map(fn(\WP_Post $p) => [
                'id'    => $p->ID,
                'title' => $p->post_title ?: __('(no title)', 'rrze-appointment'),
                'edit'  => get_edit_post_link($p->ID),
            ], $query->posts);
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }

    public static function delete(int $postId): void
    {
        try {
            $post = get_post($postId);
            if ($post && $post->post_type === self::POST_TYPE) {
                wp_delete_post($postId, true);
            }
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage(), $e->getCode(), null);
        }
    }
}
