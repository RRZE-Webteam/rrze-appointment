<?php

// Run the real booking query and admin renderer against isolated test data.
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$scenario = json_decode(stream_get_contents(STDIN), true) ?: [];
$GLOBALS['scenario'] = $scenario;
$GLOBALS['writes'] = 0;
$GLOBALS['cleared_hooks'] = [];
$GLOBALS['routes'] = [];
$GLOBALS['options'] = [
    'date_format' => 'd.m.Y',
    'rrze_appointment_settings' => [
        'retention_days' => $scenario['retention'] ?? 30,
        'sensitive_mode_enabled' => $scenario['sensitive'] ?? false,
        'cancellation_reason_enabled' => $scenario['showReason'] ?? true,
        'appointment_manager_user_ids' => $scenario['allowedUsers'] ?? [7],
    ],
    'rrze_appointment_booked_slots_meta' => [
        '2026-08-23 10:00-10:30' => ['title' => 'Future appointment', 'person_id' => 2],
        '2026-07-21 11:30-12:00' => ['title' => 'Expired appointment', 'person_id' => 1],
        '2026-08-19 09:00-09:30' => [
            'title' => 'Recent appointment', 'person_id' => 1,
            'booker_name' => 'Alice Example', 'booker_email' => 'alice@example.test',
        ],
        '2026-08-20 12:30-13:00' => [
            'title' => 'Upcoming appointment', 'person_id' => 2,
            'person_email' => 'host@example.test', 'booker_email' => 'upcoming@example.test',
        ],
        '2026-08-19 10:00-10:30' => [
            'title' => 'Private topic', 'person_id' => 3, 'person_name' => 'Private host',
            'booker_name' => 'Private booker', 'booker_email' => 'private@example.test',
            'admin_anonymized' => true,
        ],
        '2026-08-20 11:30-12:00' => ['title' => 'Just ended appointment', 'person_id' => 2],
        '2026-07-21 11:31-12:01' => ['title' => 'Still retained appointment', 'person_id' => 1],
        '2026-08-20 11:45-12:15' => ['title' => 'Ongoing appointment', 'person_id' => 1],
    ],
];
$GLOBALS['options']['rrze_appointment_booked_slots'] = array_keys(
    $GLOBALS['options']['rrze_appointment_booked_slots_meta']
);

function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    ++$GLOBALS['writes'];
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option($key) {
    ++$GLOBALS['writes'];
    unset($GLOBALS['options'][$key]);
    return true;
}
function wp_timezone() { return new DateTimeZone($GLOBALS['scenario']['timezone'] ?? 'Europe/Berlin'); }
function current_datetime() { return new DateTimeImmutable($GLOBALS['scenario']['now'] ?? '2026-08-20 12:00:00', wp_timezone()); }
function current_time($type) {
    $now = current_datetime();
    return $type === 'timestamp' ? $now->getTimestamp() + $now->getOffset() : $now->format('Y-m-d');
}
function get_post_meta($postId, $key, $single) { return ''; }
function get_the_title($postId) { return 'Host ' . $postId; }
function current_user_can($capability) { return $GLOBALS['scenario']['canManage'] ?? true; }
function get_current_user_id() { return $GLOBALS['scenario']['userId'] ?? 0; }
function get_current_blog_id() { return 1; }
function is_user_member_of_blog($userId, $blogId) { return $GLOBALS['scenario']['member'] ?? true; }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function __($text, $domain) { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function esc_url($url) { return esc_attr($url); }
function esc_html__($text, $domain) { return esc_html($text); }
function esc_html_e($text, $domain) { echo esc_html($text); }
function esc_attr_e($text, $domain) { echo esc_attr($text); }
function selected($current, $value) { if ((string) $current === (string) $value) { echo ' selected="selected"'; } }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) {
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);
    return $parts['scheme'] . '://' . $parts['host'] . $parts['path'] . '?' . http_build_query(array_merge($query, $args));
}
function date_i18n($format, $timestamp) { return gmdate($format, $timestamp); }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">'; }
function check_admin_referer($action, $name) { return 1; }
function wp_clear_scheduled_hook($hook, $args) {
    $GLOBALS['cleared_hooks'][] = [$hook, $args];
}
function wp_redirect($url) { throw new RuntimeException('Unexpected redirect.'); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function home_url($path = '') { return 'https://example.test' . $path; }
function esc_url_raw($value) { return $value; }
function wp_verify_nonce($nonce, $action) { return $action === 'wp_rest' && $nonce === 'test-nonce'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['routes'][] = compact('namespace', 'route', 'args'); }
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Response {
    public function __construct(public array $data, public int $status, public array $headers) {}
}
class WP_REST_Request {
    public function __construct(private array $params, private string $nonce) {}
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function get_header($key) { return $key === 'X-WP-Nonce' ? $this->nonce : null; }
}

$root = dirname(__DIR__, 2);
require $root . '/includes/AppointmentException.php';
require $root . '/includes/Configuration/PluginSettings.php';
require $root . '/includes/Booking/Bookings.php';
require $root . '/includes/Admin/Request.php';
require $root . '/includes/Admin/AppointmentPermissions.php';
require $root . '/includes/Admin/BookingsPage.php';
require $root . '/includes/Controller/AdminBookingsController.php';
require __DIR__ . '/booking-mail-stubs.php';

$_GET = $scenario['query'] ?? [];
$_POST = $scenario['post'] ?? [];
$page = new \RRZE\Appointment\Admin\BookingsPage();
$controller = new \RRZE\Appointment\Controller\AdminBookingsController();
$controller->registerRoutes();
$request = new WP_REST_Request($scenario['params'] ?? [], $scenario['nonce'] ?? 'test-nonce');
$api = !empty($scenario['cancel']) ? $controller->cancelBooking($request) : $controller->getBookings($request);
ob_start();
$page->render();
$html = ob_get_clean();
echo json_encode([
    'html' => $html,
    'bookings' => \RRZE\Appointment\Booking\Bookings::getAll($scenario['filter'] ?? []),
    'storedSlots' => get_option('rrze_appointment_booked_slots'),
    'writes' => $GLOBALS['writes'],
    'api' => $api,
    'mails' => \RRZE\Appointment\Mail\Mailer::$mails,
    'clearedHooks' => $GLOBALS['cleared_hooks'],
    'routes' => array_map(static fn($route) => [
        'route' => $route['route'], 'methods' => $route['args']['methods'],
        'permission' => $route['args']['permission_callback'][1],
        'args' => $route['args']['args'],
    ], $GLOBALS['routes']),
]);
