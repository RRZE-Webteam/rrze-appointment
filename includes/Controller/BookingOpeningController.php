<?php

namespace RRZE\Appointment\Controller;

use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Presentation\PublicPageRenderer;
use RRZE\Appointment\Rights;

defined('ABSPATH') || exit;

/**
 * Handles public subscriptions and secure links for advance-booking openings.
 */
final class BookingOpeningController
{
    private const BOOKING_NONCE_ACTION = 'rrze_appointment_book';
    private const BOOKING_NONCE_FIELD = 'nonce';
    private const CLAIM_QUERY_KEY = 'rrze_appt_opening';
    private const STATUS_QUERY_KEY = 'rrze_appt_opening_registered';

    private PublicPageRenderer $renderer;

    /**
     * @param PublicPageRenderer $renderer Renderer for public status and error pages.
     */
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
            check_ajax_referer(self::BOOKING_NONCE_ACTION, self::BOOKING_NONCE_FIELD);

            $request = $this->getRequestData();
            $context = $this->resolveSubscriptionContext($request);

            [$bookerEmail, $bookerName] = $this->resolveBooker($request, $context);
            $this->validateBooker($bookerEmail, $bookerName);
            $this->assertSlotIsAvailable($request['slot']);

            $meta = $this->buildSubscriptionMeta($context, $bookerEmail, $bookerName);
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
        } catch (AppointmentException $exception) {
            wp_send_json_error($exception->getMessage());
        }
    }

    /**
     * Claims an opening email link and forwards into regular confirmation.
     */
    public function handleClaim(): void
    {
        try {
            $token = $this->getQueryToken(self::CLAIM_QUERY_KEY);
            if ($token === '') {
                return;
            }

            $result = BookingOpeningNotifier::claim($token);
            if (is_wp_error($result)) {
                $this->renderer->renderError($result->get_error_message(), 410);
            }

            wp_safe_redirect($result);
            exit;
        } catch (AppointmentException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Shows the public success page after a notification registration.
     */
    public function handleRegistrationStatus(): void
    {
        try {
            $token = $this->getQueryToken(self::STATUS_QUERY_KEY);
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
        } catch (AppointmentException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Reads and sanitizes the public subscription request.
     *
     * @return array{slot: string, postId: int, blockFingerprint: string, email: string, name: string}
     */
    private function getRequestData(): array
    {
        return [
            'slot' => sanitize_text_field($this->getPostValue('slot')),
            'postId' => absint($this->getPostValue('post_id')),
            'blockFingerprint' => sanitize_text_field($this->getPostValue('block_id')),
            'email' => sanitize_email($this->getPostValue('booker_email')),
            'name' => sanitize_text_field($this->getPostValue('booker_name')),
        ];
    }

    /**
     * Resolves and validates a published appointment for opening notification.
     *
     * @param array{slot: string, postId: int, blockFingerprint: string, email: string, name: string} $request
     * @return array<string, mixed>
     */
    private function resolveSubscriptionContext(array $request): array
    {
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

        return $context;
    }

    /**
     * @param array<string, mixed> $request Sanitized request data.
     * @param array<string, mixed> $context Published appointment context.
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

    /**
     * Validates the required subscriber identity fields.
     */
    private function validateBooker(string $email, string $name): void
    {
        if ($email === '') {
            wp_send_json_error(__('Please provide an email address.', 'rrze-appointment'));
        }
        if ($name === '') {
            wp_send_json_error(__('Please provide your name.', 'rrze-appointment'));
        }
    }

    /**
     * Rejects slots that have already been booked.
     */
    private function assertSlotIsAvailable(string $slot): void
    {
        if (in_array($slot, (array) get_option(Bookings::SLOTS_OPTION, []), true)) {
            wp_send_json_error(__('This appointment is no longer available.', 'rrze-appointment'));
        }
    }

    /**
     * Builds the narrow metadata persisted with an opening subscription.
     *
     * @param array<string, mixed> $context Published appointment context.
     * @return array<string, mixed>
     */
    private function buildSubscriptionMeta(
        array $context,
        string $bookerEmail,
        string $bookerName
    ): array {
        return [
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
    }

    /**
     * Reads one scalar POST value and removes WordPress request slashes.
     */
    private function getPostValue(string $key): string
    {
        $value = wp_unslash($_POST[$key] ?? '');

        return is_string($value) ? $value : '';
    }

    /**
     * Reads and sanitizes a token from the query string.
     */
    private function getQueryToken(string $key): string
    {
        $value = wp_unslash($_GET[$key] ?? '');

        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
