<?php

// Run both real public controllers against isolated state and delivery stubs.
namespace RRZE\Appointment {
    class Rights {
        public static function get(): array {
            return $GLOBALS['scenario']['identity'] ?? [
                'authenticated' => true,
                'bookerName' => 'SSO User',
                'bookerEmail' => 'sso@example.test',
            ];
        }
    }
}
namespace RRZE\Appointment\Booking {
    class AppointmentBlock {
        public static function resolvePublished(...$args): array {
            return [
                'disable_sso' => $GLOBALS['scenario']['disableSso'] ?? true,
                'title' => 'Test consultation', 'location' => '',
                'person_id' => 1, 'person_name' => 'Host', 'person_email' => 'host@example.test',
                'tpl_id' => 0, 'post_link' => 'https://example.test/appointment', 'questions' => [],
                'booking_not_open' => true, 'booking_opens_at' => 100, 'booking_closes_at' => 200,
            ];
        }
    }
    class Bookings { public const SLOTS_OPTION = 'booked'; }
    class TokenManager {
        public static function getPendingSlots(): array { return []; }
        public static function createPending($slot, $meta): string {
            $GLOBALS['pending'][] = compact('slot', 'meta');
            return 'test-token';
        }
        public static function createPendingCancelToken($token): string { return 'test-cancel-token'; }
        public static function confirmUrl($token): string { return 'https://example.test/confirm'; }
        public static function cancelUrl($token): string { return 'https://example.test/cancel'; }
        public static function imprintUrl(): string { return 'https://example.test/legal'; }
    }
}
namespace RRZE\Appointment\Notification {
    class BookingOpeningNotifier {
        public static function subscribe($slot, $meta, $opens, $closes): array {
            $GLOBALS['subscriptions'][] = compact('slot', 'meta');
            return ['statusToken' => 'test-status'];
        }
        public static function registrationUrl($token): string { return 'https://example.test/registered'; }
    }
}
namespace RRZE\Appointment\Mail {
    class MailTemplate { public const STATUS_WARNING = 'warning'; }
    class MailTemplatePost {
        public static function getDefault($type): array {
            return ['subject' => '[title]', 'body' => '[confirmation_link]', 'body_html' => '<p>[confirmation_link]</p>'];
        }
    }
    class Mailer {
        public static function render($template, $variables): string { return strtr($template, $variables); }
        public static function send($to, ...$args): bool {
            $GLOBALS['mails'][] = $to;
            return true;
        }
    }
}
namespace RRZE\Appointment\Presentation {
    class PublicPageRenderer {}
}
namespace RRZE\Appointment\Controller {
    function header($value): void { $GLOBALS['responseHeaders'][] = $value; }
}
namespace {
    define('ABSPATH', __DIR__);
    $input = json_decode(stream_get_contents(STDIN), true) ?: [];
    require __DIR__ . '/guest-rate-limit-storage.php';
    $GLOBALS['wpdb'] = new RateLimitTestDatabase();
    $GLOBALS['rateLimits'] = $input['limits'] ?? null;
    $GLOBALS['pending'] = $GLOBALS['subscriptions'] = $GLOBALS['mails'] = $GLOBALS['checkedEmails'] = [];
    class JsonResult extends RuntimeException {
        public function __construct(public bool $success, public mixed $data, public int $status = 200) { parent::__construct(); }
    }
    function wp_send_json_error($data, $status = 200): never { throw new JsonResult(false, $data, $status); }
    function wp_send_json_success($data): never { throw new JsonResult(true, $data); }
    function check_ajax_referer($action, $field) { return 1; }
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    function sanitize_text_field($value) { return trim(strip_tags($value)); }
    function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
    function is_email($value) {
        $GLOBALS['checkedEmails'][] = $value;
        // WordPress accepts some dot/length cases that PHP's validator rejects.
        // Let those scenarios exercise the plugin's additional checks directly.
        if (!empty($GLOBALS['scenario']['coreAcceptsSyntax'])) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false;
    }
    function absint($value) { return abs((int) $value); }
    function __($text, $domain) { return $text; }
    function is_wp_error($value) { return false; }
    function get_option($key, $default = false) {
        if ($key === 'date_format') { return 'Y-m-d'; }
        if ($key === 'booked') { return $GLOBALS['scenario']['booked'] ?? []; }
        return $default;
    }
    function date_i18n($format, $timestamp) { return gmdate($format, $timestamp); }

    $root = dirname(__DIR__, 2);
    require $root . '/includes/AppointmentException.php';
    require $root . '/includes/Mail/EmailAddress.php';
    require $root . '/includes/Booking/GuestRequestLimiter.php';
    require $root . '/includes/Controller/BookingRequestController.php';
    require $root . '/includes/Controller/BookingOpeningController.php';
    $results = [];
    foreach ($input['requests'] ?? [$input] as $scenario) {
        $GLOBALS['scenario'] = $scenario;
        $GLOBALS['testNow'] = $scenario['now'] ?? 1000;
        $GLOBALS['testBlog'] = $scenario['blog'] ?? 1;
        $GLOBALS['wpdb']->options = $GLOBALS['testBlog'] === 1 ? 'wp_options' : 'wp_2_options';
        $GLOBALS['responseHeaders'] = [];
        $_SERVER = [
            'REMOTE_ADDR' => $scenario['ip'] ?? '192.0.2.1',
            'HTTP_X_FORWARDED_FOR' => $scenario['forwarded'] ?? '',
        ];
        $email = $scenario['email'] ?? '';
        $_POST = [
            'slot' => '2099-01-01 10:00-10:30', 'post_id' => '42', 'block_id' => 'test-block',
            'booker_email' => is_string($email) ? addslashes($email) : $email,
            'booker_name' => 'Test User',
        ];
        try {
            if (($scenario['flow'] ?? 'booking') === 'notification') {
                (new \RRZE\Appointment\Controller\BookingOpeningController(
                    new \RRZE\Appointment\Presentation\PublicPageRenderer()
                ))->handleSubscription();
            } else {
                (new \RRZE\Appointment\Controller\BookingRequestController())->handleRequest();
            }
            throw new RuntimeException('The controller did not send a response.');
        } catch (JsonResult $result) {
            $results[] = [
                'success' => $result->success, 'data' => $result->data,
                'status' => $result->status, 'headers' => $GLOBALS['responseHeaders'],
                'pending' => $GLOBALS['pending'], 'subscriptions' => $GLOBALS['subscriptions'],
                'mails' => $GLOBALS['mails'], 'checkedEmails' => $GLOBALS['checkedEmails'],
            ];
        }
    }
    echo json_encode(isset($input['requests']) ? [
        'responses' => $results, 'rows' => $GLOBALS['wpdb']->rows(),
    ] : $results[0]);
}
