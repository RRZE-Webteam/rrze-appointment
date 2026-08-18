<?php

namespace RRZE\Appointment;

defined('ABSPATH') || exit;

/**
 * Renders public confirmation, cancellation, waitlist, and error pages.
 */
final class PublicPageRenderer
{
    /**
     * Builds the non-personal appointment summary shown on public pages.
     *
     * @param string               $slot Appointment slot identifier.
     * @param array<string, mixed> $meta Stored appointment metadata.
     * @return array{title: string, date: string, time: string, location: string}
     */
    public function getAppointmentDetails(string $slot, array $meta = []): array
    {
        [$datePart, $timePart] = array_pad(explode(' ', $slot, 2), 2, '');
        [$startTime, $endTime] = array_pad(explode('-', $timePart, 2), 2, '');
        $date = '';
        $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $datePart, wp_timezone());
        if ($dateObject && $dateObject->format('Y-m-d') === $datePart) {
            $date = wp_date(get_option('date_format'), $dateObject->getTimestamp(), wp_timezone());
        }

        return [
            'title' => sanitize_text_field((string) ($meta['title'] ?? '')),
            'date' => $date,
            'time' => $startTime !== ''
                ? ($endTime !== '' ? $startTime . ' – ' . $endTime : $startTime)
                : '',
            'location' => sanitize_text_field((string) ($meta['location'] ?? '')),
        ];
    }

    /**
     * Renders the question form or the completed confirmation page.
     *
     * @param array<int, array<string, mixed>> $questions          Configured questions.
     * @param array<string, string>            $submittedAnswers   Submitted answers.
     * @param array<string, string>            $appointmentDetails Public appointment details.
     */
    public function renderConfirmation(
        string $token = '',
        array $questions = [],
        array $submittedAnswers = [],
        string $formError = '',
        string $formErrorField = '',
        array $appointmentDetails = []
    ): void {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = $token !== '' && !empty($questions);
        $isCancellation = false;
        $isCancellationConfirmation = false;
        $isWaitlistOptOut = false;
        $waitlistNotificationsEnabled = false;
        $waitlistOptInAction = '';
        $waitlistOptInNonce = '';
        $cancellationAction = '';
        $cancellationNonce = '';
        $illustrationUrl = plugin()->getUrl('src/illustrations')
            . ($isQuestionForm ? 'financial-analyst-31.png' : 'order-confirmed-62.png');
        $formAction = $isQuestionForm ? TokenManager::confirmUrl($token) : '';
        $formNonce = $isQuestionForm
            ? wp_create_nonce('rrze_appointment_confirm_questions_' . $token)
            : '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders the success page after an appointment is cancelled.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderCancellationSuccess(array $appointmentDetails = []): void
    {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = false;
        $isCancellation = true;
        $isCancellationConfirmation = false;
        $isWaitlistOptOut = false;
        $waitlistNotificationsEnabled = false;
        $waitlistOptInAction = '';
        $waitlistOptInNonce = '';
        $cancellationAction = '';
        $cancellationNonce = '';
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'neutral-face-89.png';
        $questions = [];
        $submittedAnswers = [];
        $formError = '';
        $formErrorField = '';
        $formAction = '';
        $formNonce = '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders the confirmation step before cancellation changes state.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderCancellationConfirmation(string $token, array $appointmentDetails): void
    {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = false;
        $isCancellation = false;
        $isCancellationConfirmation = true;
        $isWaitlistOptOut = false;
        $waitlistNotificationsEnabled = false;
        $waitlistOptInAction = '';
        $waitlistOptInNonce = '';
        $cancellationAction = TokenManager::cancelUrl($token);
        $cancellationNonce = wp_create_nonce('rrze_appointment_cancel_' . $token);
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'neutral-face-89.png';
        $questions = [];
        $submittedAnswers = [];
        $formError = '';
        $formErrorField = '';
        $formAction = '';
        $formNonce = '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders the waitlist notification status page.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderWaitlistStatus(
        string $token,
        bool $notificationsEnabled = false,
        array $appointmentDetails = []
    ): void {
        status_header(200);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $isQuestionForm = false;
        $isCancellation = false;
        $isCancellationConfirmation = false;
        $isWaitlistOptOut = true;
        $waitlistNotificationsEnabled = $notificationsEnabled;
        $waitlistOptInAction = TokenManager::waitlistOptOutUrl($token);
        $waitlistOptInNonce = wp_create_nonce('rrze_appointment_waitlist_optin_' . $token);
        $cancellationAction = '';
        $cancellationNonce = '';
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'reminder-note-28.png';
        $questions = [];
        $submittedAnswers = [];
        $formError = '';
        $formErrorField = '';
        $formAction = '';
        $formNonce = '';

        require plugin()->getPath('templates') . 'confirmation-page.php';
        exit;
    }

    /**
     * Renders a public error without exposing the WordPress error interface.
     */
    public function renderError(string $message, int $statusCode = 410): void
    {
        status_header($statusCode);
        nocache_headers();

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $illustrationUrl = plugin()->getUrl('src/illustrations') . 'bug-fixing-71.png';
        $errorMessage = $message;

        require plugin()->getPath('templates') . 'error-page.php';
        exit;
    }
}
