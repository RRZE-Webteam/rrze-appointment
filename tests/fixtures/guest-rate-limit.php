<?php

define('ABSPATH', __DIR__);
require __DIR__ . '/guest-rate-limit-storage.php';
require dirname(__DIR__, 2) . '/includes/Booking/GuestRequestLimiter.php';

use RRZE\Appointment\Booking\GuestRequestLimiter;

$scenario = json_decode(stream_get_contents(STDIN), true);
$wpdb = new RateLimitTestDatabase();
$rateLimits = ['ip' => 0, 'email' => $scenario['limit'] ?? 1];
$email = 'booker@example.org';
$results = [];
$intermediate = [];

// Interleave a successful competing SQL write after our read, before our write.
$race = static function (RateLimitTestDatabase $db, string $query): void { $db->query($query); };
switch ($scenario['operation']) {
    case 'insertRace':
        $wpdb->beforeWrite = $race;
        break;
    case 'updateRace':
        $results[] = GuestRequestLimiter::consume($email);
        $wpdb->beforeWrite = $race;
        break;
    case 'contention':
        $rateLimits['email'] = 100;
        $results[] = GuestRequestLimiter::consume($email);
        $compete = static function (RateLimitTestDatabase $db, string $query) use (&$compete): void {
            $db->query($query);
            $db->beforeWrite = $compete;
        };
        $wpdb->beforeWrite = $compete;
        break;
    case 'readFailure':
        $wpdb->failReads = true;
        break;
    case 'writeFailure':
        $wpdb->failWrites = true;
        break;
    case 'corrupt':
        GuestRequestLimiter::consume($email);
        $wpdb->db->exec("UPDATE wp_options SET option_value = 'corrupted'");
        break;
    case 'cleanup':
        GuestRequestLimiter::consume($email);
        $wpdb->db->exec("INSERT INTO wp_options VALUES ('rrzeXapptXguestXrateXunrelated', '1:1', 'no')");
        $testNow = 1899;
        GuestRequestLimiter::cleanup();
        $intermediate = $wpdb->rows();
        $testNow = 1900;
        GuestRequestLimiter::cleanup();
        echo json_encode(['intermediate' => $intermediate, 'rows' => $wpdb->rows()]);
        exit;
    case 'defaultIpLimit':
        $rateLimits = null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        for ($i = 0; $i < 301; $i++) {
            $results[] = GuestRequestLimiter::consume('user' . $i . '@example.org');
        }
        echo json_encode(['results' => $results, 'rows' => $wpdb->rows()]);
        exit;
}
$results[] = GuestRequestLimiter::consume($email);
echo json_encode(['results' => $results, 'rows' => $wpdb->rows()]);
