<?php

defined('ABSPATH') || exit;

$waitlistNotificationsEnabled = !empty($waitlistNotificationsEnabled);
$isCancellationConfirmation = !empty($isCancellationConfirmation);
$isOpeningNotification = !empty($isOpeningNotification);
$appointmentDetails = is_array($appointmentDetails ?? null) ? $appointmentDetails : [];
$hasAppointmentDetails = count(array_filter($appointmentDetails, static fn($value): bool => $value !== '')) > 0;

if ($isQuestionForm) {
    $statusText = __('Confirmation required', 'rrze-appointment');
    $headingText = __('Complete your appointment request', 'rrze-appointment');
    $messageText = __('Please answer the remaining questions below. Your appointment will be confirmed when you submit this form.', 'rrze-appointment');
} elseif ($isCancellationConfirmation) {
    $statusText = __('Cancellation', 'rrze-appointment');
    $headingText = __('Cancel this appointment?', 'rrze-appointment');
    $messageText = __('Please check the appointment details before cancelling. The appointment will only be cancelled after you use the button below.', 'rrze-appointment');
} elseif ($isCancellation) {
    $statusText = __('Appointment cancelled', 'rrze-appointment');
    $headingText = __('Your appointment has been cancelled', 'rrze-appointment');
    $messageText = __('Your appointment request has been cancelled successfully. No further action is required.', 'rrze-appointment');
} elseif ($isWaitlistOptOut) {
    $statusText = $waitlistNotificationsEnabled
        ? __('Notifications enabled', 'rrze-appointment')
        : __('Notifications disabled', 'rrze-appointment');
    $headingText = $waitlistNotificationsEnabled
        ? __('You are subscribed again', 'rrze-appointment')
        : __('You have unsubscribed', 'rrze-appointment');
    $messageText = $waitlistNotificationsEnabled
        ? __('You will receive an email again when an earlier appointment becomes available.', 'rrze-appointment')
        : __('Your appointment remains confirmed. You will no longer receive emails when an earlier appointment becomes available.', 'rrze-appointment');
} elseif ($isOpeningNotification) {
    $statusText = __('Notification registered', 'rrze-appointment');
    $headingText = __('We will let you know', 'rrze-appointment');
    $messageText = __('We will email you as soon as this appointment opens for booking. The appointment is not reserved until you complete the booking from that email.', 'rrze-appointment');
} else {
    $statusText = __('Successfully confirmed', 'rrze-appointment');
    $headingText = __('Your appointment is confirmed', 'rrze-appointment');
    $messageText = __('Thank you for confirming your appointment. We have sent the appointment details and a calendar invitation to your email address.', 'rrze-appointment');
}

