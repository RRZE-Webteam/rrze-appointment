<?php

namespace RRZE\Appointment\Mail {
    /** Replaces public template rendering so this fixture focuses on mail boundaries. */
    final class MailTemplate {
        public const STATUS_NEUTRAL = 'neutral';
        /** Returns the test HTML or simulates a template integration failure. */
        public static function wrap($html, $subject, $status): string {
            if ($GLOBALS['mode'] === 'template-error') {
                throw new \RuntimeException('synthetic@example.test secret-token');
            }
            return $html;
        }
    }
}
namespace {
    use RRZE\Appointment\Mail\Mailer;
    use RRZE\Appointment\AppointmentException;

    if (PHP_SAPI !== 'cli') { exit; }
    define('ABSPATH', __DIR__);
    $root = dirname(__DIR__, 2);
    require $root . '/includes/AppointmentException.php';
    require $root . '/includes/Mail/Mailer.php';
    // Load PHPMailer only; do not load wp-config, WordPress or an SMTP transport.
    require $argv[1] . '/Exception.php';
    require $argv[1] . '/PHPMailer.php';
    $GLOBALS['hooks'] = [];
    $GLOBALS['mode'] = 'success';
    $GLOBALS['mime'] = '';
    $GLOBALS['attachmentKinds'] = [];
    /** Preserves stable English failure messages for the assertions. */
    function __($text, $domain): string { return $text; }
    /** Registers the plugin's per-message PHPMailer callback. */
    function add_action($hook, $callback): void { $GLOBALS['hooks'][$hook] = $callback; }
    /** Removes the plugin's callback even after mail preparation fails. */
    function remove_action($hook, $callback): void { unset($GLOBALS['hooks'][$hook]); }
    /** Builds a real MIME message in memory; never sends or writes it to disk. */
    function wp_mail($to, $subject, $plain, $headers = [], $attachments = []): bool
    {
        if ($GLOBALS['mode'] === 'throw') {
            throw new \RuntimeException('synthetic@example.test secret-token');
        }
        if ($GLOBALS['mode'] === 'false') { return false; }
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->setFrom('host@example.test');
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $plain;
        ($GLOBALS['hooks']['phpmailer_init'])($mailer);
        $GLOBALS['attachmentKinds'] = array_column($mailer->getAttachments(), 5);
        $GLOBALS['fileAttachments'] = $attachments;
        $mailer->preSend();
        $GLOBALS['mime'] = $mailer->getSentMIMEMessage();
        return true;
    }
    $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
    Mailer::send('synthetic@example.test', 'Appointment', 'Plain body', '<p>HTML body</p>', [], 'neutral', ['appointment.ics' => $calendar]);
    $result = [
        'mimeHasCalendar' => str_contains($GLOBALS['mime'], 'text/calendar') && str_contains($GLOBALS['mime'], 'appointment.ics')
            && str_contains($GLOBALS['mime'], base64_encode($calendar)),
        'onlyStringAttachments' => $GLOBALS['attachmentKinds'] === [true] && $GLOBALS['fileAttachments'] === [],
    ];
    foreach (['success', 'throw', 'false', 'template-error'] as $mode) {
        $GLOBALS['mode'] = $mode;
        $failedSafely = false;
        try {
            Mailer::send('synthetic@example.test', 'Appointment', 'Secret answer', '<p>Secret answer</p>', [], 'neutral', ['appointment.ics' => $calendar]);
        } catch (AppointmentException $error) {
            $failedSafely = $error->getPrevious() === null && !str_contains((string) $error, 'secret-token')
                && !str_contains((string) $error, 'synthetic@example.test') && !str_contains((string) $error, 'Secret answer');
        }
        $result[$mode] = [
            'failedSafely' => $failedSafely,
            'cleared' => !isset($GLOBALS['rrze_appointment_html_body']) && !isset($GLOBALS['rrze_appointment_string_attachments'])
                && $GLOBALS['hooks'] === [],
        ];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
