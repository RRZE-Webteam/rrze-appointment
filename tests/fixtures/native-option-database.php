<?php

/**
 * Optional native MySQL/MariaDB smoke test, run with wp eval-file and plugins skipped.
 *
 * Uses synthetic strings, a connection-local temporary table and a second database
 * connection. It never reads appointment options or the configured encryption key.
 */

use RRZE\Appointment\AppointmentException;
use RRZE\Appointment\Storage\OptionDatabase;

if (!defined('WP_CLI') || !WP_CLI) {
    exit('Run with wp --skip-plugins --skip-themes eval-file.');
}
require_once dirname(__DIR__, 2) . '/includes/AppointmentException.php';
require_once dirname(__DIR__, 2) . '/includes/Storage/OptionDatabase.php';

/** Asserts native SQL behavior without printing stored values or credentials. */
function assertAppointmentDatabase(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;
$table = 'rrze_appt_storage_test_tmp';
$store = null;
$contender = null;
$second = null;
try {
    assertAppointmentDatabase($wpdb->query($wpdb->prepare('CREATE TEMPORARY TABLE %i LIKE %i', $table, $wpdb->options)) !== false, 'Temporary table creation failed.');
    $store = new OptionDatabase($wpdb, $table);
    assertAppointmentDatabase($store->read('synthetic') === null, 'Missing-row detection failed.');
    assertAppointmentDatabase($store->replace('synthetic', null, 'synthetic-v1'), 'Insert failed.');
    assertAppointmentDatabase($store->replace('synthetic', 'synthetic-v1', 'synthetic-v2'), 'Conditional update failed.');
    assertAppointmentDatabase(!$store->replace('synthetic', 'synthetic-v1', 'stale'), 'Stale update was accepted.');
    assertAppointmentDatabase($store->read('synthetic') === 'synthetic-v2', 'Concurrent value was lost.');
    assertAppointmentDatabase($store->replace('synthetic', 'synthetic-v2', 'synthetic-v2'), 'No-op update failed.');
    $second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $blocked = false;
    try {
        $contender = new OptionDatabase($second, $table);
    } catch (AppointmentException) {
        $blocked = true;
    }
    assertAppointmentDatabase($blocked, 'Another connection acquired the held lock.');
    $store->release();
    $contender = new OptionDatabase($second, $table);
    $contender->assertOwnership();
    $contender->release();
    WP_CLI::success('Native conditional writes, stale-write rejection and two-connection locking passed. No booking data were accessed.');
} finally {
    $store?->release();
    $contender?->release();
    $second?->close();
    $wpdb->query($wpdb->prepare('DROP TEMPORARY TABLE IF EXISTS %i', $table));
}
