<?php

namespace RRZE\Appointment\Presentation;

defined('ABSPATH') || exit;

/** Prevents personal responses and action-link URLs from becoming shared cache entries. */
final class PrivateResponses
{
    private const TOKEN_PARAMETERS = [
        'rrze_appt_confirm', 'rrze_appt_cancel', 'rrze_appt_waitlist_optout',
        'rrze_appt_opening', 'rrze_appt_opening_registered',
    ];
    private const AJAX_ACTIONS = [
        'rrze_appointment_book', 'rrze_appointment_notify_opening', 'rrze_appointment_get_booker',
    ];

    /** Registers early protection, including permission, validation and storage failures. */
    public static function register(): void
    {
        add_action('init', [self::class, 'protectRequest'], 0);
        add_filter('rest_post_dispatch', [self::class, 'protectRestResponse'], 10, 3);
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

    /** Marks token pages and personal AJAX requests before any booking access or output. */
    public static function protectRequest(): void
    {
        $private = count(array_intersect(self::TOKEN_PARAMETERS, array_keys($_GET))) > 0;
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
