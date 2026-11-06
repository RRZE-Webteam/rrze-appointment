<?php

/**
 * Controlled maintenance entrypoint, loaded without the normal plugin bootstrap.
 *
 * Usage: wp --skip-plugins --skip-themes eval-file /absolute/path/bin/storage-maintenance.php preflight network
 * Modes: preflight, migrate, verify. Omit "network" for the --url-selected site.
 * Migration requires an external write pause across the entire selected scope.
 */

use RRZE\Appointment\Storage\StorageMaintenance;

if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    exit;
}
if (class_exists(\RRZE\Appointment\Main::class, false)) {
    WP_CLI::error('Run appointment maintenance with --skip-plugins --skip-themes.');
}
if (count($args) < 1 || count($args) > 2 || (isset($args[1]) && $args[1] !== 'network')) {
    WP_CLI::error('Usage: storage-maintenance.php <preflight|migrate|verify> [network]');
}

/** Loads only Appointment classes requested by maintenance, without registering runtime hooks. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Appointment\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

try {
    /** Emits operational metadata only; never exception details, keys, payloads or action URLs. */
    $report = static function (array $result): void {
        WP_CLI::line(json_encode($result, JSON_THROW_ON_ERROR));
    };
    $success = StorageMaintenance::run($args[0], isset($args[1]), $report);
    if (!$success) {
        WP_CLI::error('Appointment maintenance failed. Keep writers paused and inspect the reported sites.');
    }
    WP_CLI::success('Appointment maintenance checks completed.');
} catch (Throwable) {
    WP_CLI::error('Appointment maintenance is unavailable. Check the mode, scope, key, database and migration state.');
}
