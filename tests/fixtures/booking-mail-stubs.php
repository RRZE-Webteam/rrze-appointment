<?php

namespace RRZE\Appointment\Notification {
    class Reminder { public const CRON_HOOK = 'reminder'; }
}
namespace RRZE\Appointment\Booking {
    class TokenManager {
        public static function imprintUrl(): string { return 'https://example.test/legal'; }
    }
}
namespace RRZE\Appointment\Mail {
    class MailTemplate {
        public const STATUS_NEUTRAL = 'neutral';
        public const STATUS_DANGER = 'danger';
    }
    class MailTemplatePost {
        public static function getDefault($type): array {
            return ['subject' => '[title]', 'body' => 'Cancelled [title]', 'body_html' => '<p>Cancelled [title]</p>'];
        }
    }
    class Mailer {
        public static array $mails = [];
        public static function render($template, $variables): string { return strtr($template, $variables); }
        public static function send($to, $subject, $plain, $html, $attachments = [], $status = ''): bool {
            self::$mails[] = compact('to', 'subject', 'plain', 'html');
            return true;
        }
    }
}
