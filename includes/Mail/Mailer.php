<?php

namespace RRZE\Appointment\Mail;

use RRZE\Appointment\AppointmentException;

defined('ABSPATH') || exit;

/**
 * Renders appointment templates and sends multipart email.
 */
final class Mailer
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function render(#[\SensitiveParameter] string $template, #[\SensitiveParameter] array $variables): string
    {
        // Existing custom templates may still contain the removed placeholder.
        $variables['[message]'] = '';

        $replacements = [];
        foreach ($variables as $placeholder => $value) {
            $replacements[(string) $placeholder] = is_scalar($value) ? (string) $value : '';
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<int, string> $attachments Absolute attachment paths.
     * @param array<string, string> $stringAttachments Attachment filename => in-memory content.
     */
    public static function send(
        #[\SensitiveParameter] string $to,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $plain,
        #[\SensitiveParameter] string $html,
        #[\SensitiveParameter] array $attachments = [],
        #[\SensitiveParameter] string $status = MailTemplate::STATUS_NEUTRAL,
        #[\SensitiveParameter] array $stringAttachments = []
    ): bool {
        try {
            $GLOBALS['rrze_appointment_html_body'] = MailTemplate::wrap($html, $subject, $status);
            $GLOBALS['rrze_appointment_string_attachments'] = $stringAttachments;
            add_action('phpmailer_init', [self::class, 'addHtmlPart']);
            if (!wp_mail($to, $subject, $plain, [], $attachments)) {
                throw new AppointmentException(__('The appointment email could not be sent. Please try again.', 'rrze-appointment'), 502);
            }
            return true;
        } catch (\Throwable $exception) {
            throw AppointmentException::fromThrowable($exception);
        } finally {
            remove_action('phpmailer_init', [self::class, 'addHtmlPart']);
            unset($GLOBALS['rrze_appointment_html_body']);
            unset($GLOBALS['rrze_appointment_string_attachments']);
        }
    }

    /** Adds HTML and calendar bytes directly to this message without creating files. */
    public static function addHtmlPart(#[\SensitiveParameter] \PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $html = $GLOBALS['rrze_appointment_html_body'] ?? '';
        if (!is_string($html) || $html === '') {
            return;
        }

        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->AltBody = $phpmailer->Body;
        $phpmailer->Body = $html;
        $phpmailer->isHTML(true);
        foreach ($GLOBALS['rrze_appointment_string_attachments'] ?? [] as $filename => $content) {
            if (!$phpmailer->addStringAttachment($content, $filename, 'base64', 'text/calendar')) {
                throw new AppointmentException(__('The calendar attachment could not be created.', 'rrze-appointment'));
            }
        }
    }
}
