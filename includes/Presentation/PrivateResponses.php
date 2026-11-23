<?php

namespace RRZE\Appointment\Presentation;

defined('ABSPATH') || exit;

/** Prevents personal responses and action-link URLs from becoming shared cache entries. */
final class PrivateResponses
{
    private const MONTH_PARAMETER = '/^rrze_appt_month_([0-9]+)_[a-f0-9]{64}_[0-9]+$/D';
    private const TOKEN_PARAMETERS = [
        'rrze_appt_confirm', 'rrze_appt_cancel', 'rrze_appt_waitlist_optout',
        'rrze_appt_opening', 'rrze_appt_opening_registered',
    ];
    private const AJAX_ACTIONS = [
        'rrze_appointment_book', 'rrze_appointment_notify_opening', 'rrze_appointment_get_booker', 'rrze_appointment_get_month',
    ];

    /** Registers cache protection and preserves calendar navigation through the page password form. */
    public static function register(): void
    {
        add_action('init', [self::class, 'protectRequest'], 0);
        add_filter('rest_post_dispatch', [self::class, 'protectRestResponse'], 10, 3);
        add_filter('the_password_form', [self::class, 'preserveCalendarMonths'], 10, 2);
    }

    /**
     * Returns one policy for successful and failed personal responses.
     *
     * @return array<string, string> HTTP response headers.
     */
    public static function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ];
    }

    /** Marks token, linked calendar and personal AJAX responses before accessing booking data. */
    public static function protectRequest(): void
    {
        $parameters = array_keys($_GET);
        $private = array_intersect(self::TOKEN_PARAMETERS, $parameters)
            || preg_grep(self::MONTH_PARAMETER, $parameters);
        $page = $_GET['page'] ?? '';
        $private = $private || (is_string($page) && in_array($page, [
            'rrze-appointment-bookings', 'rrze-appointment-settings',
        ], true));
        $action = $_REQUEST['action'] ?? '';
        $private = $private || $action === 'rrze_appointment_backend_sso' || $action === 'rrze_appointment_access';
        $private = $private || (wp_doing_ajax() && is_string($action) && in_array($action, self::AJAX_ACTIONS, true));
        if (!$private) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
            foreach (self::headers() as $name => $value) {
                header($name . ': ' . $value);
            }
        }
    }

    /**
     * Returns the visitor to the chosen calendar month after entering the page password.
     *
     * @param string $form WordPress password form HTML.
     * @param \WP_Post|null $post Post whose content the password protects.
     * @return string Form with this post's month selections on the default redirect.
     */
    public static function preserveCalendarMonths(string $form, ?\WP_Post $post = null): string
    {
        if (!$post || !$_GET) {
            return $form;
        }
        $months = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && preg_match(self::MONTH_PARAMETER, $key, $matches)
                && (int) $matches[1] === (int) $post->ID
                && is_string($value) && preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $value)) {
                $months[$key] = $value;
            }
        }
        if (!$months) {
            return $form;
        }
        $permalink = get_permalink($post);
        $html = new \WP_HTML_Tag_Processor($form);
        while ($html->next_tag('INPUT')) {
            if ($html->get_attribute('name') === 'redirect_to' && $html->get_attribute('type') === 'hidden'
                && $html->get_attribute('value') === $permalink) {
                // Calendar links put the active block last so its fragment can be restored after the POST.
                $html->set_attribute('value', add_query_arg($months, $permalink) . '#' . array_key_last($months));
                break;
            }
        }
        return $html->get_updated_html();
    }

    /**
     * Applies privacy headers after WordPress converts REST errors to responses.
     *
     * @param \WP_REST_Response $response Includes authentication and permission errors.
     * @param \WP_REST_Server $server Current REST server.
     * @param \WP_REST_Request $request Request routed by WordPress.
     * @return \WP_REST_Response The same response with its status and data preserved.
     */
    public static function protectRestResponse(#[\SensitiveParameter] $response, $server, #[\SensitiveParameter] $request)
    {
        // WordPress matches REST routes case-insensitively.
        if (in_array(rtrim(strtolower($request->get_route()), '/'), [
            '/rrze/v2/appointment/booker', '/rrze/v2/appointment/bookings', '/rrze/v2/appointment/bookings/cancel',
        ], true)) {
            foreach (self::headers() as $name => $value) {
                $response->header($name, $value);
            }
        }
        return $response;
    }
}
