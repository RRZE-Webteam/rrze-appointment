<?php

namespace RRZE\Appointment\Presentation;

use RRZE\Appointment\Booking\TokenManager;
use function RRZE\Appointment\plugin;

defined('ABSPATH') || exit;

/**
 * Renders public confirmation, cancellation, waitlist, and error pages.
 */
final class PublicPageRenderer
{
    private const CONFIRMATION_TEMPLATE = 'confirmation-page.php';
    private const ERROR_TEMPLATE = 'error-page.php';
    private const ILLUSTRATION_PATH = 'assets/images';
    private const MODE_CONFIRMATION = 'confirmation';
    private const MODE_CANCELLATION_SUCCESS = 'cancellation_success';
    private const MODE_CANCELLATION_CONFIRMATION = 'cancellation_confirmation';
    private const MODE_WAITLIST = 'waitlist';
    private const MODE_OPENING_NOTIFICATION = 'opening_notification';
    private const QUESTION_NONCE_PREFIX = 'rrze_appointment_confirm_questions_';
    private const CANCELLATION_NONCE_PREFIX = 'rrze_appointment_cancel_';
    private const WAITLIST_NONCE_PREFIX = 'rrze_appointment_waitlist_optin_';

    /**
     * Builds the non-personal appointment summary shown on public pages.
     *
     * @param string               $slot Appointment slot identifier.
     * @param array<string, mixed> $meta Stored appointment metadata.
     * @return array{title: string, date: string, time: string, location: string}
     */
    public function getAppointmentDetails(string $slot, array $meta = []): array
    {
        [$datePart, $startTime, $endTime] = $this->parseSlot($slot);
        $date = '';
        $timezone = wp_timezone();
        $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $datePart, $timezone);
        if ($dateObject && $dateObject->format('Y-m-d') === $datePart) {
            $date = wp_date(
                (string) get_option('date_format'),
                $dateObject->getTimestamp(),
                $timezone
            );
        }

        return [
            'title' => sanitize_text_field((string) ($meta['title'] ?? '')),
            'date' => $date,
            'time' => $this->formatTimeRange($startTime, $endTime),
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
        $this->renderConfirmationPage(self::MODE_CONFIRMATION, [
            'token' => $token,
            'questions' => $questions,
            'submittedAnswers' => $submittedAnswers,
            'formError' => $formError,
            'formErrorField' => $formErrorField,
            'appointmentDetails' => $appointmentDetails,
        ]);
    }

    /**
     * Renders the success page after an appointment is cancelled.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderCancellationSuccess(array $appointmentDetails = []): void
    {
        $this->renderConfirmationPage(self::MODE_CANCELLATION_SUCCESS, [
            'appointmentDetails' => $appointmentDetails,
        ]);
    }

    /**
     * Renders the confirmation step before cancellation changes state.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderCancellationConfirmation(string $token, array $appointmentDetails): void
    {
        $this->renderConfirmationPage(self::MODE_CANCELLATION_CONFIRMATION, [
            'token' => $token,
            'appointmentDetails' => $appointmentDetails,
        ]);
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
        $this->renderConfirmationPage(self::MODE_WAITLIST, [
            'token' => $token,
            'notificationsEnabled' => $notificationsEnabled,
            'appointmentDetails' => $appointmentDetails,
        ]);
    }

    /**
     * Renders the success page after an opening notification is registered.
     *
     * @param array<string, string> $appointmentDetails Public appointment details.
     */
    public function renderOpeningNotificationSuccess(array $appointmentDetails = []): void
    {
        $this->renderConfirmationPage(self::MODE_OPENING_NOTIFICATION, [
            'appointmentDetails' => $appointmentDetails,
        ]);
    }

    /**
     * Renders the shared confirmation template with an explicit view context.
     *
     * @param array<string, mixed> $data Mode-specific context values.
     */
    private function renderConfirmationPage(string $mode, array $data): void
    {
        $this->prepareResponse(200);
        $context = $this->buildConfirmationContext($mode, $data);

        $homeUrl = $context['homeUrl'];
        $siteName = $context['siteName'];
        $isQuestionForm = $context['isQuestionForm'];
        $isCancellation = $context['isCancellation'];
        $isCancellationConfirmation = $context['isCancellationConfirmation'];
        $isWaitlistOptOut = $context['isWaitlistOptOut'];
        $isOpeningNotification = $context['isOpeningNotification'];
        $waitlistNotificationsEnabled = $context['waitlistNotificationsEnabled'];
        $waitlistOptInAction = $context['waitlistOptInAction'];
        $waitlistOptInNonce = $context['waitlistOptInNonce'];
        $cancellationAction = $context['cancellationAction'];
        $cancellationNonce = $context['cancellationNonce'];
        $illustrationUrl = $context['illustrationUrl'];
        $questions = $context['questions'];
        $submittedAnswers = $context['submittedAnswers'];
        $formError = $context['formError'];
        $formErrorField = $context['formErrorField'];
        $formAction = $context['formAction'];
        $formNonce = $context['formNonce'];
        $appointmentDetails = $context['appointmentDetails'];

        require $this->getTemplatePath(self::CONFIRMATION_TEMPLATE);
        exit;
    }

