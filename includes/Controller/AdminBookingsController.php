<?php

namespace RRZE\Appointment\Controller;

use RRZE\Appointment\Admin\AppointmentPermissions;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Configuration\PluginSettings;

defined('ABSPATH') || exit;

/** Authenticated data and actions for the appointment management screen. */
final class AdminBookingsController
{
    public const REST_NAMESPACE = 'rrze/v2/appointment';
    public const ROUTE = '/bookings';

    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'getBookings'],
            'permission_callback' => [$this, 'allowRequest'],
            'args' => [
                'view' => ['type' => 'string', 'enum' => ['current', 'past'], 'default' => 'current'],
            ],
        ]);
        register_rest_route(self::REST_NAMESPACE, self::ROUTE . '/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancelBooking'],
            'permission_callback' => [$this, 'allowRequest'],
            'args' => [
                'slot' => ['type' => 'string', 'required' => true],
                'reason' => [
                    'type' => 'string',
                    'default' => '',
                    'maxLength' => Bookings::MAX_CANCELLATION_REASON_LENGTH,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
    }

    /** Requires the same site-specific permission as the page and a REST nonce. */
    public function allowRequest(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!AppointmentPermissions::currentUserCanManage()) {
            return new \WP_Error(
                'rrze_appointment_forbidden',
                __('You do not have permission to manage appointments.', 'rrze-appointment'),
                ['status' => 403]
            );
        }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new \WP_Error(
                'rrze_appointment_invalid_nonce',
                __('Your session has expired. Reload the page and try again.', 'rrze-appointment'),
                ['status' => 403]
            );
        }
        return true;
    }

    public function getBookings(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $permission = $this->allowRequest($request);
        if (is_wp_error($permission)) {
            return $permission;
        }

        try {
            $hasPastView = (int) PluginSettings::get('retention_days') > 0;
            $view = $hasPastView && $request->get_param('view') === 'past' ? 'past' : 'current';
            $items = array_map(static function (array $booking): array {
                // Do not expose template IDs, tokens or unmasked stored metadata.
                return [
                    'id' => $booking['slot'],
                    'date' => $booking['date'],
                    'dateLabel' => date_i18n(get_option('date_format'), strtotime($booking['date'])),
                    'time' => $booking['time'],
                    'title' => (string) $booking['title'],
                    'personName' => (string) $booking['person_name'],
                    'bookerName' => (string) $booking['booker_name'],
                    'bookerEmail' => (string) $booking['booker_email'],
                    'anonymized' => $booking['admin_anonymized'],
                ];
            }, Bookings::getAll(['view' => $view]));

            return $this->response([
                'items' => $items,
                'view' => $view,
                'hasPastView' => $hasPastView,
                'sensitiveMode' => (bool) PluginSettings::get('sensitive_mode_enabled'),
                'cancellationReasonEnabled' => (bool) PluginSettings::get('cancellation_reason_enabled'),
            ]);
        } catch (AppointmentException $exception) {
            return new \WP_Error(
                'rrze_appointment_load_failed',
                __('Appointments could not be loaded. Please try again.', 'rrze-appointment'),
                ['status' => 500]
            );
        }
    }

    public function cancelBooking(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $permission = $this->allowRequest($request);
        if (is_wp_error($permission)) {
            return $permission;
        }

        try {
            $slot = $request->get_param('slot');
            if (!is_string($slot) || !in_array($slot, array_column(Bookings::getAll(), 'slot'), true)) {
                return new \WP_Error(
                    'rrze_appointment_not_cancellable',
                    __('This appointment has ended or is no longer booked. Refresh the list.', 'rrze-appointment'),
                    ['status' => 409]
                );
            }
            $reason = (bool) PluginSettings::get('cancellation_reason_enabled')
                ? sanitize_textarea_field((string) $request->get_param('reason'))
                : '';
            if (!Bookings::cancel($slot, $reason)) {
                return new \WP_Error(
                    'rrze_appointment_not_found',
                    __('This appointment is no longer booked. Refresh the list.', 'rrze-appointment'),
                    ['status' => 409]
                );
            }
            return $this->response([
                'message' => __('Booking cancelled and cancellation emails sent.', 'rrze-appointment'),
            ]);
        } catch (AppointmentException $exception) {
            return new \WP_Error(
                'rrze_appointment_cancel_failed',
                __('The booking could not be cancelled. Refresh the list and try again.', 'rrze-appointment'),
                ['status' => 500]
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data): \WP_REST_Response
    {
        return new \WP_REST_Response($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
