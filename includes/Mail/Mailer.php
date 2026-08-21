<?php

namespace RRZE\Appointment\Mail;

defined('ABSPATH') || exit;

/**
 * Renders appointment templates and sends multipart email.
 */
final class Mailer
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function render(string $template, array $variables): string
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
     */
    public static function send(
        string $to,
        string $subject,
        string $plain,
        string $html,
        array $attachments = [],
        string $status = MailTemplate::STATUS_NEUTRAL
    ): bool {
        $GLOBALS['rrze_appointment_html_body'] = MailTemplate::wrap($html, $subject, $status);
        add_action('phpmailer_init', [self::class, 'addHtmlPart']);
        try {
            return wp_mail($to, $subject, $plain, [], $attachments);
        } finally {
            remove_action('phpmailer_init', [self::class, 'addHtmlPart']);
            unset($GLOBALS['rrze_appointment_html_body']);
        }
    }

    public static function addHtmlPart(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $html = $GLOBALS['rrze_appointment_html_body'] ?? '';
        if (!is_string($html) || $html === '') {
            return;
        }

        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->AltBody = $phpmailer->Body;
        $phpmailer->Body = $html;
        $phpmailer->isHTML(true);
    }
}
