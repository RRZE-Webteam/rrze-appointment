<?php
/**
 * WP-CLI scenarios for the disposable, real-WordPress readiness audit.
 * All booking records, addresses and action tokens are synthetic.
 */

use RRZE\Appointment\Booking\AppointmentBlock;
use RRZE\Appointment\Booking\Bookings;
use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Notification\BookingOpeningNotifier;
use RRZE\Appointment\Notification\Reminder;
use RRZE\Appointment\Storage\BookingStorage;
use RRZE\Appointment\Storage\NetworkMigration;

if (!defined('WP_CLI') || !WP_CLI || !defined('RRZE_APPOINTMENT_AUDIT_ROOT')
    || !str_starts_with(DB_NAME, 'rrze_appt_audit_')) {
    exit('This fixture requires the isolated audit installation.');
}

/**
 * Return the five storage names without requiring the new storage class.
 *
 * @return list<string> Known legacy option names.
 */
function appointmentAuditNames(): array
{
    return [
        'rrze_appointment_booked_slots', 'rrze_appointment_booked_slots_meta',
        'rrze_appointment_pending_slots', 'rrze_appointment_cancel_tokens',
        'rrze_appointment_booking_opening_notifications',
    ];
}

/**
 * Read the real storage boundary in either the baseline or candidate version.
 *
 * @param string $name One of the known booking option names.
 * @return array<mixed> Stored logical booking data.
 */
function appointmentAuditRead(string $name): array
{
    return class_exists(BookingStorage::class) ? BookingStorage::get($name) : get_option($name, []);
}

/**
 * Write synthetic scenario data through the version's actual storage boundary.
 *
 * @param string $name One of the known booking option names.
 * @param array<mixed> $value Synthetic logical data to persist.
 * @return void
 */
function appointmentAuditWrite(string $name, array $value): void
{
    if (class_exists(BookingStorage::class)) {
        BookingStorage::set($name, $value);
    } else {
        update_option($name, $value, false);
    }
}

/**
 * Collect logical counts and hashes, never personal data or encryption keys.
 *
 * @return array<string, mixed> Logical, ciphertext and cron summary.
 */
function appointmentAuditSummary(): array
{
    global $wpdb;
    $result = ['stores' => [], 'cron' => [], 'errors' => []];
    foreach (appointmentAuditNames() as $name) {
        try {
            $data = appointmentAuditRead($name);
            $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));
            $result['stores'][$name] = [
                'count' => count($data), 'digest' => hash('sha256', serialize($data)),
                'raw_digest' => hash('sha256', (string) $raw), 'bytes' => strlen((string) $raw),
                'encrypted' => str_starts_with((string) $raw, 'rrze-appointment:encrypted:'),
            ];
        } catch (Throwable $error) {
            $result['errors'][] = $name;
        }
    }
    $rawState = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'rrze_appointment_storage_state'));
    $state = json_decode((string) $rawState, true);
    $result['phase'] = $state['phase'] ?? 'legacy';
    $result['completed'] = count($state['completed'] ?? []);
    $result['state_digest'] = hash('sha256', (string) $rawState);
    foreach (_get_cron_array() as $time => $hooks) {
        foreach ($hooks as $hook => $events) {
            foreach ($events as $event) {
                if (str_starts_with($hook, 'rrze_appointment_') || $hook === 'audit_foreign_event') {
                    $result['cron'][] = ['time' => $time, 'hook' => $hook,
                        'opaque' => isset($event['args'][0]) && is_string($event['args'][0]) && str_starts_with($event['args'][0], 'rrze-appt-job:v1:'),
                        'args_digest' => hash('sha256', serialize($event['args']))];
                }
            }
        }
    }
    $result['peak_memory'] = memory_get_peak_usage(true);
    return $result;
}

/**
 * Wait for a bounded file barrier so independent PHP processes overlap reliably.
 *
 * @param string $name Local barrier prefix owned by this scenario.
 * @return void
 * @throws RuntimeException When the controller does not release within 40 seconds.
 */
function appointmentAuditBarrier(string $name): void
{
    $root = RRZE_APPOINTMENT_AUDIT_ROOT;
    file_put_contents($root . '/' . $name . '-ready', 'ready');
    $deadline = microtime(true) + 40;
    while (!is_file($root . '/' . $name . '-release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Audit barrier timed out.');
        }
        usleep(20000);
        clearstatcache();
    }
}

