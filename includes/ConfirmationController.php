<?php

namespace RRZE\Appointment;

use RRZE\Appointment\Common\CustomException;

defined('ABSPATH') || exit;

/**
 * Confirms pending bookings and sends the final appointment emails.
 */
final class ConfirmationController
{
    private const CONFIRMATION_QUERY_KEY = 'rrze_appt_confirm';
    private const QUESTIONS_NONCE_FIELD = 'rrze_appt_questions_nonce';
    private const QUESTIONS_FIELD = 'question_answers';
    private const QUESTIONS_NONCE_PREFIX = 'rrze_appointment_confirm_questions_';
    private const MAX_ANSWER_LENGTH = 5000;
    private const BOOKER_TEMPLATE_TYPE = 'booking_booker';
    private const HOST_TEMPLATE_TYPE = 'booking_host';
    private const CALENDAR_FILE_PREFIX = 'rrze_appt_';
    private const PERSISTABLE_META_KEYS = [
        'title',
        'location',
        'person_id',
        'person_name',
        'person_email',
        'booker_email',
        'booker_name',
        'booker_waitlist',
        'waitlist_notified_slots',
        'tpl_id',
        'post_link',
    ];

    private PublicPageRenderer $renderer;

    /**
     * @param PublicPageRenderer $renderer Public appointment page renderer.
     */
    public function __construct(PublicPageRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Handles confirmation links and optional question submission.
     */
    public function handleConfirmation(): void
    {
        try {
            $token = $this->getConfirmationToken();
            if ($token === '') {
                return;
            }

            $pendingEntry = TokenManager::getPending($token);
            if (!is_array($pendingEntry)) {
                $this->renderInvalidConfirmation();
                return;
            }

            $pendingMeta = is_array($pendingEntry['meta'] ?? null) ? $pendingEntry['meta'] : [];
            $questions = is_array($pendingMeta['questions'] ?? null) ? $pendingMeta['questions'] : [];
            $appointmentDetails = $this->renderer->getAppointmentDetails(
                (string) ($pendingEntry['slot'] ?? ''),
                $pendingMeta
            );
            $questionAnswers = $this->collectQuestionAnswers(
                $token,
                $questions,
                $appointmentDetails
            );
            if ($questionAnswers === null) {
                return;
            }

            $entry = TokenManager::confirmPending($token);
            if (!is_array($entry)) {
                $this->renderInvalidConfirmation();
                return;
            }

            $slot = (string) $entry['slot'];
            $meta = $this->getPersistableBookingMeta(
                is_array($entry['meta'] ?? null) ? $entry['meta'] : []
            );
            [$datePart, $startTime, $endTime] = $this->parseSlot($slot);

            $this->markSlotAsBooked($slot);
            Reminder::scheduleForSlot($slot, $meta);

            $variables = $this->buildTemplateVariables(
                $datePart,
                $startTime,
                $endTime,
                $meta,
                TokenManager::getCancelUrlForSlot($slot)
            );
            $this->sendConfirmationEmails(
                $meta,
                $variables,
                $questionAnswers,
                $this->createCalendarAttachment($datePart, $startTime, $endTime, $meta)
            );

            $this->renderer->renderConfirmation('', [], [], '', '', $appointmentDetails);
        } catch (CustomException $exception) {
            wp_die(esc_html($exception->getMessage()), '', ['response' => 500]);
        }
    }

    /**
     * Collects and validates request-scoped question answers.
     *
     * Answers are returned for the host email only and are never persisted.
     *
     * @param array<int, array<string, mixed>> $questions          Configured questions.
     * @param array<string, string>            $appointmentDetails Public appointment details.
     * @return array<int, array{label: string, answer: string}>|null Null after rendering a response.
     */
    private function collectQuestionAnswers(
        string $token,
        array $questions,
        array $appointmentDetails
    ): ?array {
        if ($questions === []) {
            return [];
        }

        if ($this->getRequestMethod() !== 'POST') {
            $this->renderer->renderConfirmation($token, $questions, [], '', '', $appointmentDetails);
            return null;
        }

        $nonce = $this->getPostText(self::QUESTIONS_NONCE_FIELD);
        $postedAnswers = wp_unslash($_POST[self::QUESTIONS_FIELD] ?? []);
        $postedAnswers = is_array($postedAnswers) ? $postedAnswers : [];
        $validation = $this->validateQuestionAnswers($questions, $postedAnswers);

        if (!wp_verify_nonce($nonce, self::QUESTIONS_NONCE_PREFIX . $token)) {
            $this->renderer->renderConfirmation(
                $token,
                $questions,
                $validation['values'],
                __('The form has expired. Please try again.', 'rrze-appointment'),
                '',
                $appointmentDetails
            );
            return null;
        }
        if ($validation['error'] !== '') {
            $this->renderer->renderConfirmation(
                $token,
                $questions,
                $validation['values'],
                $validation['error'],
                $validation['errorField'],
                $appointmentDetails
            );
            return null;
        }

        return $validation['answers'];
    }

    /**
     * Reads the confirmation token from the query string.
     */
    private function getConfirmationToken(): string
    {
        $token = wp_unslash($_GET[self::CONFIRMATION_QUERY_KEY] ?? '');
        return is_string($token) ? sanitize_text_field($token) : '';
    }

    /**
     * Reads and sanitizes a scalar form value.
     */
    private function getPostText(string $key): string
    {
        $value = wp_unslash($_POST[$key] ?? '');
        return is_string($value) ? sanitize_text_field($value) : '';
    }

    /**
     * Returns the normalized HTTP request method.
     */
    private function getRequestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * Truncates an answer without splitting multibyte characters when possible.
     */
    private function truncateAnswer(string $answer): string
    {
        return function_exists('mb_substr')
            ? mb_substr($answer, 0, self::MAX_ANSWER_LENGTH)
            : substr($answer, 0, self::MAX_ANSWER_LENGTH);
    }

    /**
     * Splits a stored slot into its date, start time, and end time.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseSlot(string $slot): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');

        return [$datePart, $startTime, $endTime];
    }

    /**
     * Validates and sanitizes answers submitted on the confirmation page.
     *
     * @param array<int, array<string, mixed>> $questions     Configured questions.
     * @param array<string, mixed>             $postedAnswers Submitted answer values.
     * @return array{answers: array<int, array{label: string, answer: string}>, values: array<string, string>, error: string, errorField: string}
     */
    private function validateQuestionAnswers(array $questions, array $postedAnswers): array
    {
        $answers = [];
        $values = [];
        $error = '';
        $errorField = '';

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = sanitize_key((string) ($question['id'] ?? ''));
            $label = sanitize_text_field((string) ($question['label'] ?? ''));
            if ($questionId === '' || $label === '') {
                continue;
            }

            $rawAnswer = $postedAnswers[$questionId] ?? '';
            $answer = is_scalar($rawAnswer) ? (string) $rawAnswer : '';
            $answer = ($question['type'] ?? '') === 'select'
                ? sanitize_text_field($answer)
                : sanitize_textarea_field($answer);
            $answer = trim($this->truncateAnswer($answer));
            $values[$questionId] = $answer;

            if (!empty($question['required']) && $answer === '' && $error === '') {
                $error = sprintf(
                    /* translators: %s: Question that requires an answer. */
                    __('Please answer “%s”.', 'rrze-appointment'),
                    $label
                );
                $errorField = $questionId;
            }

            $options = is_array($question['options'] ?? null) ? $question['options'] : [];
            if (
                ($question['type'] ?? '') === 'select'
                && $answer !== ''
                && !in_array($answer, $options, true)
                && $error === ''
            ) {
                $error = sprintf(
                    /* translators: %s: Question with an invalid dropdown answer. */
                    __('Select a valid answer for “%s”.', 'rrze-appointment'),
                    $label
                );
                $errorField = $questionId;
            }

            if ($answer !== '') {
                $answers[] = ['label' => $label, 'answer' => $answer];
            }
        }

        return [
            'answers' => $answers,
            'values' => $values,
            'error' => $error,
            'errorField' => $errorField,
        ];
    }

