<?php

namespace RRZE\Appointment\Mail;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Registers and persists reusable appointment email templates.
 */
final class MailTemplatePost
{
    public const POST_TYPE = 'rrze_appt_mail_tpl';

    private const EDITABLE_DEFAULT_META_KEY = '_rrze_appt_editable_default_template';
    private const EDITABLE_DEFAULT_CREATED_OPTION = 'rrze_appt_editable_default_template_created';
    private const TEMPLATE_FIELDS = ['subject', 'body', 'body_html'];
    private const TEMPLATE_TYPES = [
        'booking_pending',
        'booking_pending_questions',
        'booking_opening_notification',
        'booking_booker',
        'booking_host',
        'reminder_admin',
        'reminder_booker',
        'cancellation',
        'waitlist_earlier_slot',
    ];

    /**
     * Registers the private post type and its REST-visible template fields.
     *
     * @throws CustomException When WordPress registration fails.
     */
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

            foreach (self::TEMPLATE_TYPES as $type) {
                foreach (self::TEMPLATE_FIELDS as $field) {
                    register_post_meta(self::POST_TYPE, "tpl_{$type}_{$field}", [
                        'show_in_rest'  => true,
                        'single'        => true,
                        'type'          => 'string',
                        'auth_callback' => $field === 'body_html'
                            ? static fn (): bool => current_user_can('edit_posts') && self::canEditHtml()
                            : static fn (): bool => current_user_can('edit_posts'),
                    ]);
                }
            }
        } catch (CustomException $exception) {
            return;
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * HTML templates are restricted to network super admins on multisite.
     */
    public static function canEditHtml(): bool
    {
        return !is_multisite() || is_super_admin();
    }

    /**
     * Returns the bundled default for a supported template type.
     *
     * @return array{subject: string, body: string, body_html: string}
     */
    public static function getDefault(string $type): array
    {
        if (!self::isSupportedType($type)) {
            return ['subject' => '', 'body' => '', 'body_html' => ''];
        }

        $appointment = __('Appointment', 'rrze-appointment');
        $date        = __('Date', 'rrze-appointment');
        $time        = __('Time', 'rrze-appointment');
        $location    = __('Location', 'rrze-appointment');
        $bookedBy    = __('Booked by', 'rrze-appointment');
        $confirm     = __('Confirm appointment', 'rrze-appointment');
        $confirmQuestions = __('Confirm appointment and answer questions', 'rrze-appointment');
        $bookNow      = __('Book appointment now', 'rrze-appointment');
        $cancelReq   = __('Cancel request', 'rrze-appointment');
        $cancel      = __('Cancel appointment', 'rrze-appointment');
        $legal       = __('Legal notice', 'rrze-appointment');

        $baseTable = MailTemplate::detailsTable([
            $appointment => '[title]',
            $date => '[date]',
            $time => '[time]',
            $location => '[location]',
        ]);

        $hostTable = MailTemplate::detailsTable([
            $appointment => '[title]',
            $date => '[date]',
            $time => '[time]',
            $location => '[location]',
            $bookedBy => '[name] ([email])',
        ]);

        $reminderHostTable = MailTemplate::detailsTable([
            $appointment => '[title]',
            $date => '[date]',
            $time => '[time]',
            $location => '[location]',
            $bookedBy => '[name] ([email])',
        ]);

        $legalLink = '<p style="margin:24px 0 0;font-size:13px;line-height:20px;">'
            . '<a href="[imprint_link]" target="_blank" style="color:#04316a;text-decoration:underline;">'
            . $legal
            . '</a></p>';

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
                    . MailTemplate::actionButton('[confirmation_link]', $confirm)
                    . '<p style="margin:0;"><a href="[cancel_link]">' . $cancelReq . '</a></p>'
                    . $legalLink,
            ],
            'booking_pending_questions' => [
                'subject'   => __('Confirm appointment and answer questions: [title] on [date]', 'rrze-appointment'),
                'body'      => sprintf(
                    __("%s\n\n%s: [title]\n%s: [date]\n%s: [time]\n%s: [location]\n\n%s\n\n%s: [confirmation_link]\n%s: [cancel_link]\n\n%s: [imprint_link]", 'rrze-appointment'),
                    __('Please confirm your appointment request and answer the remaining questions:', 'rrze-appointment'),
                    $appointment, $date, $time, $location,
                    __('The appointment is booked only after you submit the confirmation form.', 'rrze-appointment'),
                    __('Continue', 'rrze-appointment'),
                    __('Cancel', 'rrze-appointment'),
                    $legal
                ),
                'body_html'  =>
                    '<p>' . __('Please confirm your appointment request and answer the remaining questions:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p>' . __('The appointment is booked only after you submit the confirmation form.', 'rrze-appointment') . '</p>'
                    . MailTemplate::actionButton('[confirmation_link]', $confirmQuestions)
                    . '<p style="margin:0;"><a href="[cancel_link]">' . $cancelReq . '</a></p>'
                    . $legalLink,
            ],
            'booking_opening_notification' => [
                'subject' => __('Booking is now open: [title] on [date]', 'rrze-appointment'),
                'body' =>
                    sprintf(__('Hello %s,', 'rrze-appointment'), '[name]')
                    . "\n\n"
                    . __('The appointment you asked about is now open for booking.', 'rrze-appointment')
                    . "\n\n"
                    . "{$appointment}: [title]\n"
                    . "{$date}: [date]\n"
                    . "{$time}: [time]\n"
                    . "{$location}: [location]\n\n"
                    . __('The appointment is not reserved until you complete the booking.', 'rrze-appointment')
                    . "\n\n{$bookNow}: [booking_link]"
                    . "\n\n{$legal}: [imprint_link]",
                'body_html' =>
                    '<p>' . sprintf(__('Hello %s,', 'rrze-appointment'), '[name]') . '</p>'
                    . '<p>' . __('The appointment you asked about is now open for booking.', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p>' . __('The appointment is not reserved until you complete the booking.', 'rrze-appointment') . '</p>'
                    . MailTemplate::actionButton('[booking_link]', $bookNow)
                    . $legalLink,
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
                    . MailTemplate::actionButton('[cancel_link]', $cancel)
                    . $legalLink,
            ],
            'booking_host' => [
                'subject'   => __('New booking: [title] on [date]', 'rrze-appointment'),
                'body'      =>
                    __('New booking received:', 'rrze-appointment') . "\n\n"
                    . "{$appointment}: [title]\n"
                    . "{$date}: [date]\n"
                    . "{$time}: [time]\n"
                    . "{$location}: [location]\n"
                    . "{$bookedBy}: [name] ([email])\n"
                    . "[questions]\n\n"
                    . "{$cancel}: [cancel_link]\n\n"
                    . "{$legal}: [imprint_link]",
                'body_html'  =>
                    '<p>' . __('New booking received:', 'rrze-appointment') . '</p>'
                    . $hostTable
                    . '[questions]'
                    . MailTemplate::actionButton('[cancel_link]', $cancel)
                    . $legalLink,
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
                    . MailTemplate::actionButton('[cancel_link]', $cancel)
                    . $legalLink,
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
                    . MailTemplate::actionButton('[cancel_link]', $cancel)
                    . $legalLink,
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
                    . $legalLink,
            ],
            'waitlist_earlier_slot' => [
                'subject'   => __('Earlier appointment available', 'rrze-appointment'),
                'body'      =>
                    sprintf(__('Hello %s,', 'rrze-appointment'), '[name]')
                    . "\n\n"
                    . __('An earlier appointment has become available:', 'rrze-appointment')
                    . "\n\n"
                    . "{$appointment}: [title]\n"
                    . "{$date}: [date]\n"
                    . "{$time}: [time]\n"
                    . "{$location}: [location]\n"
                    . __('Host', 'rrze-appointment') . ": [person_name]\n\n"
                    . sprintf(__('Your current appointment is on %s at %s.', 'rrze-appointment'), '[current_date]', '[current_time]')
                    . "\n\n"
                    . __('Please book the earlier slot directly on the website.', 'rrze-appointment') . ': [post_link]'
                    . "\n\n"
                    . __('Stop earlier appointment notifications', 'rrze-appointment') . ': [waitlist_optout_link]'
                    . "\n\n{$legal}: [imprint_link]",
                'body_html' =>
                    '<p>' . sprintf(__('Hello %s,', 'rrze-appointment'), '[name]') . '</p>'
                    . '<p>' . __('An earlier appointment has become available:', 'rrze-appointment') . '</p>'
                    . $baseTable
                    . '<p><strong>' . __('Host', 'rrze-appointment') . ':</strong> [person_name]</p>'
                    . '<p>' . sprintf(__('Your current appointment is on %s at %s.', 'rrze-appointment'), '[current_date]', '[current_time]') . '</p>'
                    . '<p>' . __('Please book the earlier slot directly on the website.', 'rrze-appointment') . '</p>'
                    . MailTemplate::actionButton('[post_link]', __('View available appointments', 'rrze-appointment'))
                    . '<p><a href="[waitlist_optout_link]">'
                    . __('Stop earlier appointment notifications', 'rrze-appointment')
                    . '</a></p>'
                    . $legalLink,
            ],
        ];

        return $defaults[$type];
    }

    /**
     * Returns published and draft templates for the settings screen.
     *
     * @return array<int, array{id: int, title: string, status: string}>
     */
    public static function getAll(): array
    {
        try {
            return array_map(static fn (\WP_Post $post): array => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
            ], self::getTemplatePosts(['publish', 'draft']));
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns published templates for editor-facing selectors.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public static function getPublished(): array
    {
        try {
            return array_map(static fn (\WP_Post $post): array => [
                'id' => $post->ID,
                'title' => $post->post_title,
            ], self::getTemplatePosts('publish'));
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Returns the stored fields for one supported template type.
     *
     * @return array{subject: string, body: string, body_html: string}|null
     */
    public static function getTemplateForType(int $postId, string $type): ?array
    {
        try {
            $post = get_post($postId);
            if (!self::isTemplatePost($post) || !self::isSupportedType($type)) {
                return null;
            }

            return [
                'subject'   => (string) get_post_meta($postId, "tpl_{$type}_subject", true),
                'body'      => (string) get_post_meta($postId, "tpl_{$type}_body", true),
                'body_html' => (string) get_post_meta($postId, "tpl_{$type}_body_html", true),
            ];
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Creates or updates an email template.
     *
     * HTML is changed only when the caller explicitly confirms permission.
     *
     * @param array<string, mixed> $data Submitted template values.
     * @return int|\WP_Error Saved template ID or WordPress error.
     */
    public static function save(array $data, bool $isDraft = false, bool $canEditHtml = false): int|\WP_Error
    {
        try {
            $postId = (int) ($data['id'] ?? 0);
            if ($postId > 0 && !self::isTemplatePost(get_post($postId))) {
                return new \WP_Error(
                    'rrze_appointment_invalid_mail_template',
                    __('The selected mail template is invalid.', 'rrze-appointment')
                );
            }

            $result = self::saveTemplatePost(
                $postId,
                self::getInputValue($data, 'title', 'text'),
                $isDraft ? 'draft' : 'publish'
            );
            if (is_wp_error($result)) {
                return $result;
            }

            self::saveTemplateMeta($result, $data, $canEditHtml);

            return $result;
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Queries templates in the requested post statuses.
     *
     * @param string|array<int, string> $postStatus WordPress post status query.
     * @return array<int, \WP_Post>
     */
    private static function getTemplatePosts(string|array $postStatus): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => $postStatus,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        return is_array($posts) ? $posts : [];
    }

    /**
     * Creates or updates the template post record.
     *
     * @return int|\WP_Error
     */
    private static function saveTemplatePost(int $postId, string $title, string $status): int|\WP_Error
    {
        if ($postId > 0) {
            return wp_update_post([
                'ID' => $postId,
                'post_title' => $title,
                'post_status' => $status,
            ], true);
        }

        return wp_insert_post([
            'post_title' => $title,
            'post_type' => self::POST_TYPE,
            'post_status' => $status,
        ], true);
    }

    /**
     * Persists all supported fields for a template post.
     *
     * @param array<string, mixed> $data Submitted template values.
     */
    private static function saveTemplateMeta(int $postId, array $data, bool $canEditHtml): void
    {
        foreach (self::TEMPLATE_TYPES as $type) {
            update_post_meta(
                $postId,
                "tpl_{$type}_subject",
                self::getInputValue($data, "{$type}_subject", 'text')
            );
            update_post_meta(
                $postId,
                "tpl_{$type}_body",
                self::getInputValue($data, "{$type}_body", 'textarea')
            );
            if ($canEditHtml) {
                update_post_meta(
                    $postId,
                    "tpl_{$type}_body_html",
                    wp_kses_post(self::getInputValue($data, "{$type}_body_html", 'raw'))
                );
            }
        }
    }

    /**
     * Reads a scalar submitted value and applies the requested sanitization.
     *
     * @param array<string, mixed>   $data   Submitted template values.
     * @param 'raw'|'text'|'textarea' $format Sanitization format.
     */
    private static function getInputValue(array $data, string $key, string $format): string
    {
        $value = wp_unslash($data[$key] ?? '');
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) $value;
        if ($format === 'text') {
            return sanitize_text_field($value);
        }
        if ($format === 'textarea') {
            return sanitize_textarea_field($value);
        }

        return $value;
    }

    /**
     * Determines whether a value is a mail-template post.
     *
     * @param mixed $post Candidate WordPress post.
     */
    private static function isTemplatePost($post): bool
    {
        return $post instanceof \WP_Post && $post->post_type === self::POST_TYPE;
    }

    /**
     * Determines whether a template type is registered by this plugin.
     */
    private static function isSupportedType(string $type): bool
    {
        return in_array($type, self::TEMPLATE_TYPES, true);
    }

    /**
     * Returns posts whose block content references the supplied template.
     *
     * @return array<int, array{id: int, title: string, edit: string|null}>
     */
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

            return array_map(static fn (\WP_Post $post): array => [
                'id' => $post->ID,
                'title' => $post->post_title ?: __('(no title)', 'rrze-appointment'),
                'edit' => get_edit_post_link($post->ID),
            ], $query->posts);
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Permanently deletes a mail-template post.
     */
    public static function delete(int $postId): void
    {
        try {
            $post = get_post($postId);
            if (self::isTemplatePost($post)) {
                wp_delete_post($postId, true);
            }
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Creates the editable copy of the bundled defaults once per site.
     */
    public static function ensureEditableDefaultTemplateExists(): void
    {
        try {
            $alreadyInitialized = (bool) get_option(self::EDITABLE_DEFAULT_CREATED_OPTION, false);
            if ($alreadyInitialized) {
                return;
            }

            $existing = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => ['publish', 'draft', 'private', 'pending'],
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'meta_key'       => self::EDITABLE_DEFAULT_META_KEY,
                'meta_value'     => '1',
            ]);

            if (!empty($existing)) {
                update_option(self::EDITABLE_DEFAULT_CREATED_OPTION, 1, false);
                return;
            }

            $data = [
                'id'    => 0,
                'title' => __('Standard template (editable)', 'rrze-appointment'),
            ];

            foreach (self::TEMPLATE_TYPES as $type) {
                $default = self::getDefault($type);
                $data["{$type}_subject"]   = $default['subject'] ?? '';
                $data["{$type}_body"]      = $default['body'] ?? '';
                $data["{$type}_body_html"] = $default['body_html'] ?? '';
            }

            $newId = self::save($data, false, true);
            if (!is_wp_error($newId)) {
                update_post_meta($newId, self::EDITABLE_DEFAULT_META_KEY, '1');
                update_option(self::EDITABLE_DEFAULT_CREATED_OPTION, 1, false);
            }
        } catch (\Exception $exception) {
            throw self::createException($exception);
        }
    }

    /**
     * Converts infrastructure errors to the plugin's shared exception type.
     */
    private static function createException(\Exception $exception): CustomException
    {
        if ($exception instanceof CustomException) {
            return $exception;
        }

        return new CustomException($exception->getMessage(), (int) $exception->getCode(), null);
    }
}
