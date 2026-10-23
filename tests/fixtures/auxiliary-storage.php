<?php

namespace RRZE\Appointment\Presentation {
    /** Captures headers without emitting an HTTP response in the CLI test. */
    function header(string $header): void { $GLOBALS['headers'][] = $header; }
    /** Makes early header dispatch deterministic in the CLI fixture. */
    function headers_sent(): bool { return false; }
}
namespace {
    use RRZE\Appointment\AppointmentException;
    use RRZE\Appointment\Presentation\PrivateResponses;
    use RRZE\Appointment\Storage\BookingStorage;

    define('RRZE_APPOINTMENT_STORAGE_TEST_BOOTSTRAP_ONLY', true);
    require __DIR__ . '/booking-storage.php';
    if (($argv[1] ?? '') !== 'legacy') {
        define('RRZE_APPOINTMENT_ENCRYPTION_KEY', base64_encode(str_repeat('test-key', 8)));
    }
    $wpdb = new BookingTestDatabase();
    $GLOBALS['evicted'] = [];
    $GLOBALS['headers'] = [];
    $GLOBALS['ajax'] = false;

    /** Supplies a translation-free message for assertions. */
    function __($text, $domain): string { return $text; }
    /** Records the common no-cache header function. */
    function nocache_headers(): void { $GLOBALS['headers'][] = 'nocache'; }
    /** Selects an AJAX or ordinary page request. */
    function wp_doing_ajax(): bool { return $GLOBALS['ajax']; }
    /** Supplies per-site context for the WordPress-salt fallback before encryption. */
    function get_current_blog_id(): int { return $GLOBALS['wpdb']->options === 'wp_options' ? 1 : 2; }

    $result = [];
    $slot = '2099-04-12 09:00-09:30';
    $first = BookingStorage::lockReference('waitlist', $slot);
    $result['opaqueLock'] = preg_match('/^v2_[a-f0-9]{64}$/D', $first) === 1 && !str_contains($first, md5($slot));
    $result['stableLock'] = BookingStorage::lockReference('waitlist', $slot) === $first;
    $result['purposeBound'] = BookingStorage::lockReference('opening', $slot) !== $first;
    BookingStorage::release();
    $wpdb->options = 'wp_2_options';
    $result['siteBound'] = BookingStorage::lockReference('waitlist', $slot) !== $first;
    BookingStorage::release();
    $wpdb->options = 'wp_options';
    $cipherBefore = $wpdb->raw(BookingStorage::OPTIONS[0]);
    for ($index = 0; $index < 125; $index++) {
        $wpdb->seed('rrze_appointment_waitlist_lock_' . md5('slot-' . $index), (string) (time() - 301));
    }
    $live = 'rrze_appointment_opening_claim_' . md5('live');
    $foreign = 'foreign_' . md5('old');
    $new = 'rrze_appointment_waitlist_lock_' . $first;
    $wpdb->seed($live, (string) time());
    $wpdb->seed($foreign, '1');
    $wpdb->seed($new, (string) time());
    $staleNew = 'rrze_appointment_waitlist_lock_v2_' . hash('sha256', 'stale');
    $wpdb->seed($staleNew, '1');
    BookingStorage::initialize();
    $remaining = (int) $wpdb->db->querySingle("SELECT COUNT(*) FROM wp_options WHERE option_name REGEXP '^rrze_appointment_waitlist_lock_[a-f0-9]{32}$'");
    $result['boundedCleanup'] = $remaining === 25;
    $result['unrelatedLocksPreserved'] = $wpdb->raw($live) !== null && $wpdb->raw($foreign) !== null && $wpdb->raw($new) !== null;
    BookingStorage::release();
    BookingStorage::initialize();
    $result['cleanupResumed'] = (int) $wpdb->db->querySingle("SELECT COUNT(*) FROM wp_options WHERE option_name REGEXP '^rrze_appointment_waitlist_lock_[a-f0-9]{32}$'") === 0;
    $result['expiredCurrentLockRemoved'] = $wpdb->raw($staleNew) === null;
    $result['bookingUnchanged'] = $cipherBefore === $wpdb->raw(BookingStorage::OPTIONS[0]);
    BookingStorage::release();

    $raw = new \RuntimeException('Synthetic Patient synthetic@example.test secret-token');
    $safe = AppointmentException::fromThrowable($raw);
    $result['safeFailure'] = !str_contains((string) $safe, 'synthetic@example.test')
        && !str_contains((string) $safe, 'secret-token') && $safe->getPrevious() === null;
    $domain = new AppointmentException('A deliberate safe message.');
    $result['domainPreserved'] = AppointmentException::fromThrowable($domain)->getMessage() === $domain->getMessage();

    $_GET = [];
    $_REQUEST = [];
    PrivateResponses::protectRequest();
    $result['publicCacheUntouched'] = $GLOBALS['headers'] === [] && !defined('DONOTCACHEPAGE');
    $tokenHeaders = [];
    foreach (['rrze_appt_confirm', 'rrze_appt_cancel', 'rrze_appt_waitlist_optout', 'rrze_appt_opening', 'rrze_appt_opening_registered'] as $parameter) {
        $_GET = [$parameter => ['malformed-token']];
        $GLOBALS['headers'] = [];
        PrivateResponses::protectRequest();
        $tokenHeaders[] = $GLOBALS['headers'];
    }
    $result['tokenHeaders'] = $tokenHeaders;
    $result['cachePluginFlag'] = DONOTCACHEPAGE;
    $result['adminHeaders'] = [];
    foreach (['rrze-appointment-bookings', 'rrze-appointment-settings'] as $page) {
        $_GET = ['page' => $page];
        $GLOBALS['headers'] = [];
        PrivateResponses::protectRequest();
        $result['adminHeaders'][] = $GLOBALS['headers'];
    }
    $_GET = [];
    foreach (['rrze_appointment_backend_sso', 'rrze_appointment_access'] as $action) {
        $_REQUEST = ['action' => $action];
        $GLOBALS['headers'] = [];
        PrivateResponses::protectRequest();
        $result['adminHeaders'][] = $GLOBALS['headers'];
    }
    $_GET = [];
    $_REQUEST = ['action' => 'rrze_appointment_book'];
    $GLOBALS['ajax'] = true;
    $GLOBALS['headers'] = [];
    PrivateResponses::protectRequest();
    $result['ajaxHeaders'] = $GLOBALS['headers'];
    $rest = [];
    foreach (['/rrze/v2/appointment/bookings', '/rrze/v2/appointment/bookings/cancel', '/rrze/v2/appointment/booker', '/RRZE/v2/Appointment/BOOKINGS/', '/unrelated/v1/public'] as $route) {
        foreach ([200, 401, 403, 500, 503] as $status) {
            $response = new class($status) {
                public array $headers = [];
                /** Retains the status as supplied by the REST pipeline. */
                public function __construct(public int $status) {}
                /** Implements the REST response header API. */
                public function header($name, $value): void { $this->headers[$name] = $value; }
            };
            $request = new class($route) {
                /** Captures the already resolved REST route. */
                public function __construct(private string $route) {}
                /** Supplies the routed path, independent of URL/query parsing. */
                public function get_route(): string { return $this->route; }
            };
            $protected = PrivateResponses::protectRestResponse($response, null, $request);
            $rest[] = ['route' => $route, 'status' => $protected->status, 'headers' => $protected->headers];
        }
    }
    $result['rest'] = $rest;
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
