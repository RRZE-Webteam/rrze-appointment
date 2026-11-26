<?php

use RRZE\Appointment\Booking\TokenManager;
use RRZE\Appointment\Storage\StorageMaintenance;

if (PHP_SAPI !== 'cli' || !defined('RRZE_APPOINTMENT_STORAGE_TEST_BOOTSTRAP_ONLY')) {
    exit;
}

/** Enables the multisite maintenance entry point for this fixture. */
function is_multisite(): bool { return true; }

/** Selects the isolated network in the synthetic blogs tables. */
function get_current_network_id(): int { return 1; }

/** Maps the currently selected options table to its synthetic site. */
function get_current_blog_id(): int { return $GLOBALS['wpdb']->options === 'wp_options' ? 1 : 2; }

/** Selects the second site's options table for its real storage preflight. */
function switch_to_blog(int $id): void { $GLOBALS['wpdb']->options = 'wp_' . $id . '_options'; }

/** Returns to the network's main site after a switched check. */
function restore_current_blog(): void { $GLOBALS['wpdb']->options = 'wp_options'; }

/** Computes the plugin and file name used by the network activation check. */
function plugin_basename(string $path): string { return basename(dirname($path)) . '/' . basename($path); }

/** The fixture network uses site-local plugin activation. */
function get_network_option(int $network, string $name, mixed $default): mixed { return $default; }

/** Marks only the main site active while the second site retains a legacy booking option. */
function get_blog_option(int $site, string $name, mixed $default): mixed
{
    return $name === 'active_plugins' && $site === 1
        ? [plugin_basename(dirname(__DIR__, 2) . '/rrze-appointment.php')]
        : $default;
}

define('RRZE_APPOINTMENT_ENCRYPTION_KEY', base64_encode(str_repeat('n', 32)));
foreach ([$wpdb->primary, $wpdb->replica] as $database) {
    $database->db->exec('CREATE TABLE wp_blogs (blog_id INTEGER PRIMARY KEY, site_id INTEGER)');
    $database->db->exec('INSERT INTO wp_blogs VALUES (1, 1)');
}
$wpdb->primary->db->exec('INSERT INTO wp_blogs VALUES (2, 1)');
$wpdb->secondPrimary->seed(TokenManager::PENDING_OPTION, serialize([]));

$reports = [];
/** Captures public per-site statuses and optionally simulates a schema change between sites. */
$report = static function (array $row) use ($wpdb, $scenario, &$reports): void {
    $reports[] = $row;
    if ($scenario === 'network-schema-changed' && ($row['site_id'] ?? 0) === 1) {
        $wpdb->primary->schema = 'different_network';
    }
};
$failure = failure(static fn() => StorageMaintenance::run('preflight', true, $report));
return [
    'failure' => $failure,
    'reports' => $reports,
    'peakLocks' => [$wpdb->primary->peakLocks, $wpdb->secondPrimary->peakLocks, $wpdb->replica->peakLocks],
    'released' => !$wpdb->primary->activeLocks && !$wpdb->secondPrimary->activeLocks && !$wpdb->replica->activeLocks,
];