$pageTitle = sprintf(
    /* translators: 1: confirmation page title, 2: website name. */
    __('%1$s – %2$s', 'rrze-appointment'),
    $headingText,
    $siteName
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, noarchive">
    <title><?php echo esc_html($pageTitle); ?></title>
    <style>
        :root {
            color-scheme: light;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: clamp(1rem, 4vw, 3rem);
            background: #f6f7f7;
            color: #1e1e1e;
            gap: 1.5rem;
        }

        .rrze-appointment-confirmation {
            display: grid;
            align-items: center;
            width: min(70rem, 100%);
            padding: clamp(2rem, 6vw, 5rem);
            border: 1px solid #e0e0e0;
            border-radius: 1.5rem;
            background: #fff;
            box-shadow: 0 1.5rem 4rem rgba(30, 30, 30, 0.1);
            gap: clamp(2rem, 6vw, 5rem);
            grid-template-columns: minmax(16rem, 0.9fr) minmax(18rem, 1.1fr);
        }

        .rrze-appointment-confirmation__illustration {
            display: flex;
            justify-content: center;
        }

        .rrze-appointment-confirmation__illustration img {
            display: block;
            width: min(100%, 30rem);
            height: auto;
        }

        .rrze-appointment-confirmation__status {
            display: inline-flex;
            align-items: center;
            min-height: 2rem;
            margin: 0 0 1.25rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            background: #e8f5e9;
            color: #1b5e20;
            font-size: 0.875rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .rrze-appointment-confirmation.is-question-form .rrze-appointment-confirmation__status {
            background: #fff8e1;
            color: #5d4037;
        }

        .rrze-appointment-confirmation.is-cancellation .rrze-appointment-confirmation__status {
            background: #ffebee;
            color: #b71c1c;
        }

        .rrze-appointment-confirmation.is-cancellation-confirmation .rrze-appointment-confirmation__status {
            background: #fff3e0;
            color: #7a2e00;
        }

        .rrze-appointment-confirmation.is-cancellation .rrze-appointment-confirmation__action {
            background: #04316a;
        }

        .rrze-appointment-confirmation.is-cancellation .rrze-appointment-confirmation__action:hover {
            background: #021f46;
        }

        .rrze-appointment-confirmation.is-cancellation-confirmation .rrze-appointment-confirmation__action--destructive {
            background: #b32d2e;
        }

        .rrze-appointment-confirmation.is-cancellation-confirmation .rrze-appointment-confirmation__action--destructive:hover {
            background: #8a2424;
        }

        .rrze-appointment-confirmation.is-waitlist-optout .rrze-appointment-confirmation__status {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .rrze-appointment-confirmation.is-opening-notification .rrze-appointment-confirmation__status {
            background: #fff4df;
            color: #6b3a00;
        }

        .rrze-appointment-confirmation.is-waitlist-optout .rrze-appointment-confirmation__action:not(.rrze-appointment-confirmation__action--secondary) {
            background: #04316a;
        }

        .rrze-appointment-confirmation.is-waitlist-optout .rrze-appointment-confirmation__action:not(.rrze-appointment-confirmation__action--secondary):hover {
            background: #021f46;
        }

        .rrze-appointment-confirmation h1 {
            max-width: 13ch;
            margin: 0;
            color: #1e1e1e;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.08;
            text-wrap: balance;
        }

        .rrze-appointment-confirmation__message {
            max-width: 38rem;
            margin: 1.5rem 0 0;
            color: #50575e;
            font-size: clamp(1rem, 2vw, 1.2rem);
            line-height: 1.7;
        }

        .rrze-appointment-confirmation__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 3rem;
            margin-top: 2rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.35rem;
            background: #2e7d32;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        button.rrze-appointment-confirmation__action {
            border: 0;
            font: inherit;
            cursor: pointer;
        }

        .rrze-appointment-confirmation__action:hover {
            background: #1b5e20;
        }

        .rrze-appointment-confirmation__action:focus-visible {
            outline: 3px solid #04316a;
            outline-offset: 3px;
        }

        .rrze-appointment-confirmation__details {
            max-width: 38rem;
            margin-top: 2rem;
            padding: 1.25rem;
            border: 1px solid #dcdcde;
            border-radius: 0.5rem;
            background: #f6f7f7;
        }

        .rrze-appointment-confirmation__details h2 {
            margin: 0 0 1rem;
            font-size: 1.25rem;
            line-height: 1.35;
        }

        .rrze-appointment-confirmation__details dl {
            display: grid;
            margin: 0;
            gap: 0.75rem 1rem;
            grid-template-columns: minmax(6rem, auto) 1fr;
        }

        .rrze-appointment-confirmation__details dt {
            font-weight: 700;
        }

        .rrze-appointment-confirmation__details dd {
            min-width: 0;
            margin: 0;
        }

        .rrze-appointment-confirmation__form {
            display: grid;
            max-width: 38rem;
            margin-top: 2rem;
            gap: 1.25rem;
            text-align: left;
        }

        .rrze-appointment-confirmation__field {
            display: grid;
            gap: 0.5rem;
        }

        .rrze-appointment-confirmation__field-label {
            color: #1e1e1e;
            font-weight: 650;
            line-height: 1.4;
        }

        .rrze-appointment-confirmation__requirement {
            margin-left: 0.35rem;
            color: #646970;
            font-size: 0.85em;
            font-weight: 500;
        }

        .rrze-appointment-confirmation__legal-notice {
            margin: 0;
            padding: 0.65rem 0.75rem;
            border-left: 3px solid #646970;
            background: #f6f7f7;
            color: #50575e;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .rrze-appointment-confirmation__legal-notice strong {
            color: #2c3338;
        }

        .rrze-appointment-confirmation__field textarea,
        .rrze-appointment-confirmation__field select {
            width: 100%;
            min-height: 3rem;
            padding: 0.7rem 0.8rem;
            border: 1px solid #8c8f94;
            border-radius: 0.35rem;
            background: #fff;
            color: #1e1e1e;
            font: inherit;
            line-height: 1.5;
        }

        .rrze-appointment-confirmation__field textarea {
            min-height: 8rem;
            resize: vertical;
        }

        .rrze-appointment-confirmation__field textarea:focus,
        .rrze-appointment-confirmation__field select:focus {
            border-color: #2e7d32;
            box-shadow: 0 0 0 1px #2e7d32;
            outline: 2px solid transparent;
        }

        .rrze-appointment-confirmation__field textarea[aria-invalid="true"],
        .rrze-appointment-confirmation__field select[aria-invalid="true"] {
            border-color: #c62828;
            box-shadow: 0 0 0 1px #c62828;
        }

        .rrze-appointment-confirmation__error {
            margin: 0;
            padding: 0.85rem 1rem;
            border-left: 4px solid #c62828;
            background: #ffebee;
            color: #b71c1c;
            line-height: 1.5;
        }

        .rrze-appointment-confirmation__error:focus {
            outline: 3px solid #c62828;
            outline-offset: 3px;
        }

        .rrze-appointment-confirmation__form .rrze-appointment-confirmation__action {
            width: fit-content;
            margin-top: 0.5rem;
        }

        .rrze-appointment-confirmation__actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 2rem;
            gap: 0.75rem;
        }

        .rrze-appointment-confirmation__actions .rrze-appointment-confirmation__action {
            margin-top: 0;
        }

        .rrze-appointment-confirmation__optin-form {
            margin: 0;
        }

        .rrze-appointment-confirmation__action--secondary {
            min-height: 2.5rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid #04316a;
            background: transparent;
            color: #04316a;
            font-size: 0.875rem;
        }

        button.rrze-appointment-confirmation__action--secondary {
            border: 1px solid #04316a;
        }

        .rrze-appointment-confirmation__action--secondary:hover {
            background: #e3f2fd;
        }

        .rrze-appointment-public-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            width: min(70rem, 100%);
            padding: 0 0.75rem;
            color: #646970;
            gap: 0.75rem 1.5rem;
            font-size: 0.875rem;
        }

        .rrze-appointment-public-footer__links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1.25rem;
        }

        .rrze-appointment-public-footer a {
            color: #50575e;
            text-underline-offset: 0.2em;
        }

        .rrze-appointment-public-footer a:hover {
            color: #1e1e1e;
        }

        .rrze-appointment-public-footer a:focus-visible {
            border-radius: 0.15rem;
            outline: 3px solid #04316a;
            outline-offset: 3px;
        }

        .rrze-appointment-public-footer__credit {
            margin: 0;
            font-size: 0.8125rem;
            font-style: italic;
        }

        @media (max-width: 48rem) {
            .rrze-appointment-confirmation {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .rrze-appointment-confirmation__illustration img {
                width: min(100%, 22rem);
            }

            .rrze-appointment-confirmation h1,
            .rrze-appointment-confirmation__message {
                margin-right: auto;
                margin-left: auto;
            }

            .rrze-appointment-public-footer {
                justify-content: center;
                text-align: center;
            }

            .rrze-appointment-public-footer__links {
                justify-content: center;
            }

            .rrze-appointment-confirmation__actions {
                justify-content: center;
            }

            .rrze-appointment-confirmation__details dl {
                grid-template-columns: 1fr;
                gap: 0.2rem;
                text-align: left;
            }

            .rrze-appointment-confirmation__details dd + dt {
                margin-top: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <main class="rrze-appointment-confirmation<?php
        echo $isQuestionForm
            ? ' is-question-form'
            : ($isCancellationConfirmation
                ? ' is-cancellation-confirmation'
                : ($isCancellation
                    ? ' is-cancellation'
                    : ($isWaitlistOptOut
                        ? ' is-waitlist-optout'
                        : ($isOpeningNotification ? ' is-opening-notification' : ''))));
    ?>">
        <div class="rrze-appointment-confirmation__illustration" aria-hidden="true">
            <img src="<?php echo esc_url($illustrationUrl); ?>" alt="">
        </div>
        <div class="rrze-appointment-confirmation__content">
            <p class="rrze-appointment-confirmation__status">
                <?php echo esc_html($statusText); ?>
            </p>
            <h1><?php echo esc_html($headingText); ?></h1>
            <p class="rrze-appointment-confirmation__message">
                <?php echo esc_html($messageText); ?>
            </p>

            <?php if ($hasAppointmentDetails) : ?>
                <section class="rrze-appointment-confirmation__details" aria-labelledby="rrze-appt-details-title">
                    <h2 id="rrze-appt-details-title"><?php esc_html_e('Appointment details', 'rrze-appointment'); ?></h2>
                    <dl>
                        <?php if (($appointmentDetails['title'] ?? '') !== '') : ?>
                            <dt><?php esc_html_e('Appointment', 'rrze-appointment'); ?></dt>
                            <dd><?php echo esc_html($appointmentDetails['title']); ?></dd>
                        <?php endif; ?>
                        <?php if (($appointmentDetails['date'] ?? '') !== '') : ?>
                            <dt><?php esc_html_e('Date', 'rrze-appointment'); ?></dt>
                            <dd><?php echo esc_html($appointmentDetails['date']); ?></dd>
                        <?php endif; ?>
                        <?php if (($appointmentDetails['time'] ?? '') !== '') : ?>
                            <dt><?php esc_html_e('Time', 'rrze-appointment'); ?></dt>
                            <dd><?php echo esc_html($appointmentDetails['time']); ?></dd>
                        <?php endif; ?>
                        <?php if (($appointmentDetails['location'] ?? '') !== '') : ?>
                            <dt><?php esc_html_e('Location', 'rrze-appointment'); ?></dt>
                            <dd><?php echo esc_html($appointmentDetails['location']); ?></dd>
                        <?php endif; ?>
                    </dl>
                </section>
            <?php endif; ?>

            <?php if ($isQuestionForm) : ?>
                <form class="rrze-appointment-confirmation__form" method="post" action="<?php echo esc_url($formAction); ?>">
                    <input type="hidden" name="rrze_appt_questions_nonce" value="<?php echo esc_attr($formNonce); ?>">
                    <?php if ($formError !== '') : ?>
                        <p
                            class="rrze-appointment-confirmation__error"
                            id="rrze-appt-form-error"
                            tabindex="-1"
                            <?php echo $formErrorField === '' ? 'autofocus' : ''; ?>
                        >
                            <?php echo esc_html($formError); ?>
                        </p>
                    <?php endif; ?>

                    <?php foreach ($questions as $question) :
                        $questionId = sanitize_key((string) ($question['id'] ?? ''));
                        $questionLabel = sanitize_text_field((string) ($question['label'] ?? ''));
                        $questionDataUse = sanitize_textarea_field((string) ($question['dataUse'] ?? ''));
                        $questionType = ($question['type'] ?? '') === 'select' ? 'select' : 'text';
                        $questionRequired = !empty($question['required']);
                        $questionOptions = is_array($question['options'] ?? null) ? $question['options'] : [];
                        $submittedAnswer = (string) ($submittedAnswers[$questionId] ?? '');
                        if ($questionId === '' || $questionLabel === '') {
                            continue;
                        }
                        $fieldId = 'rrze-appt-question-' . $questionId;
                        $noticeId = $fieldId . '-data-use';
                        $questionHasError = $formError !== '' && $formErrorField === $questionId;
                        $describedByIds = [];
                        if ($questionDataUse !== '') {
                            $describedByIds[] = $noticeId;
                        }
                        if ($questionHasError) {
                            $describedByIds[] = 'rrze-appt-form-error';
                        }
                        $describedBy = implode(' ', $describedByIds);
                        ?>
                        <div class="rrze-appointment-confirmation__field">
                            <label class="rrze-appointment-confirmation__field-label" for="<?php echo esc_attr($fieldId); ?>">
                                <?php echo esc_html($questionLabel); ?>
                                <span
                                    class="rrze-appointment-confirmation__requirement"
                                    <?php echo $questionRequired ? 'aria-hidden="true"' : ''; ?>
                                >
                                    <?php
                                    echo esc_html(
                                        $questionRequired
                                            ? __('Required', 'rrze-appointment')
                                            : __('Optional', 'rrze-appointment')
                                    );
                                    ?>
                                </span>
                            </label>
                            <?php if ($questionType === 'select') : ?>
                                <select
                                    id="<?php echo esc_attr($fieldId); ?>"
                                    name="question_answers[<?php echo esc_attr($questionId); ?>]"
                                    <?php echo $questionRequired ? 'required' : ''; ?>
                                    <?php echo $describedBy !== '' ? 'aria-describedby="' . esc_attr($describedBy) . '"' : ''; ?>
                                    <?php echo $questionHasError ? 'aria-invalid="true" aria-errormessage="rrze-appt-form-error" autofocus' : ''; ?>
                                >
                                    <option value=""><?php esc_html_e('Select an option', 'rrze-appointment'); ?></option>
                                    <?php foreach ($questionOptions as $option) :
                                        $option = sanitize_text_field((string) $option);
                                        ?>
                                        <option value="<?php echo esc_attr($option); ?>" <?php selected($submittedAnswer, $option); ?>>
                                            <?php echo esc_html($option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <textarea
                                    id="<?php echo esc_attr($fieldId); ?>"
                                    name="question_answers[<?php echo esc_attr($questionId); ?>]"
                                    maxlength="5000"
                                    rows="5"
                                    <?php echo $questionRequired ? 'required' : ''; ?>
                                    <?php echo $describedBy !== '' ? 'aria-describedby="' . esc_attr($describedBy) . '"' : ''; ?>
                                    <?php echo $questionHasError ? 'aria-invalid="true" aria-errormessage="rrze-appt-form-error" autofocus' : ''; ?>
                                ><?php echo esc_textarea($submittedAnswer); ?></textarea>
                            <?php endif; ?>
                            <?php if ($questionDataUse !== '') : ?>
                                <p class="rrze-appointment-confirmation__legal-notice" id="<?php echo esc_attr($noticeId); ?>">
                                    <strong><?php esc_html_e('Why we ask:', 'rrze-appointment'); ?></strong>
                                    <?php echo nl2br(esc_html($questionDataUse)); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button class="rrze-appointment-confirmation__action" type="submit">
                        <?php esc_html_e('Confirm appointment', 'rrze-appointment'); ?>
                    </button>
                </form>
            <?php elseif ($isCancellationConfirmation) : ?>
                <form class="rrze-appointment-confirmation__form" method="post" action="<?php echo esc_url($cancellationAction); ?>">
                    <input type="hidden" name="rrze_appt_cancel_action" value="cancel">
                    <input type="hidden" name="rrze_appt_cancel_nonce" value="<?php echo esc_attr($cancellationNonce); ?>">
                    <?php if ($showCancellationReason) : ?>
                        <div class="rrze-appointment-confirmation__field">
                            <label class="rrze-appointment-confirmation__field-label" for="rrze-appt-cancellation-reason">
                                <?php esc_html_e('Reason for cancellation', 'rrze-appointment'); ?>
                                <span class="rrze-appointment-confirmation__requirement">
                                    <?php esc_html_e('Optional', 'rrze-appointment'); ?>
                                </span>
                            </label>
                            <textarea
                                id="rrze-appt-cancellation-reason"
                                name="cancellation_reason"
                                maxlength="<?php echo esc_attr(\RRZE\Appointment\Booking\Bookings::MAX_CANCELLATION_REASON_LENGTH); ?>"
                                rows="4"
                            ></textarea>
                            <p class="rrze-appointment-confirmation__legal-notice">
                                <?php esc_html_e('This reason will be included in the cancellation email.', 'rrze-appointment'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    <div class="rrze-appointment-confirmation__actions">
                        <button
                            class="rrze-appointment-confirmation__action rrze-appointment-confirmation__action--destructive"
                            type="submit"
                        >
                            <?php esc_html_e('Cancel appointment', 'rrze-appointment'); ?>
                        </button>
                        <a
                            class="rrze-appointment-confirmation__action rrze-appointment-confirmation__action--secondary"
                            href="<?php echo esc_url($homeUrl); ?>"
                        >
                            <?php esc_html_e('Keep appointment and return to website', 'rrze-appointment'); ?>
                        </a>
                    </div>
                </form>
            <?php else : ?>
                <div class="rrze-appointment-confirmation__actions">
                    <a class="rrze-appointment-confirmation__action" href="<?php echo esc_url($homeUrl); ?>">
                        <?php esc_html_e('Back to website', 'rrze-appointment'); ?>
                    </a>
                    <?php if ($isWaitlistOptOut && !$waitlistNotificationsEnabled) : ?>
                        <form
                            class="rrze-appointment-confirmation__optin-form"
                            method="post"
                            action="<?php echo esc_url($waitlistOptInAction); ?>"
                        >
                            <input type="hidden" name="rrze_appt_waitlist_action" value="optin">
                            <input type="hidden" name="rrze_appt_waitlist_nonce" value="<?php echo esc_attr($waitlistOptInNonce); ?>">
                            <button
                                class="rrze-appointment-confirmation__action rrze-appointment-confirmation__action--secondary"
                                type="submit"
                            >
                                <?php esc_html_e('Enable notifications again', 'rrze-appointment'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php require __DIR__ . '/public-page-footer.php'; ?>
    <?php if ($isQuestionForm && $formError !== '' && $formErrorField === '') : ?>
        <script>
            document.getElementById('rrze-appt-form-error').focus();
        </script>
    <?php endif; ?>
</body>
</html>
