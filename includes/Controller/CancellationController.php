<?php

namespace RRZE\Appointment\Controller;

use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Presentation\PublicPageRenderer;

defined('ABSPATH') || exit;

/**
 * Handles public cancellation and waitlist notification links.
 */
final class CancellationController
{
    private const CANCELLATION_QUERY_KEY = 'rrze_appt_cancel';
    private const CANCELLATION_ACTION_FIELD = 'rrze_appt_cancel_action';
    private const CANCELLATION_NONCE_FIELD = 'rrze_appt_cancel_nonce';
    private const CANCELLATION_ACTION = 'cancel';
    private const CANCELLATION_NONCE_PREFIX = 'rrze_appointment_cancel_';

    private const WAITLIST_QUERY_KEY = 'rrze_appt_waitlist_optout';
    private const WAITLIST_ACTION_FIELD = 'rrze_appt_waitlist_action';
    private const WAITLIST_NONCE_FIELD = 'rrze_appt_waitlist_nonce';
    private const WAITLIST_OPT_IN_ACTION = 'optin';
    private const WAITLIST_NONCE_PREFIX = 'rrze_appointment_waitlist_optin_';

    private PublicPageRenderer $renderer;

    /**
     * @param PublicPageRenderer $renderer Public appointment page renderer.
     */
    public function __construct(PublicPageRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Shows the cancellation confirmation and processes confirmed cancellations.
     */
    public function handleCancellation(): void
    {
        try {
            $token = $this->getQueryValue(self::CANCELLATION_QUERY_KEY);
            if ($token === '') {
                return;
            }

            $entry = TokenManager::validateCancelToken($token);
            if (!is_array($entry)) {
                $this->renderer->renderError(
                    __('This cancellation link is invalid or has already been used.', 'rrze-appointment'),
                    410
                );
                return;
            }

            $slot = (string) ($entry['slot'] ?? '');
            $appointmentMeta = $this->getCancellationMeta($entry, $slot);
            $appointmentDetails = $this->renderer->getAppointmentDetails($slot, $appointmentMeta);

            if (!$this->isCancellationRequest()) {
                $this->renderer->renderCancellationConfirmation($token, $appointmentDetails);
                return;
            }

            if (!$this->hasValidNonce(
                self::CANCELLATION_NONCE_FIELD,
                self::CANCELLATION_NONCE_PREFIX . $token
            )) {
                $this->renderer->renderError(
                    __('The form has expired. Please try again.', 'rrze-appointment'),
                    403
                );
                return;
            }

            $this->cancelAppointment($entry, $slot, $token);
            $this->renderer->renderCancellationSuccess($appointmentDetails);
        } catch (AppointmentException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Disables or re-enables earlier-slot waitlist notifications.
     */
    public function handleWaitlistPreference(): void
    {
        try {
            $token = $this->getQueryValue(self::WAITLIST_QUERY_KEY);
            if ($token === '') {
                return;
            }

            $slot = TokenManager::validateWaitlistOptOutToken($token);
            if ($slot === null) {
                $this->renderer->renderError(
                    __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
                    410
                );
                return;
            }

            $appointmentMeta = $this->getStoredBookingMeta($slot);
            $appointmentDetails = $this->renderer->getAppointmentDetails($slot, $appointmentMeta);

            if ($this->isWaitlistOptInRequest()) {
                if (!$this->hasValidNonce(
                    self::WAITLIST_NONCE_FIELD,
                    self::WAITLIST_NONCE_PREFIX . $token
                )) {
                    $this->renderer->renderError(
                        __('The form has expired. Please try again.', 'rrze-appointment'),
                        403
                    );
                    return;
                }

                if (!Bookings::enableWaitlistNotifications($slot)) {
                    $this->renderInvalidWaitlistLink();
                    return;
                }
                $this->renderer->renderWaitlistStatus($token, true, $appointmentDetails);
                return;
            }

            if (!Bookings::disableWaitlistNotifications($slot)) {
                $this->renderInvalidWaitlistLink();
                return;
            }
            $this->renderer->renderWaitlistStatus($token, false, $appointmentDetails);
        } catch (AppointmentException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Loads metadata for either a pending or confirmed cancellation.
     *
     * @param array<string, mixed> $entry Validated cancellation token entry.
     * @return array<string, mixed>
     */
    private function getCancellationMeta(array $entry, string $slot): array
    {
        if (($entry['type'] ?? '') === 'pending') {
            $pendingEntry = TokenManager::getPending((string) ($entry['pending_token'] ?? ''));
            return is_array($pendingEntry['meta'] ?? null) ? $pendingEntry['meta'] : [];
        }

        return $this->getStoredBookingMeta($slot);
    }

    /**
     * Removes the appointment state represented by a cancellation token.
     *
     * @param array<string, mixed> $entry Validated cancellation token entry.
     */
    private function cancelAppointment(array $entry, string $slot, string $token): void
    {
        if (($entry['type'] ?? '') === 'pending') {
            TokenManager::deletePending((string) ($entry['pending_token'] ?? ''));
            return;
        }

        TokenManager::deleteCancelToken($token);
        Bookings::cancel($slot);
    }

    /**
     * Loads metadata belonging to a confirmed booking.
     *
     * @return array<string, mixed>
     */
    private function getStoredBookingMeta(string $slot): array
    {
        $allMeta = (array) get_option(Bookings::META_OPTION, []);
        return is_array($allMeta[$slot] ?? null) ? $allMeta[$slot] : [];
    }

    /**
     * Determines whether the request confirms a cancellation.
     */
    private function isCancellationRequest(): bool
    {
        return $this->getRequestMethod() === 'POST'
            && $this->getPostValue(self::CANCELLATION_ACTION_FIELD, 'key') === self::CANCELLATION_ACTION;
    }

    /**
     * Determines whether the request re-enables waitlist notifications.
     */
    private function isWaitlistOptInRequest(): bool
    {
        return $this->getRequestMethod() === 'POST'
            && $this->getPostValue(self::WAITLIST_ACTION_FIELD, 'key') === self::WAITLIST_OPT_IN_ACTION;
    }

    /**
     * Reads and sanitizes a scalar query-string value.
     */
    private function getQueryValue(string $key): string
    {
        $value = wp_unslash($_GET[$key] ?? '');
        return is_string($value) ? sanitize_text_field($value) : '';
    }

    /**
     * Reads and sanitizes a scalar form value.
     *
     * @param 'key'|'text' $format Sanitization format.
     */
    private function getPostValue(string $key, string $format = 'text'): string
    {
        $value = wp_unslash($_POST[$key] ?? '');
        if (!is_string($value)) {
            return '';
        }

        return $format === 'key' ? sanitize_key($value) : sanitize_text_field($value);
    }

    /**
     * Returns the normalized HTTP request method.
     */
    private function getRequestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * Verifies a nonce from a named form field.
     */
    private function hasValidNonce(string $field, string $action): bool
    {
        return (bool) wp_verify_nonce($this->getPostValue($field), $action);
    }

    /**
     * Renders the shared invalid waitlist-link response.
     */
    private function renderInvalidWaitlistLink(): void
    {
        $this->renderer->renderError(
            __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
            410
        );
    }
}
