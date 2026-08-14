<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Validates new booking requests and sends confirmation-link emails.
 */
final class BookingRequestController
{
    /**
     * Handles the public AJAX booking request.
     */
    public function handleRequest(): void
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
                $request['slot']
            );
            if (is_wp_error($context)) {
                wp_send_json_error($context->get_error_message());
            }

            [$bookerEmail, $bookerName] = $this->resolveBooker($request, $context);
            $this->validateBooker($bookerEmail, $bookerName, $request['message'], !empty($context['require_message']));

            [$datePart, $startTime, $endTime] = $this->parseSlot($request['slot']);
            $this->assertSlotIsAvailable($request['slot']);

            $meta = $this->buildPendingMeta($request, $context, $bookerEmail, $bookerName);
            $confirmToken = TokenManager::createPending($request['slot'], $meta);
            $this->sendConfirmationRequest(
                $confirmToken,
                $datePart,
                $startTime,
                $endTime,
                $meta
            );

            wp_send_json_success([
                'message' => __('Please confirm your appointment by email.', 'rrze-appointment'),
            ]);
        } catch (CustomException $exception) {
            wp_send_json_error($exception->getMessage());
        }
    }

    /**
     * Sanitizes booking request values from the AJAX payload.
     *
     * @return array{slot: string, postId: int, blockFingerprint: string, email: string, name: string, message: string, waitlist: bool}
     */
    private function getRequestData(): array
    {
        return [
            'slot' => sanitize_text_field($_POST['slot'] ?? ''),
            'postId' => absint($_POST['post_id'] ?? 0),
            'blockFingerprint' => sanitize_text_field($_POST['block_id'] ?? ''),
            'email' => sanitize_email($_POST['booker_email'] ?? ''),
            'name' => sanitize_text_field($_POST['booker_name'] ?? ''),
            'message' => sanitize_textarea_field($_POST['booker_message'] ?? ''),
            'waitlist' => !empty($_POST['booker_waitlist']) && $_POST['booker_waitlist'] === '1',
        ];
    }

    /**
     * Resolves the authoritative booking identity for SSO and non-SSO forms.
     *
     * @param array<string, mixed> $request Sanitized request values.
     * @param array<string, mixed> $context Published appointment block context.
     * @return array{0: string, 1: string}
     */
    private function resolveBooker(array $request, array $context): array
    {
        if (!empty($context['disable_sso'])) {
            return [$request['email'], $request['name']];
        }

        $serverBooker = Rights::get();
        $isAuthenticated = !empty($serverBooker['authenticated']);
        $email = sanitize_email($serverBooker['bookerEmail'] ?? '');
        $serverName = sanitize_text_field($serverBooker['bookerName'] ?? '');
        if (!$isAuthenticated || $email === '') {
            wp_send_json_error(__('No authenticated email address found.', 'rrze-appointment'));
        }

        return [$email, $isAuthenticated ? $serverName : $request['name']];
    }

    /**
     * Validates required booking identity and message fields.
     */
    private function validateBooker(string $email, string $name, string $message, bool $requireMessage): void
    {
        if ($email === '') {
            wp_send_json_error(__('Please provide an email address.', 'rrze-appointment'));
        }
        if ($name === '') {
            wp_send_json_error(__('Please provide your name.', 'rrze-appointment'));
        }
        if ($requireMessage && $message === '') {
            wp_send_json_error(__('Please provide a message.', 'rrze-appointment'));
        }
    }

    /**
     * Parses and validates the canonical slot identifier.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseSlot(string $slot): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');
        if ($datePart === '' || $startTime === '' || $endTime === '') {
            wp_send_json_error(__('Invalid appointment format.', 'rrze-appointment'));
        }

        return [$datePart, $startTime, $endTime];
    }

    /**
     * Rejects slots that are already booked or waiting for confirmation.
     */
    private function assertSlotIsAvailable(string $slot): void
    {
        $booked = (array) get_option('rrze_appointment_booked_slots', []);
        $pending = TokenManager::getPendingSlots();
        if (in_array($slot, $booked, true) || in_array($slot, $pending, true)) {
            wp_send_json_error(__('This appointment is no longer available.', 'rrze-appointment'));
        }
    }

    /**
     * Creates the deliberately narrow metadata stored while confirmation is pending.
     *
     * Question definitions may be stored, but submitted answers are intentionally
     * collected only during confirmation and never persisted.
     *
     * @param array<string, mixed> $request Sanitized request values.
     * @param array<string, mixed> $context Published appointment block context.
     * @return array<string, mixed>
     */
    private function buildPendingMeta(
        array $request,
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
            'booker_message' => $request['message'],
            'booker_waitlist' => $request['waitlist'],
            'waitlist_notified_slots' => [],
            'tpl_id' => $context['tpl_id'],
            'post_link' => $context['post_link'],
            'questions' => $context['questions'],
        ];
    }

    /**
     * Renders and sends the pending-booking confirmation email.
     *
     * @param array<string, mixed> $meta Pending booking metadata.
     */
    private function sendConfirmationRequest(
        string $confirmToken,
        string $datePart,
        string $startTime,
        string $endTime,
        array $meta
    ): void {
        $confirmUrl = TokenManager::confirmUrl($confirmToken);
        $imprintUrl = TokenManager::imprintUrl();
        $variables = [
            '[title]' => $meta['title'],
            '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[time]' => $startTime . ' – ' . $endTime,
            '[location]' => $meta['location'] ?: '–',
            '[person_name]' => $meta['person_name'] ?: '–',
            '[name]' => $meta['booker_name'] ?: '–',
            '[email]' => $meta['booker_email'] ?: '–',
            '[message]' => $meta['booker_message'],
            '[questions]' => '',
            '[confirmation_link]' => $confirmUrl,
            '[cancel_link]' => TokenManager::cancelUrl(TokenManager::createPendingCancelToken($confirmToken)),
            '[imprint_link]' => $imprintUrl,
            '[post_link]' => $meta['post_link'],
        ];

        $templateType = empty($meta['questions']) ? 'booking_pending' : 'booking_pending_questions';
        $templateId = (int) ($meta['tpl_id'] ?? 0);
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, $templateType) ?? [])
            : [];
        $default = MailTemplatePost::getDefault($templateType);
        $subject = Settings::renderTemplate(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plainTemplate = !empty($template['body']) ? $template['body'] : $default['body'];
        $htmlTemplate = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];

        if (strpos($plainTemplate, '[confirmation_link]') === false) {
            $plainTemplate .= "\n\n" . __('Confirmation', 'rrze-appointment') . ': [confirmation_link]';
        }
        if (strpos($plainTemplate, '[imprint_link]') === false) {
            $plainTemplate .= "\n" . __('Imprint', 'rrze-appointment') . ': [imprint_link]';
        }
        if (strpos($htmlTemplate, '[confirmation_link]') === false) {
            $htmlTemplate .= '<p><a href="[confirmation_link]">'
                . __('Confirm appointment now', 'rrze-appointment')
                . '</a></p>';
        }
        if (strpos($htmlTemplate, '[imprint_link]') === false) {
            $htmlTemplate .= '<p><a href="[imprint_link]">'
                . __('Imprint', 'rrze-appointment')
                . '</a></p>';
        }

        Settings::sendMail(
            $meta['booker_email'],
            $subject,
            Settings::renderTemplate($plainTemplate, $variables),
            Settings::renderTemplate($htmlTemplate, $variables),
            [],
            MailTemplate::STATUS_WARNING
        );
    }
}