    /**
     * Restricts confirmed metadata to fields approved for persistence.
     *
     * @param array<string, mixed> $meta Pending metadata.
     * @return array<string, mixed>
     */
    private function getPersistableBookingMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip(self::PERSISTABLE_META_KEYS));
    }

    /**
     * Adds a confirmed slot to the public booked-slot option.
     */
    private function markSlotAsBooked(string $slot): void
    {
        $booked = (array) get_option(Bookings::SLOTS_OPTION, []);
        $booked[] = $slot;
        update_option(Bookings::SLOTS_OPTION, array_unique($booked), false);
    }

    /**
     * Builds email placeholder values for a confirmed booking.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     * @return array<string, string>
     */
    private function buildTemplateVariables(
        string $datePart,
        string $startTime,
        string $endTime,
        array $meta,
        string $cancelUrl
    ): array {
        return [
            '[title]' => $meta['title'] ?? '',
            '[date]' => date_i18n(get_option('date_format'), strtotime($datePart)),
            '[time]' => $startTime . ' – ' . $endTime,
            '[location]' => ($meta['location'] ?? '') ?: '–',
            '[person_name]' => trim((string) ($meta['person_name'] ?? '')) ?: '–',
            '[name]' => ($meta['booker_name'] ?? '') ?: '–',
            '[email]' => ($meta['booker_email'] ?? '') ?: '–',
            '[questions]' => '',
            '[confirmation_link]' => '',
            '[cancel_link]' => $cancelUrl,
            '[imprint_link]' => TokenManager::imprintUrl(),
            '[post_link]' => esc_url_raw($meta['post_link'] ?? home_url('/')),
        ];
    }

    /**
     * Creates an iCalendar attachment for the confirmed appointment.
     *
     * @param array<string, mixed> $meta Confirmed booking metadata.
     */
    private function createCalendarAttachment(
        string $datePart,
        string $startTime,
        string $endTime,
        array $meta
    ): string {
        $timezone = wp_timezone();
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($datePart . 'T' . $startTime . ':00', $timezone);
        $end = new \DateTimeImmutable($datePart . 'T' . $endTime . ':00', $timezone);
        $now = new \DateTimeImmutable('now', $utc);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RRZE Appointment//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . wp_generate_uuid4() . '@' . parse_url(home_url(), PHP_URL_HOST),
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escapeCalendarValue((string) ($meta['title'] ?? '')),
            'LOCATION:' . $this->escapeCalendarValue((string) ($meta['location'] ?? '')),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Escapes a value according to the iCalendar text format.
     */
    private function escapeCalendarValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /**
     * Sends confirmation emails to the booker and appointment host.
     *
     * @param array<string, mixed>                             $meta            Confirmed metadata.
     * @param array<string, string>                            $variables       Template variables.
     * @param array<int, array{label: string, answer: string}> $questionAnswers Request-scoped answers.
     */
    private function sendConfirmationEmails(
        array $meta,
        array $variables,
        array $questionAnswers,
        string $calendarContent
    ): void {
        $templateId = (int) ($meta['tpl_id'] ?? 0);
        [$bookerSubject, $bookerPlain, $bookerHtml] = $this->renderBookerEmail(
            $templateId,
            $variables
        );
        [$hostSubject, $hostPlain, $hostHtml] = $this->renderHostEmail(
            $templateId,
            $variables,
            $questionAnswers
        );

        $temporaryFile = $this->createTemporaryCalendarFile($calendarContent);
        try {
            Settings::sendMail(
                (string) ($meta['booker_email'] ?? ''),
                $bookerSubject,
                $bookerPlain,
                $bookerHtml,
                [$temporaryFile],
                MailTemplate::STATUS_SUCCESS
            );

            $hostEmail = sanitize_email((string) ($meta['person_email'] ?? ''));
            if ($hostEmail !== '') {
                Settings::sendMail(
                    $hostEmail,
                    $hostSubject,
                    $hostPlain,
                    $hostHtml,
                    [$temporaryFile],
                    MailTemplate::STATUS_SUCCESS
                );
            }
        } finally {
            wp_delete_file($temporaryFile);
        }
    }

    /**
     * Writes calendar content to a temporary .ics file.
     *
     * @throws CustomException When a temporary attachment cannot be created.
     */
    private function createTemporaryCalendarFile(string $calendarContent): string
    {
        $temporaryFile = tempnam(get_temp_dir(), self::CALENDAR_FILE_PREFIX);
        if ($temporaryFile === false) {
            throw new CustomException(
                __('The calendar attachment could not be created.', 'rrze-appointment')
            );
        }

        $calendarFile = $temporaryFile . '.ics';
        if (!rename($temporaryFile, $calendarFile)) {
            wp_delete_file($temporaryFile);
            throw new CustomException(
                __('The calendar attachment could not be created.', 'rrze-appointment')
            );
        }

        if (file_put_contents($calendarFile, $calendarContent, LOCK_EX) === false) {
            wp_delete_file($calendarFile);
            throw new CustomException(
                __('The calendar attachment could not be created.', 'rrze-appointment')
            );
        }

        return $calendarFile;
    }

    /**
     * Renders the final email sent to the person booking.
     *
     * @param array<string, string> $variables Template variables.
     * @return array{0: string, 1: string, 2: string}
     */
    private function renderBookerEmail(int $templateId, array $variables): array
    {
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, self::BOOKER_TEMPLATE_TYPE) ?? [])
            : [];
        $default = MailTemplatePost::getDefault(self::BOOKER_TEMPLATE_TYPE);
        $subject = Settings::renderTemplate(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plainTemplate = !empty($template['body']) ? $template['body'] : $default['body'];
        $htmlTemplate = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];
        [$plainTemplate, $htmlTemplate] = $this->appendRequiredLinks($plainTemplate, $htmlTemplate);

        return [
            $subject,
            Settings::renderTemplate($plainTemplate, $variables),
            Settings::renderTemplate($htmlTemplate, $variables),
        ];
    }

    /**
     * Renders the final email sent to the appointment host.
     *
     * @param array<string, string>                            $variables       Template variables.
     * @param array<int, array{label: string, answer: string}> $questionAnswers Request-scoped answers.
     * @return array{0: string, 1: string, 2: string}
     */
    private function renderHostEmail(
        int $templateId,
        array $variables,
        array $questionAnswers
    ): array {
        $template = $templateId > 0
            ? (MailTemplatePost::getTemplateForType($templateId, self::HOST_TEMPLATE_TYPE) ?? [])
            : [];
        $default = MailTemplatePost::getDefault(self::HOST_TEMPLATE_TYPE);
        $subject = Settings::renderTemplate(
            !empty($template['subject']) ? $template['subject'] : $default['subject'],
            $variables
        );
        $plainTemplate = !empty($template['body']) ? $template['body'] : $default['body'];
        $htmlTemplate = !empty($template['body_html']) ? $template['body_html'] : $default['body_html'];
        $questionSections = $this->formatQuestionAnswers($questionAnswers);
        if ($questionSections['plain'] !== '' && strpos($plainTemplate, '[questions]') === false) {
            $plainTemplate .= '[questions]';
        }
        if ($questionSections['html'] !== '' && strpos($htmlTemplate, '[questions]') === false) {
            $htmlTemplate .= '[questions]';
        }
        [$plainTemplate, $htmlTemplate] = $this->appendRequiredLinks($plainTemplate, $htmlTemplate);

        return [
            $subject,
            Settings::renderTemplate(
                $plainTemplate,
                array_merge($variables, ['[questions]' => $questionSections['plain']])
            ),
            Settings::renderTemplate(
                $htmlTemplate,
                array_merge($variables, ['[questions]' => $questionSections['html']])
            ),
        ];
    }

    /**
     * Appends cancellation and imprint links when a template omits them.
     *
     * @return array{0: string, 1: string}
     */
    private function appendRequiredLinks(string $plainTemplate, string $htmlTemplate): array
    {
        if (strpos($plainTemplate, '[cancel_link]') === false) {
            $plainTemplate .= "\n\n" . __('Cancel', 'rrze-appointment') . ': [cancel_link]';
        }
        if (strpos($plainTemplate, '[imprint_link]') === false) {
            $plainTemplate .= "\n" . __('Imprint', 'rrze-appointment') . ': [imprint_link]';
        }
        if (strpos($htmlTemplate, '[cancel_link]') === false) {
            $htmlTemplate .= '<p><a href="[cancel_link]">'
                . __('Cancel appointment', 'rrze-appointment')
                . '</a></p>';
        }
        if (strpos($htmlTemplate, '[imprint_link]') === false) {
            $htmlTemplate .= '<p><a href="[imprint_link]">'
                . __('Imprint', 'rrze-appointment')
                . '</a></p>';
        }

        return [$plainTemplate, $htmlTemplate];
    }

    /**
     * Formats request-scoped answers for the host's plain and HTML emails.
     *
     * @param array<int, array{label: string, answer: string}> $answers Answers to format.
     * @return array{plain: string, html: string}
     */
    private function formatQuestionAnswers(array $answers): array
    {
        if ($answers === []) {
            return ['plain' => '', 'html' => ''];
        }

        $plainRows = [];
        $htmlRows = '';
        foreach ($answers as $answer) {
            $label = (string) ($answer['label'] ?? '');
            $value = (string) ($answer['answer'] ?? '');
            $plainRows[] = $label . ': ' . $value;
            $htmlRows .= '<tr>'
                . '<th scope="row" style="width:34%;padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#5f6b7a;font-size:13px;font-weight:600;line-height:20px;text-align:left;vertical-align:top;">'
                . esc_html($label)
                . '</th><td style="padding:10px 12px;border-bottom:1px solid #e5e9ef;color:#1f2937;font-size:15px;line-height:22px;text-align:left;vertical-align:top;">'
                . nl2br(esc_html($value))
                . '</td></tr>';
        }

        return [
            'plain' => "\n\n" . __('Additional information:', 'rrze-appointment') . "\n" . implode("\n", $plainRows),
            'html' => '<h2 style="margin:28px 0 8px;color:#1f2937;font-size:20px;line-height:28px;">'
                . esc_html__('Additional information', 'rrze-appointment')
                . '</h2><table class="rrze-email-details" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:12px 0 20px;border-collapse:collapse;">'
                . $htmlRows
                . '</table>',
        ];
    }

    /**
     * Renders the shared invalid or expired confirmation response.
     */
    private function renderInvalidConfirmation(): void
    {
        $this->renderer->renderError(
            __('This confirmation link has expired or is invalid.', 'rrze-appointment'),
            410
        );
    }
}
