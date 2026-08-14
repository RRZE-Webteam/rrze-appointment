<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Handles public cancellation and waitlist notification links.
 */
final class CancellationController
{
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
            $token = sanitize_text_field($_GET['rrze_appt_cancel'] ?? '');
            if ($token === '') {
                return;
            }

            $entry = TokenManager::validateCancelToken($token);
            if (!$entry) {
                $this->renderer->renderError(
                    __('This cancellation link is invalid or has already been used.', 'rrze-appointment'),
                    410
                );
            }

            $slot = (string) ($entry['slot'] ?? '');
            $appointmentMeta = $this->getCancellationMeta($entry, $slot);
            $appointmentDetails = $this->renderer->getAppointmentDetails($slot, $appointmentMeta);

            $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $rawAction = wp_unslash($_POST['rrze_appt_cancel_action'] ?? '');
            $action = is_string($rawAction) ? sanitize_key($rawAction) : '';
            if ($requestMethod !== 'POST' || $action !== 'cancel') {
                $this->renderer->renderCancellationConfirmation($token, $appointmentDetails);
            }

            $rawNonce = wp_unslash($_POST['rrze_appt_cancel_nonce'] ?? '');
            $nonce = is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
            if (!wp_verify_nonce($nonce, 'rrze_appointment_cancel_' . $token)) {
                $this->renderer->renderError(
                    __('The form has expired. Please try again.', 'rrze-appointment'),
                    403
                );
            }

            if (($entry['type'] ?? '') === 'pending') {
                TokenManager::deletePending((string) ($entry['pending_token'] ?? ''));
            } else {
                TokenManager::deleteCancelToken($token);
                Bookings::cancel($slot);
            }

            $this->renderer->renderCancellationSuccess($appointmentDetails);
        } catch (CustomException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Disables or re-enables earlier-slot waitlist notifications.
     */
    public function handleWaitlistPreference(): void
    {
        try {
            $rawToken = wp_unslash($_GET['rrze_appt_waitlist_optout'] ?? '');
            $token = is_string($rawToken) ? sanitize_text_field($rawToken) : '';
            if ($token === '') {
                return;
            }

            $slot = TokenManager::validateWaitlistOptOutToken($token);
            if ($slot === null) {
                $this->renderer->renderError(
                    __('This notification opt-out link is invalid or has expired.', 'rrze-appointment'),
                    410
                );
            }

            $allMeta = (array) get_option(Bookings::META_OPTION, []);
            $appointmentMeta = is_array($allMeta[$slot] ?? null) ? $allMeta[$slot] : [];
            $appointmentDetails = $this->renderer->getAppointmentDetails($slot, $appointmentMeta);

            if ($this->isWaitlistOptInRequest()) {
                $this->verifyWaitlistNonce($token);
                if (!Bookings::enableWaitlistNotifications($slot)) {
                    $this->renderInvalidWaitlistLink();
                }
                $this->renderer->renderWaitlistStatus($token, true, $appointmentDetails);
            }

            if (!Bookings::disableWaitlistNotifications($slot)) {
                $this->renderInvalidWaitlistLink();
            }
            $this->renderer->renderWaitlistStatus($token, false, $appointmentDetails);
        } catch (CustomException $exception) {
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

        $allMeta = (array) get_option(Bookings::META_OPTION, []);
        return is_array($allMeta[$slot] ?? null) ? $allMeta[$slot] : [];
    }

    /**
     * Determines whether the request re-enables waitlist notifications.
     */
    private function isWaitlistOptInRequest(): bool
    {
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawAction = wp_unslash($_POST['rrze_appt_waitlist_action'] ?? '');
        $action = is_string($rawAction) ? sanitize_key($rawAction) : '';

        return $requestMethod === 'POST' && $action === 'optin';
    }

    /**
     * Verifies the nonce submitted by the waitlist opt-in form.
     */
    private function verifyWaitlistNonce(string $token): void
    {
        $rawNonce = wp_unslash($_POST['rrze_appt_waitlist_nonce'] ?? '');
        $nonce = is_string($rawNonce) ? sanitize_text_field($rawNonce) : '';
        if (!wp_verify_nonce($nonce, 'rrze_appointment_waitlist_optin_' . $token)) {
            $this->renderer->renderError(
                __('The form has expired. Please try again.', 'rrze-appointment'),
                403
            );
        }
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