$mode = $args[0] ?? 'summary';
$result = [];
if ($mode === 'seed') {
    update_option('timezone_string', 'Europe/Berlin');
    update_option('permalink_structure', '/%postname%/');
    update_option('rrze_appointment_settings', ['reminder_booker_days' => 1, 'reminder_host_days' => 1, 'retention_days' => 30]);
    $date = current_datetime()->modify('+1 day')->format('Y-m-d');
    $later = current_datetime()->modify('+2 days')->format('Y-m-d');
    $attributes = [
        'title' => 'Synthetic migration audit', 'selectedDates' => [$date, $later],
        'startTime' => '09:00', 'endTime' => '17:00', 'duration' => 30,
        'disableSso' => true, 'personName' => 'Synthetic Host', 'personEmail' => 'host@example.test',
        'location' => 'Local test room', 'showSlotsAsList' => false,
    ];
    $post = wp_insert_post(['post_title' => 'Audit appointments', 'post_name' => 'audit-appointments',
        'post_status' => 'publish', 'post_type' => 'page',
        'post_content' => '<!-- wp:rrze/appointment ' . wp_json_encode($attributes) . ' /-->']);
    $attributes['showSlotsAsList'] = true;
    $listPost = wp_insert_post(['post_title' => 'Audit list', 'post_name' => 'audit-list',
        'post_status' => 'publish', 'post_type' => 'page',
        'post_content' => '<!-- wp:rrze/appointment ' . wp_json_encode($attributes) . ' /-->']);
    $attributes['showSlotsAsList'] = false;
    $slots = ['booked' => "$date 09:00-09:30", 'cancel' => "$date 09:30-10:00",
        'optout' => "$date 10:00-10:30", 'confirm' => "$date 10:30-11:00",
        'new' => "$date 11:00-11:30", 'parallel' => "$date 11:30-12:00",
        'opening' => "$later 12:00-12:30"];
    $base = ['title' => 'Synthetic migration audit', 'person_name' => 'Synthetic Host',
        'person_email' => 'host@example.test', 'booker_name' => 'Synthetic Booker',
        'booker_email' => 'booker@example.test', 'booker_waitlist' => true,
        'location' => 'Local test room', 'post_link' => get_permalink($post), 'tpl_id' => 0];
    $tokens = [];
    foreach (['booked', 'cancel', 'optout', 'confirm'] as $name) {
        $tokens[$name] = TokenManager::createPending($slots[$name], $base);
    }
    $opening = BookingOpeningNotifier::subscribe($slots['opening'], $base, time() + 600, time() + 86400);
    wp_schedule_single_event(time() + 43200, 'audit_foreign_event', ['synthetic-unrelated-argument']);
    $result = ['post_id' => $post, 'list_post_id' => $listPost, 'post_url' => get_permalink($post),
        'list_url' => get_permalink($listPost), 'block_id' => AppointmentBlock::fingerprint($attributes),
        'slots' => $slots, 'tokens' => $tokens, 'opening' => $opening];
    update_option('rrze_audit_scenario', $result, false);
    flush_rewrite_rules(false);
} elseif ($mode === 'context') {
    $result = get_option('rrze_audit_scenario');
    $result['nonce'] = wp_create_nonce('rrze_appointment_book');
    $result['cancel_urls'] = [];
    $result['optout_urls'] = [];
    foreach (['booked', 'cancel', 'optout'] as $name) {
        $result['cancel_urls'][$name] = TokenManager::getCancelUrlForSlot($result['slots'][$name]);
        $result['optout_urls'][$name] = TokenManager::getWaitlistOptOutUrlForSlot($result['slots'][$name]);
    }
} elseif ($mode === 'summary') {
    $result = appointmentAuditSummary();
} elseif ($mode === 'site-summary') {
    $site = (int) ($args[1] ?? 2);
    if (!in_array($site, [1, 2, 3, 4, 5], true)) {
        throw new RuntimeException('Unknown synthetic audit site.');
    }
    if (class_exists(BookingStorage::class)) {
        BookingStorage::release();
    }
    switch_to_blog($site);
    try {
        $result = appointmentAuditSummary();
    } finally {
        if (class_exists(BookingStorage::class)) {
            BookingStorage::release();
        }
        restore_current_blog();
    }
} elseif ($mode === 'environment') {
    global $wp_version, $wpdb;
    $result = ['php' => PHP_VERSION, 'wordpress' => $wp_version, 'mysql' => $wpdb->db_version(),
        'memory_limit' => ini_get('memory_limit'), 'execution_limit' => ini_get('max_execution_time'),
        'sodium' => extension_loaded('sodium'), 'object_cache' => wp_using_ext_object_cache(),
        'timezone' => wp_timezone_string(), 'theme' => get_stylesheet(), 'sites' => get_sites(['fields' => 'ids']),
        'active_plugins' => array_keys(get_site_option('active_sitewide_plugins', []))];
} elseif ($mode === 'confirmation-fault-install') {
    global $wpdb;
    $wpdb->query('DROP TRIGGER IF EXISTS rrze_audit_confirmation_fault');
    $wpdb->query('DROP TABLE IF EXISTS rrze_audit_fault_hits');
    $wpdb->query('CREATE TABLE rrze_audit_fault_hits (hit INT) ENGINE=MyISAM');
    $installed = $wpdb->query("CREATE TRIGGER rrze_audit_confirmation_fault BEFORE UPDATE ON wp_2_options FOR EACH ROW BEGIN IF NEW.option_name='rrze_appointment_booked_slots' THEN INSERT INTO rrze_audit_fault_hits VALUES (1); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic confirmation failure'; END IF; END");
    $result = ['installed' => $installed !== false];
} elseif ($mode === 'confirmation-fault-check') {
    global $wpdb;
    $result = ['fired' => (int) $wpdb->get_var('SELECT COUNT(*) FROM rrze_audit_fault_hits')];
} elseif ($mode === 'confirmation-fault-remove') {
    global $wpdb;
    $wpdb->query('DROP TRIGGER IF EXISTS rrze_audit_confirmation_fault');
    $wpdb->query('DROP TABLE IF EXISTS rrze_audit_fault_hits');
    $result = ['removed' => true];
} elseif ($mode === 'hold-cron') {
    $cron = _get_cron_array();
    appointmentAuditBarrier('cron');
    $time = time() + 45000;
    $cron[$time]['audit_foreign_event'][md5(serialize(['late-foreign-write']))] = ['schedule' => false, 'args' => ['late-foreign-write']];
    $result = ['saved' => _set_cron_array($cron)];
} elseif ($mode === 'late-cron-event') {
    $arguments = ['created-after-migration'];
    wp_schedule_single_event(time() + 47000, 'audit_foreign_event', $arguments);
    $result = ['args_digest' => hash('sha256', serialize($arguments))];
} elseif ($mode === 'hold-lock') {
    BookingStorage::get(Bookings::SLOTS_OPTION);
    appointmentAuditBarrier('lock');
    BookingStorage::release();
    $result = ['released' => true];
} elseif ($mode === 'add') {
    $slots = appointmentAuditRead(Bookings::SLOTS_OPTION);
    $slots[] = '2099-12-31 ' . ($args[1] ?? 'synthetic');
    usleep(100000);
    appointmentAuditWrite(Bookings::SLOTS_OPTION, $slots);
    $result = ['written' => true];
} elseif ($mode === 'grow') {
    $count = (int) ($args[1] ?? 1000);
    $slots = appointmentAuditRead(Bookings::SLOTS_OPTION);
    $metadata = appointmentAuditRead(Bookings::META_OPTION);
    for ($i = 0; $i < $count; $i++) {
        $slot = (new DateTimeImmutable('2090-01-01', wp_timezone()))->modify('+' . $i . ' days')->format('Y-m-d') . ' 14:00-14:30';
        $slots[] = $slot;
        $metadata[$slot] = ['booker_name' => 'Synthetic ' . $i, 'booker_email' => 'synthetic-' . $i . '@example.test', 'notes' => str_repeat('synthetic-data-', 32)];
    }
    appointmentAuditWrite(Bookings::SLOTS_OPTION, $slots);
    appointmentAuditWrite(Bookings::META_OPTION, $metadata);
    $result = appointmentAuditSummary();
} elseif ($mode === 'dispatch') {
    $hook = $args[1];
    $events = _get_cron_array();
    $dispatched = 0;
    foreach ($events as $time => $hooks) {
        foreach ($hooks[$hook] ?? [] as $event) {
            wp_unschedule_event($time, $hook, $event['args']);
            do_action_ref_array($hook, $event['args']);
            $dispatched++;
        }
    }
    $result = ['dispatched' => $dispatched];
} elseif ($mode === 'daily') {
    do_action('rrze_appointment_daily_check');
    $result = appointmentAuditSummary();
} elseif ($mode === 'prepare-cron') {
    foreach (array_keys(appointmentAuditRead(Bookings::META_OPTION)) as $slot) {
        wp_schedule_single_event(time() + 3600, Reminder::CRON_HOOK, [$slot]);
    }
    $result = appointmentAuditSummary();
} elseif ($mode === 'grow-cron') {
    $cron = _get_cron_array();
    $index = 0;
    foreach (array_keys(appointmentAuditRead(Bookings::META_OPTION)) as $slot) {
        $arguments = [$slot];
        $cron[time() + 86400 + $index++][Reminder::CRON_HOOK][md5(serialize($arguments))] =
            ['schedule' => false, 'args' => $arguments];
    }
    _set_cron_array($cron);
    $result = ['jobs_added' => $index];
} elseif ($mode === 'pending-token') {
    $result = ['token' => null];
    foreach (appointmentAuditRead(TokenManager::PENDING_OPTION) as $token => $entry) {
        if ($entry['slot'] === $args[1]) {
            $result['token'] = $token;
        }
    }
} elseif ($mode === 'fresh-pending') {
    $scenario = get_option('rrze_audit_scenario');
    $metadata = appointmentAuditRead(Bookings::META_OPTION);
    $meta = reset($metadata);
    foreach (array_keys(appointmentAuditRead(TokenManager::PENDING_OPTION)) as $token) {
        TokenManager::deletePending($token);
    }
    $scenario['tokens']['confirm'] = TokenManager::createPending($scenario['slots']['confirm'], $meta);
    update_option('rrze_audit_scenario', $scenario, false);
    $result = $scenario;
} elseif ($mode === 'logical-slots') {
    $result = ['booked' => appointmentAuditRead(Bookings::SLOTS_OPTION),
        'pending' => array_column(appointmentAuditRead(TokenManager::PENDING_OPTION), 'slot')];
} elseif ($mode === 'expire-current') {
    $pending = appointmentAuditRead(TokenManager::PENDING_OPTION);
    foreach ($pending as &$entry) {
        $entry['expires'] = time() - 1;
    }
    unset($entry);
    appointmentAuditWrite(TokenManager::PENDING_OPTION, $pending);
    $result = ['expired' => count($pending)];
} elseif ($mode === 'opening-ready') {
    $entries = appointmentAuditRead(BookingOpeningNotifier::OPTION);
    foreach ($entries as &$entry) {
        $entry['opens_at'] = time() - 60;
    }
    unset($entry);
    appointmentAuditWrite(BookingOpeningNotifier::OPTION, $entries);
    $result = ['count' => count($entries)];
} elseif ($mode === 'network-seed') {
    $plugin = 'rrze-appointment/rrze-appointment.php';
    update_site_option('active_sitewide_plugins', []);
    update_blog_option(1, 'active_plugins', [$plugin]);
    update_blog_option(2, 'active_plugins', [$plugin]);
    switch_to_blog(3);
    TokenManager::createPending('2099-01-03 11:00-11:30', ['booker_name' => 'Dormant synthetic', 'booker_email' => 'dormant@example.test']);
    restore_current_blog();
    update_blog_option(4, Bookings::META_OPTION, 'deliberately-invalid-synthetic-array');
    $result = ['dormant' => 3, 'broken' => 4, 'empty' => 5];
} elseif ($mode === 'network-retry') {
    $state = get_network_option(1, NetworkMigration::OPTION, []);
    $state['next_batch'] = 0;
    update_network_option(1, NetworkMigration::OPTION, $state);
    NetworkMigration::run();
    $result = get_network_option(1, NetworkMigration::OPTION, []);
} elseif ($mode === 'expire-one') {
    $pending = appointmentAuditRead(TokenManager::PENDING_OPTION);
    $pending['synthetic-expired'] = ['slot' => '2099-01-01 14:00-14:30', 'expires' => time() - 60, 'meta' => []];
    appointmentAuditWrite(TokenManager::PENDING_OPTION, $pending);
    $result = ['prepared' => true];
} elseif ($mode === 'sso-required') {
    update_option('rrze_appointment_backend_sso_required', true, false);
    $result = ['enabled' => true];
} elseif ($mode === 'invalidate-session') {
    WP_Session_Tokens::get_instance(1)->destroy_all();
    $result = ['synthetic_admin_sessions_revoked' => true];
} elseif ($mode === 'network') {
    NetworkMigration::run();
    $result = get_network_option(1, NetworkMigration::OPTION, []);
} elseif ($mode === 'rest') {
    wp_set_current_user(($args[1] ?? '') === 'admin' ? 1 : 0);
    $request = new WP_REST_Request('GET', '/rrze/v2/appointment/bookings');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $response = rest_do_request($request);
    $data = $response->get_data();
    $result = ['status' => $response->get_status(), 'data_type' => gettype($data), 'code' => $data['code'] ?? null];
} else {
    throw new RuntimeException('Unknown audit scenario.');
}
echo wp_json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
