<?php

use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Controller\AvailabilityController;

if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__);
require __DIR__ . '/legacy-booking-storage.php';
foreach (['Booking/Bookings', 'Booking/TokenManager', 'Booking/BookingWindow', 'Booking/SlotGenerator',
    'Booking/AppointmentBlock', 'Booking/MonthlyAvailability', 'Controller/AvailabilityController'] as $file) {
    require dirname(__DIR__, 2) . '/includes/' . $file . '.php';
}

/** Minimal published post for the WordPress block lookup. */
class WP_Post {
    public int $ID = 7;
    public string $post_type = 'page';
    public string $post_status = 'publish';
    public string $post_content;
}
/** Registry without overrides for the fixture's explicit attributes. */
class WP_Block_Type_Registry {
    /** Returns the test registry. */
    public static function get_instance(): self { return new self(); }
    /** The fixture already supplies the saved block values. */
    public function get_registered($name) { return null; }
}

/** Returns the requested fixture post. */
function get_post($id) { return $id === 7 ? $GLOBALS['post'] : null; }
/** Models public visibility independently of the post-password check. */
function is_post_publicly_viewable($post) { return $post->post_status === 'publish'; }
/** Only ordinary pages in the fixture have a frontend. */
function is_post_type_viewable($type) { return $type === 'page'; }
/** Models a logged-in user independently of their rights on the target page. */
function is_user_logged_in() { return $GLOBALS['mode'] !== 'private-guest'; }
/** Grants only object-specific read access, including a case with a pending password prompt. */
function current_user_can($cap, $id) {
    return $cap === 'read_post' && $id === 7 && in_array($GLOBALS['mode'], ['private-reader', 'private-password'], true);
}
/** Models the visitor's accepted or outstanding WordPress password prompt. */
function post_password_required($post) { return in_array($GLOBALS['mode'], ['need-password', 'private-password'], true); }
/** Checks the controller's use of the public booking nonce. */
function check_ajax_referer($action, $field) {
    if ($action !== 'rrze_appointment_book' || $field !== 'nonce') {
        throw new LogicException('Wrong nonce context');
    }
}
/** Reads the fixture's nested parsed block representation. */
function parse_blocks($content) { return json_decode($content, true); }
/** Encodes the signed block selector. */
function wp_json_encode($value) { return json_encode($value); }
/** Supplies a deterministic test salt. */
function wp_salt($scheme) { return 'month-test'; }
/** Supplies the timezone used to publish the times. */
function wp_timezone() { return new DateTimeZone('Europe/Berlin'); }
/** Freezes time one hour before the opening slot. */
function current_datetime() { return new DateTimeImmutable('2027-01-11 08:00:00', wp_timezone()); }
/** Returns the fixed absolute clock used by the generator. */
function current_time($format, $gmt = false) { return current_datetime()->getTimestamp(); }
/** Supplies one booked time and one unexpired pending reservation. */
function get_option($name, $default) {
    if ($name === Bookings::SLOTS_OPTION) {
        if ($GLOBALS['mode'] === 'legacy-booked') {
            return ['2027-01-11 16:00-16:30'];
        }
        return ['2027-01-11 09:30-10:00'];
    }
    if ($name === TokenManager::PENDING_OPTION) {
        $slot = $GLOBALS['mode'] === 'legacy-pending' ? '2027-01-11 16:00-16:30' : '2027-01-11 10:00-10:30';
        return [['slot' => $slot, 'meta' => [], 'expires' => time() + 1800]];
    }
    return $default;
}
/** Ends the simulated AJAX request with its error result. */
function wp_send_json_error($data, $status) {
    echo json_encode(compact('status', 'data'));
    exit;
}
/** Ends the simulated AJAX request with its success result. */
function wp_send_json_success($data) {
    echo json_encode(['status' => 200, 'data' => $data]);
    exit;
}

$mode = $argv[1];
$attributes = isset($argv[2]) ? json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR)
    : ['selectedDates' => ['2027-01-11', '2027-01-16', '2027-02-02'], 'startTime' => '09:00', 'endTime' => '11:00',
    'duration' => 30, 'bookingCutoff' => 60, 'hideWeekends' => true, 'showSlotsAsList' => $mode === 'list'];
$post = new WP_Post();
$post->post_content = json_encode([['blockName' => 'core/group', 'innerBlocks' => [
    ['blockName' => 'rrze/appointment', 'attrs' => $attributes],
]]]);
$post->post_status = $mode === 'unpublished' ? 'draft' : 'publish';
if (str_starts_with($mode, 'private-')) {
    $post->post_status = 'private';
}
$_POST = [
    'post_id' => '7',
    'block_id' => $mode === 'stale-block' ? str_repeat('a', 64) : AppointmentBlock::fingerprint($attributes),
    'month' => $mode === 'invalid-month' ? '2027-13' : '2027-01',
];
AvailabilityController::handleRequest();
