<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Handles public subscriptions and secure links for advance-booking openings.
 */
final class BookingOpeningController
{
    private PublicPageRenderer $renderer;

    public function __construct(PublicPageRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Registers a visitor for an appointment whose booking window is not open.
     */
    public function handleSubscription(): void
    {
        try {
            check_ajax_referer('rrze_appointment_book', 'nonce');

            $request = $this->getRequestData();
            if ($request['slot'] === '') {
                wp_send_json_error(__('No appointment specified.', 'rrze-appointment'));
            }

            $context = AppointmentBlock::resolvePublished(
                $request['postId'],
                $request['blockFingerprint'],
                $request['slot'],
                true
            );
            if (is_wp_error($context)) {
                wp_send_json_error($context->get_error_message());
            }
            if (empty($context['booking_not_open'])) {
                wp_send_json_error(
                    __('This appointment is already open for booking.', 'rrze-appointment')
                );
            }
            if ((int) $context['booking_opens_at'] >= (int) $context['booking_closes_at']) {
                wp_send_json_error(
                    __('This appointment has no valid booking period.', 'rrze-appointment')
                );
            }

            [$bookerEmail, $bookerName] = $this->resolveBooker($request, $context);
            if ($bookerEmail === '') {
                wp_send_json_error(__('Please provide an email address.', 'rrze-appointment'));
            }
            if ($bookerName === '') {
                wp_send_json_error(__('Please provide your name.', 'rrze-appointment'));
            }

            if (in_array(
                $request['slot'],
                (array) get_option(Bookings::SLOTS_OPTION, []),
                true
            )) {
                wp_send_json_error(__('This appointment is no longer available.', 'rrze-appointment'));
            }

            $meta = [
                'title' => $context['title'],
                'location' => $context['location'],
                'person_id' => $context['person_id'],
                'person_name' => $context['person_name'],
                'person_email' => $context['person_email'],
                'booker_email' => $bookerEmail,
                'booker_name' => $bookerName,
                'booker_waitlist' => false,
                'waitlist_notified_slots' => [],
                'tpl_id' => $context['tpl_id'],
                'post_link' => $context['post_link'],
                'questions' => $context['questions'],
            ];
            $subscription = BookingOpeningNotifier::subscribe(
                $request['slot'],
                $meta,
                (int) $context['booking_opens_at'],
                (int) $context['booking_closes_at']
            );

            wp_send_json_success([
                'message' => __('We will email you when this appointment opens for booking.', 'rrze-appointment'),
                'redirectUrl' => BookingOpeningNotifier::registrationUrl(
                    $subscription['statusToken']
                ),
            ]);
        } catch (CustomException $exception) {
            wp_send_json_error($exception->getMessage());
        }
    }

    /**
     * Claims an opening email link and forwards into regular confirmation.
     */
    public function handleClaim(): void
    {
        try {
            $rawToken = wp_unslash($_GET['rrze_appt_opening'] ?? '');
            $token = is_string($rawToken) ? sanitize_text_field($rawToken) : '';
            if ($token === '') {
                return;
            }

            $result = BookingOpeningNotifier::claim($token);
            if (is_wp_error($result)) {
                $this->renderer->renderError($result->get_error_message(), 410);
            }

            wp_safe_redirect($result);
            exit;
        } catch (CustomException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Shows the public success page after a notification registration.
     */
    public function handleRegistrationStatus(): void
    {
        try {
            $rawToken = wp_unslash($_GET['rrze_appt_opening_registered'] ?? '');
            $token = is_string($rawToken) ? sanitize_text_field($rawToken) : '';
            if ($token === '') {
                return;
            }

            $entry = BookingOpeningNotifier::getSubscriptionByStatusToken($token);
            if (!$entry) {
                $this->renderer->renderError(
                    __('This booking notification link is invalid or has expired.', 'rrze-appointment'),
                    410
                );
            }

            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $this->renderer->renderOpeningNotificationSuccess(
                $this->renderer->getAppointmentDetails(
                    (string) ($entry['slot'] ?? ''),
                    $meta
                )
            );
        } catch (CustomException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * @return array{slot: string, postId: int, blockFingerprint: string, email: string, name: string}
     */
    private function getRequestData(): array
    {
        return [
            'slot' => sanitize_text_field($_POST['slot'] ?? ''),
            'postId' => absint($_POST['post_id'] ?? 0),
            'blockFingerprint' => sanitize_text_field($_POST['block_id'] ?? ''),
            'email' => sanitize_email($_POST['booker_email'] ?? ''),
            'name' => sanitize_text_field($_POST['booker_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $context
     * @return array{0: string, 1: string}
     */
    private function resolveBooker(array $request, array $context): array
    {
        if (!empty($context['disable_sso'])) {
            return [$request['email'], $request['name']];
        }

        $serverBooker = Rights::get();
        $email = sanitize_email((string) ($serverBooker['bookerEmail'] ?? ''));
        $name = sanitize_text_field((string) ($serverBooker['bookerName'] ?? ''));
        if (empty($serverBooker['authenticated']) || $email === '') {
            wp_send_json_error(__('No authenticated email address found.', 'rrze-appointment'));
        }

        return [$email, $name];
    }
}