    /**
     * Builds the complete variable set expected by the confirmation template.
     *
     * @param array<string, mixed> $data Mode-specific context values.
     * @return array<string, mixed>
     */
    private function buildConfirmationContext(string $mode, array $data): array
    {
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];
        $isQuestionForm = $mode === self::MODE_CONFIRMATION
            && $token !== ''
            && $questions !== [];

        $context = [
            'homeUrl' => (string) home_url('/'),
            'siteName' => (string) get_bloginfo('name'),
            'isQuestionForm' => $isQuestionForm,
            'isCancellation' => $mode === self::MODE_CANCELLATION_SUCCESS,
            'isCancellationConfirmation' => $mode === self::MODE_CANCELLATION_CONFIRMATION,
            'isWaitlistOptOut' => $mode === self::MODE_WAITLIST,
            'isOpeningNotification' => $mode === self::MODE_OPENING_NOTIFICATION,
            'waitlistNotificationsEnabled' => !empty($data['notificationsEnabled']),
            'waitlistOptInAction' => '',
            'waitlistOptInNonce' => '',
            'cancellationAction' => '',
            'cancellationNonce' => '',
            'illustrationUrl' => $this->getIllustrationUrl('order-confirmed-62.png'),
            'questions' => $questions,
            'submittedAnswers' => is_array($data['submittedAnswers'] ?? null)
                ? $data['submittedAnswers']
                : [],
            'formError' => is_string($data['formError'] ?? null) ? $data['formError'] : '',
            'formErrorField' => is_string($data['formErrorField'] ?? null)
                ? $data['formErrorField']
                : '',
            'formAction' => '',
            'formNonce' => '',
            'appointmentDetails' => is_array($data['appointmentDetails'] ?? null)
                ? $data['appointmentDetails']
                : [],
        ];

        if ($isQuestionForm) {
            $context['illustrationUrl'] = $this->getIllustrationUrl('financial-analyst-31.png');
            $context['formAction'] = TokenManager::confirmUrl($token);
            $context['formNonce'] = wp_create_nonce(self::QUESTION_NONCE_PREFIX . $token);
        } elseif ($mode === self::MODE_CANCELLATION_SUCCESS) {
            $context['illustrationUrl'] = $this->getIllustrationUrl('neutral-face-89.png');
        } elseif ($mode === self::MODE_CANCELLATION_CONFIRMATION) {
            $context['illustrationUrl'] = $this->getIllustrationUrl('neutral-face-89.png');
            $context['cancellationAction'] = TokenManager::cancelUrl($token);
            $context['cancellationNonce'] = wp_create_nonce(self::CANCELLATION_NONCE_PREFIX . $token);
        } elseif ($mode === self::MODE_WAITLIST) {
            $context['illustrationUrl'] = $this->getIllustrationUrl('reminder-note-28.png');
            $context['waitlistOptInAction'] = TokenManager::waitlistOptOutUrl($token);
            $context['waitlistOptInNonce'] = wp_create_nonce(self::WAITLIST_NONCE_PREFIX . $token);
        } elseif ($mode === self::MODE_OPENING_NOTIFICATION) {
            $context['illustrationUrl'] = $this->getIllustrationUrl('notification-36.png');
        }

        return $context;
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

        return [
            sanitize_text_field($datePart),
            sanitize_text_field($startTime),
            sanitize_text_field($endTime),
        ];
    }

    /**
     * Formats a public time range from normalized slot parts.
     */
    private function formatTimeRange(string $startTime, string $endTime): string
    {
        if ($startTime === '') {
            return '';
        }

        return $endTime === '' ? $startTime : $startTime . ' – ' . $endTime;
    }

    /**
     * Sends the status and cache headers shared by all public pages.
     */
    private function prepareResponse(int $statusCode): void
    {
        status_header($statusCode);
        nocache_headers();
    }

    /**
     * Returns a bundled illustration URL.
     */
    private function getIllustrationUrl(string $filename): string
    {
        return plugin()->getUrl(self::ILLUSTRATION_PATH) . $filename;
    }

    /**
     * Returns the absolute path to a public page template.
     */
    private function getTemplatePath(string $filename): string
    {
        return plugin()->getPath('templates') . $filename;
    }

    /**
     * Renders a public error without exposing the WordPress error interface.
     */
    public function renderError(string $message, int $statusCode = 410): void
    {
        $this->prepareResponse($statusCode);

        $homeUrl = home_url('/');
        $siteName = get_bloginfo('name');
        $illustrationUrl = $this->getIllustrationUrl('bug-fixing-71.png');
        $errorMessage = $message;

        require $this->getTemplatePath(self::ERROR_TEMPLATE);
        exit;
    }
}
