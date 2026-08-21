<?php

namespace RRZE\Appointment\Controller;

use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Common\CustomException;
use RRZE\Appointment\Mail\MailTemplate;
use RRZE\Appointment\Mail\MailTemplatePost;
use RRZE\Appointment\Rights;
use RRZE\Appointment\Settings;

defined('ABSPATH') || exit;

/**
 * Validates new booking requests and sends confirmation-link emails.
 */
final class BookingRequestController
{
    private const BOOKING_NONCE_ACTION = 'rrze_appointment_book';
    private const BOOKING_NONCE_FIELD = 'nonce';
    private const PENDING_TEMPLATE_TYPE = 'booking_pending';
    private const PENDING_QUESTIONS_TEMPLATE_TYPE = 'booking_pending_questions';

    /**
     * Handles the public AJAX booking request.
     */
    public function handleRequest(): void
    {
        try {
            check_ajax_referer(self::BOOKING_NONCE_ACTION, self::BOOKING_NONCE_FIELD);

            $request = $this->getRequestData();
            $context = $this->resolveBookingContext($request);

            [$bookerEmail, $bookerName] = $this->resolveBooker($request, $context);
            $this->validateBooker($bookerEmail, $bookerName);

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
     * @return array{slot: string, postId: int, blockFingerprint: string, email: string, name: string, waitlist: bool}
     */
    private function getRequestData(): array
    {
        return [
            'slot' => sanitize_text_field($this->getPostValue('slot')),
            'postId' => absint($this->getPostValue('post_id')),
            'blockFingerprint' => sanitize_text_field($this->getPostValue('block_id')),
            'email' => sanitize_email($this->getPostValue('booker_email')),
            'name' => sanitize_text_field($this->getPostValue('booker_name')),
            'waitlist' => $this->getPostValue('booker_waitlist') === '1',
        ];
    }

    /**
     * Resolves the authoritative context from a published appointment block.
     *
     * @param array{slot: string, postId: int, blockFingerprint: string, email: string, name: string, waitlist: bool} $request
     * @return array<string, mixed>
     */
    private function resolveBookingContext(array $request): array
    {
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

        return $context;
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
     * Validates the required booking identity fields.
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
        $booked = (array) get_option(Bookings::SLOTS_OPTION, []);
        $pending = TokenManager::getPendingSlots();
        if (in_array($slot, $booked, true) || in_array($slot, $pending, true)) {
            wp_send_json_error(__('This appointment is no longer available.', 'rrze-appointment'));
        }
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
        $variables = $this->getConfirmationVariables(
            $confirmToken,
            $datePart,
            $startTime,
            $endTime,
            $meta
        );
        $templateType = empty($meta['questions'])
            ? self::PENDING_TEMPLATE_TYPE
            : self::PENDING_QUESTIONS_TEMPLATE_TYPE;
        [$template, $default] = $this->getConfirmationTemplates(
            (int) ($meta['tpl_id'] ?? 0),
            $templateType
        );
        $subject = Settings::renderTemplate(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plain = !empty($template['body']) ? $template['body'] : $default['body'];
        $html = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];
        [$plain, $html] = $this->ensureRequiredConfirmationLinks($plain, $html);

        Settings::sendMail(
            $meta['booker_email'],
            $subject,
            Settings::renderTemplate($plain, $variables),
            Settings::renderTemplate($html, $variables),
            [],
            MailTemplate::STATUS_WARNING
        );
    }

    /**
     * Builds placeholder values for the pending-booking email.
     *
     * @param array<string, mixed> $meta Pending booking metadata.
     * @return array<string, string>
     */
    private function getConfirmationVariables(
        string $confirmToken,
        string $datePart,
        string $startTime,
        string $endTime,
        array $meta
    ): array {
        $confirmUrl = TokenManager::confirmUrl($confirmToken);
        $imprintUrl = TokenManager::imprintUrl();

        return [
            '[title]' => (string) $meta['title'],
            '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[time]' => $startTime . ' – ' . $endTime,
            '[location]' => (string) ($meta['location'] ?: '–'),
            '[person_name]' => (string) ($meta['person_name'] ?: '–'),
            '[name]' => (string) ($meta['booker_name'] ?: '–'),
            '[email]' => (string) ($meta['booker_email'] ?: '–'),
            '[questions]' => '',
            '[confirmation_link]' => $confirmUrl,
            '[cancel_link]' => TokenManager::cancelUrl(TokenManager::createPendingCancelToken($confirmToken)),
            '[imprint_link]' => $imprintUrl,
            '[post_link]' => (string) $meta['post_link'],
        ];
    }

    /**
     * Returns the selected custom template and its default fallback.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function getConfirmationTemplates(int $templateId, string $templateType): array
    {
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, $templateType) ?? [])
            : [];

        return [$template, MailTemplatePost::getDefault($templateType)];
    }

    /**
     * Ensures custom templates retain their required confirmation and imprint links.
     *
     * @return array{0: string, 1: string}
     */
    private function ensureRequiredConfirmationLinks(string $plain, string $html): array
    {
        if (strpos($plain, '[confirmation_link]') === false) {
            $plain .= "\n\n" . __('Confirmation', 'rrze-appointment') . ': [confirmation_link]';
        }
        if (strpos($plain, '[imprint_link]') === false) {
            $plain .= "\n" . __('Imprint', 'rrze-appointment') . ': [imprint_link]';
        }
        if (strpos($html, '[confirmation_link]') === false) {
            $html .= '<p><a href="[confirmation_link]">'
                . __('Confirm appointment now', 'rrze-appointment')
                . '</a></p>';
        }
        if (strpos($html, '[imprint_link]') === false) {
            $html .= '<p><a href="[imprint_link]">'
                . __('Imprint', 'rrze-appointment')
                . '</a></p>';
        }

        return [$plain, $html];
    }
}
