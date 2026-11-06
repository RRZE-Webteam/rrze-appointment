<?php
/**
 * Test-only instrumentation, copied exclusively into the disposable audit site.
 * Captures real PHPMailer MIME output; no email leaves the local installation.
 */

if (!defined('RRZE_APPOINTMENT_AUDIT_ROOT') || !str_starts_with(DB_NAME, 'rrze_appt_audit_')) {
    exit('Audit instrumentation requires a disposable database.');
}

// Keep browser subresources local as well as WordPress's server-side HTTP calls.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'");
}

/**
 * Record per-request resource use without query strings, cookies or payloads.
 *
 * @return void
 */
function appointmentAuditRequestMetrics(): void
{
    $row = ['path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
        'peak_bytes' => memory_get_peak_usage(true), 'status' => http_response_code(),
        'memory_limit' => ini_get('memory_limit'), 'execution_limit' => ini_get('max_execution_time')];
    file_put_contents(RRZE_APPOINTMENT_AUDIT_ROOT . '/request-metrics.jsonl', json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
}
if (PHP_SAPI === 'cli-server') {
    register_shutdown_function('appointmentAuditRequestMetrics');
}

require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';

/** Captures complete prepared messages after PHPMailer has built their MIME parts. */
final class AppointmentAuditMailer extends WP_PHPMailer
{
    /**
     * Save synthetic MIME messages instead of invoking a real delivery transport.
     *
     * @return bool Whether the controlled test transport accepted the message.
     */
    public function postSend(): bool
    {
        $root = RRZE_APPOINTMENT_AUDIT_ROOT;
        if (is_file($root . '/mail-delay')) {
            file_put_contents($root . '/mail-entered', 'ready');
            usleep((int) ((float) file_get_contents($root . '/mail-delay') * 1000000));
        }
        if (is_file($root . '/mail-fail')) {
            return false;
        }
        $file = $root . '/mail/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(4)) . '.eml';
        file_put_contents($file, $this->getSentMIMEMessage());
        chmod($file, 0600);
        return true;
    }
}
$GLOBALS['phpmailer'] = new AppointmentAuditMailer(true);

/**
 * Supply a valid sender address even when the test host is a loopback IP.
 *
 * @param string $address Original WordPress sender address.
 * @return string Synthetic sender used only by the local mail sink.
 */
function appointmentAuditFrom(string $address): string
{
    return 'wordpress@example.test';
}
add_filter('wp_mail_from', 'appointmentAuditFrom');

/**
 * Disable canonical redirects in the path-based loopback test installation.
 *
 * @param string|false $redirect Suggested canonical destination.
 * @return false Keep the original local request URL.
 */
function appointmentAuditNoRedirect($redirect)
{
    return false;
}
add_filter('redirect_canonical', 'appointmentAuditNoRedirect');

/**
 * Intercept only explicitly armed database writes in the disposable test database.
 *
 * @param string $query SQL produced by the real plugin and WordPress core.
 * @return string Original query, or a deliberately failing synthetic query.
 */
function appointmentAuditQuery(string $query): string
{
    $file = RRZE_APPOINTMENT_AUDIT_ROOT . '/fault.json';
    if (!is_file($file)) {
        return $query;
    }
    $fault = json_decode(file_get_contents($file), true);
    if (!is_array($fault) || !str_contains($query, $fault['match'] ?? "\0")
        || !preg_match('/^\s*(UPDATE|INSERT)/i', $query)) {
        return $query;
    }
    static $hits = 0;
    if (++$hits !== ($fault['hit'] ?? 1)) {
        return $query;
    }
    file_put_contents(RRZE_APPOINTMENT_AUDIT_ROOT . '/fault-fired', $fault['mode']);
    if ($fault['mode'] === 'exit') {
        exit(86);
    }
    if ($fault['mode'] === 'lost-lock') {
        global $wpdb;
        mysqli_query($wpdb->dbh, 'SELECT RELEASE_ALL_LOCKS()');
        return $query;
    }
    return 'UPDATE rrze_audit_intentionally_missing_table SET value = 1';
}
add_filter('query', 'appointmentAuditQuery');

/**
 * Pause an old version's real cleanup immediately before WordPress writes it.
 * This hook is armed only by a local fixture file and one explicit test request.
 *
 * @param mixed $value Updated pending option prepared by the legacy plugin.
 * @return mixed Unchanged value, written by the original WordPress caller.
 */
function appointmentAuditHoldLegacyCleanup($value)
{
    $root = RRZE_APPOINTMENT_AUDIT_ROOT;
    if (empty($_GET['audit_hold_legacy']) || !is_file($root . '/legacy-arm')) {
        return $value;
    }
    file_put_contents($root . '/legacy-ready', 'ready');
    $deadline = microtime(true) + 35;
    while (!is_file($root . '/legacy-release') && microtime(true) < $deadline) {
        usleep(20000);
        clearstatcache();
    }
    return $value;
}
add_filter('pre_update_option_rrze_appointment_pending_slots', 'appointmentAuditHoldLegacyCleanup');
